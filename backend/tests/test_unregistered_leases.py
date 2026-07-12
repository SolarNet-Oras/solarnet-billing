"""
Backend tests for Unregistered DHCP Leases feature + Add Client regression.

Covers:
- Auth guard on the 4 new endpoints
- POST /api/v1/unregistered-leases/sync-all (tolerant of zero routers)
- GET  /api/v1/unregistered-leases/static-commented
- GET  /api/v1/unregistered-leases/dynamic
- POST /api/v1/unregistered-leases/{id}/quick-register (success, 404, 422)
- Regression: POST /api/v1/customers (Add Client) end-to-end w/ portal creds
- Regression: POST /api/v1/routers/{id}/sync-dhcp accepts request
- DB schema: dhcp_leases has comment, rate_limit, is_dynamic
"""
import os
import time
import uuid
import subprocess
import json
import pytest
import requests

BASE_URL = "http://localhost:8001"
ADMIN_EMAIL = "admin@ispbilling.local"
ADMIN_PASSWORD = "password"


# ---------------- fixtures ----------------

@pytest.fixture(scope="session")
def token():
    r = requests.post(f"{BASE_URL}/api/v1/auth/login",
                      json={"email": ADMIN_EMAIL, "password": ADMIN_PASSWORD},
                      timeout=15)
    assert r.status_code == 200, r.text
    body = r.json()
    tok = body.get("data", {}).get("access_token") or body.get("access_token")
    assert tok, f"No access_token in {body}"
    return tok


@pytest.fixture(scope="session")
def auth_headers(token):
    return {"Authorization": f"Bearer {token}", "Accept": "application/json"}


def tinker(php_code: str) -> str:
    """Run a snippet in artisan tinker and return stdout."""
    proc = subprocess.run(
        ["php", "artisan", "tinker", "--execute", php_code],
        cwd="/app/backend", capture_output=True, text=True, timeout=30,
    )
    if proc.returncode != 0:
        raise RuntimeError(f"tinker failed: {proc.stderr}\n{proc.stdout}")
    return proc.stdout.strip()


@pytest.fixture(scope="session")
def router_id(auth_headers):
    """Create a router via API for tests that need one."""
    payload = {
        "name": f"TEST_Router_{uuid.uuid4().hex[:6]}",
        "host": "10.99.99.1",
        "port": 8728,
        "username": "admin",
        "password": "test",
        "is_active": True,
    }
    r = requests.post(f"{BASE_URL}/api/v1/routers", json=payload,
                      headers=auth_headers, timeout=15)
    assert r.status_code == 201, r.text
    return r.json()["data"]["id"]


@pytest.fixture(scope="session")
def plan_10_5(auth_headers):
    """Create a service plan with 10/5 Mbps for matching."""
    payload = {
        "name": f"TEST_Plan_10_5_{uuid.uuid4().hex[:4]}",
        "price": 999,
        "download_speed": 10,
        "upload_speed": 5,
        "priority": 8,
        "is_active": True,
    }
    r = requests.post(f"{BASE_URL}/api/v1/service-plans", json=payload,
                      headers=auth_headers, timeout=15)
    assert r.status_code == 201, r.text
    return r.json()["data"]


# ---------------- auth guard ----------------

@pytest.mark.parametrize("method,path", [
    ("post", "/api/v1/unregistered-leases/sync-all"),
    ("get",  "/api/v1/unregistered-leases/static-commented"),
    ("get",  "/api/v1/unregistered-leases/dynamic"),
    ("post", "/api/v1/unregistered-leases/some-id/quick-register"),
])
def test_endpoints_require_auth(method, path):
    r = requests.request(method, f"{BASE_URL}{path}",
                         headers={"Accept": "application/json"}, timeout=10)
    assert r.status_code in (401, 403), f"expected 401/403 got {r.status_code}: {r.text[:200]}"


# ---------------- schema ----------------

def test_dhcp_leases_schema_has_new_columns():
    out = tinker("echo json_encode(\\Schema::getColumnListing('dhcp_leases'));")
    cols = json.loads(out)
    for c in ("comment", "rate_limit", "is_dynamic"):
        assert c in cols, f"missing column {c} in {cols}"


# ---------------- sync-all ----------------

def test_sync_all_tolerant_of_zero_or_more_routers(auth_headers):
    r = requests.post(f"{BASE_URL}/api/v1/unregistered-leases/sync-all",
                      headers=auth_headers, timeout=30)
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["success"] is True
    data = body["data"]
    for k in ("total_routers", "success", "failed", "routers"):
        assert k in data, f"missing {k} in {data}"


# ---------------- static-commented / dynamic ----------------

def test_static_commented_returns_success_shape(auth_headers):
    r = requests.get(f"{BASE_URL}/api/v1/unregistered-leases/static-commented",
                     headers=auth_headers, timeout=15)
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["success"] is True
    assert isinstance(body["data"], list)


def test_dynamic_returns_success_shape(auth_headers):
    r = requests.get(f"{BASE_URL}/api/v1/unregistered-leases/dynamic",
                     headers=auth_headers, timeout=15)
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["success"] is True
    assert isinstance(body["data"], list)


# ---------------- lease seeding helper ----------------

def _seed_lease(router_id: str, *, is_dynamic: bool, comment, rate_limit,
                is_matched: bool = False) -> str:
    """Insert a DhcpLease row via tinker; returns lease id."""
    comment_php = "null" if comment is None else f"'{comment}'"
    rate_php = "null" if rate_limit is None else f"'{rate_limit}'"
    mac = "AA:BB:CC:" + ":".join(uuid.uuid4().hex[i:i+2] for i in (0, 2, 4)).upper()
    ip = f"10.20.30.{int(uuid.uuid4().int % 250) + 2}"
    code = (
        f"$l=\\App\\Models\\DhcpLease::create(["
        f"'router_id'=>'{router_id}',"
        f"'mac_address'=>'{mac}',"
        f"'ip_address'=>'{ip}',"
        f"'hostname'=>'testhost',"
        f"'comment'=>{comment_php},"
        f"'rate_limit'=>{rate_php},"
        f"'is_dynamic'=>" + ("true" if is_dynamic else "false") + ","
        f"'server'=>'dhcp1',"
        f"'status'=>'bound',"
        f"'is_matched'=>" + ("true" if is_matched else "false") + ","
        f"'last_seen_at'=>now()"
        f"]); echo $l->id;"
    )
    return tinker(code).splitlines()[-1].strip()


def test_static_commented_includes_seeded_lease_and_suggests_plan(auth_headers, router_id, plan_10_5):
    lease_id = _seed_lease(router_id, is_dynamic=False,
                           comment="Juan Dela Cruz", rate_limit="10M/5M")
    r = requests.get(f"{BASE_URL}/api/v1/unregistered-leases/static-commented",
                     headers=auth_headers, timeout=15)
    assert r.status_code == 200, r.text
    leases = r.json()["data"]
    found = next((l for l in leases if l["id"] == lease_id), None)
    assert found, "seeded static+commented lease not returned"
    assert found["comment"] == "Juan Dela Cruz"
    assert found["rate_limit"] == "10M/5M"
    assert found["is_dynamic"] in (False, 0)
    assert found.get("suggested_plan"), "suggested_plan missing"
    assert int(found["suggested_plan"]["download_speed"]) == 10
    assert int(found["suggested_plan"]["upload_speed"]) == 5


def test_dynamic_endpoint_includes_dynamic_and_uncommented(auth_headers, router_id):
    dyn_id = _seed_lease(router_id, is_dynamic=True, comment=None, rate_limit=None)
    uncommented_static_id = _seed_lease(router_id, is_dynamic=False,
                                        comment=None, rate_limit=None)
    r = requests.get(f"{BASE_URL}/api/v1/unregistered-leases/dynamic",
                     headers=auth_headers, timeout=15)
    assert r.status_code == 200, r.text
    ids = [l["id"] for l in r.json()["data"]]
    assert dyn_id in ids
    assert uncommented_static_id in ids


# ---------------- quick-register ----------------

def test_quick_register_success_with_plan_match(auth_headers, router_id, plan_10_5):
    lease_id = _seed_lease(router_id, is_dynamic=False,
                           comment="Maria Santos", rate_limit="10M/5M")
    r = requests.post(
        f"{BASE_URL}/api/v1/unregistered-leases/{lease_id}/quick-register",
        headers=auth_headers, json={"email": f"maria_{uuid.uuid4().hex[:5]}@test.com"},
        timeout=60,
    )
    assert r.status_code == 201, r.text
    body = r.json()
    assert body["success"] is True
    cust = body["data"]
    assert cust["full_name"] == "Maria Santos"
    assert cust["service_plan_id"] is not None
    assert float(cust["monthly_fee"]) > 0
    # portal_credentials populated because email provided
    assert body["portal_credentials"] is not None
    assert body["portal_credentials"]["password"]

    # verify lease flipped
    row = tinker(f"echo json_encode(\\App\\Models\\DhcpLease::find('{lease_id}'));")
    lease = json.loads(row)
    assert lease["is_matched"] in (True, 1)
    assert lease["customer_id"] == cust["id"]


def test_quick_register_already_matched_returns_422(auth_headers, router_id):
    lease_id = _seed_lease(router_id, is_dynamic=False, comment="X", rate_limit="10M/5M",
                           is_matched=True)
    r = requests.post(
        f"{BASE_URL}/api/v1/unregistered-leases/{lease_id}/quick-register",
        headers=auth_headers, json={}, timeout=15,
    )
    assert r.status_code == 422, r.text
    assert r.json()["success"] is False


def test_quick_register_missing_lease_returns_404(auth_headers):
    fake = str(uuid.uuid4())
    r = requests.post(
        f"{BASE_URL}/api/v1/unregistered-leases/{fake}/quick-register",
        headers=auth_headers, json={}, timeout=15,
    )
    assert r.status_code == 404, r.text


# ---------------- Add Client regression ----------------

def test_add_client_end_to_end(auth_headers, router_id, plan_10_5):
    acct = f"TEST-ACC-{uuid.uuid4().hex[:6].upper()}"
    payload = {
        "account_number": acct,
        "full_name": "TEST_John Doe",
        "address": "123 Test St",
        "contact_number": "09171234567",
        "email": f"john_{uuid.uuid4().hex[:5]}@test.com",
        "installation_date": "2026-01-15",
        "monthly_fee": 999,
        "status": "active",
        "service_plan_id": plan_10_5["id"],
        "router_id": router_id,
    }
    r = requests.post(f"{BASE_URL}/api/v1/customers", json=payload,
                      headers=auth_headers, timeout=20)
    assert r.status_code == 201, r.text
    body = r.json()
    assert body["status"] == "success"
    assert body["data"]["account_number"] == acct
    assert body["data"]["service_plan_id"] == plan_10_5["id"]
    assert body["data"]["router_id"] == router_id
    # portal credentials because email provided
    assert body["portal_credentials"] is not None
    assert body["portal_credentials"]["password"]

    # GET to verify persistence
    cid = body["data"]["id"]
    g = requests.get(f"{BASE_URL}/api/v1/customers/{cid}",
                     headers=auth_headers, timeout=10)
    assert g.status_code == 200
    assert g.json()["data"]["account_number"] == acct


def test_add_client_without_service_plan_or_router(auth_headers):
    acct = f"TEST-ACC-{uuid.uuid4().hex[:6].upper()}"
    payload = {
        "account_number": acct,
        "full_name": "TEST_No Plan",
        "address": "N/A",
        "contact_number": "09170000000",
        "installation_date": "2026-01-15",
        "monthly_fee": 500,
        "status": "pending",
    }
    r = requests.post(f"{BASE_URL}/api/v1/customers", json=payload,
                      headers=auth_headers, timeout=15)
    assert r.status_code == 201, r.text


# ---------------- Regression: sync-dhcp still accepts request ----------------

def test_router_sync_dhcp_accepts_request(auth_headers, router_id):
    r = requests.post(f"{BASE_URL}/api/v1/routers/{router_id}/sync-dhcp",
                      headers=auth_headers, timeout=30)
    # Router is a fake host; endpoint should NOT 404/401/422 but may 200 with failure,
    # or 5xx due to connection. Accept 200 or 500 as "route reachable".
    assert r.status_code in (200, 500, 503), r.text[:300]
