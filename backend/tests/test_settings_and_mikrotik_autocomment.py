"""
Tests for:
 (A) Settings API — GET/PUT /api/v1/settings
 (B) Mikrotik auto-comment + auto-rate-limit on quick-register from unregistered leases

Env base URL: uses VITE_API_URL (public) if set, otherwise localhost:8001.
"""
import os
import uuid
import random
import psycopg2
import pytest
import requests

BASE_URL = os.environ.get("VITE_API_URL") or "http://localhost:8001"
BASE_URL = BASE_URL.rstrip("/")
API = f"{BASE_URL}/api/v1"

PG = dict(host="localhost", port=5432, dbname="isp_billing",
          user="isp_user", password="isp_secure_password")


# ---------- fixtures ----------
@pytest.fixture(scope="session")
def token():
    r = requests.post(f"{API}/auth/login",
                      json={"email": "admin@ispbilling.local", "password": "password"},
                      timeout=15)
    assert r.status_code == 200, f"login failed: {r.status_code} {r.text}"
    return r.json()["data"]["access_token"]


@pytest.fixture(scope="session")
def auth(token):
    return {"Authorization": f"Bearer {token}", "Accept": "application/json"}


@pytest.fixture()
def pg():
    conn = psycopg2.connect(**PG)
    conn.autocommit = True
    yield conn
    conn.close()


# ============================================================
# (A) SETTINGS
# ============================================================
class TestSettingsApi:
    def test_get_settings_shape(self, auth):
        r = requests.get(f"{API}/settings", headers=auth, timeout=15)
        assert r.status_code == 200, r.text
        body = r.json()
        assert body.get("success") is True
        data = body.get("data")
        assert isinstance(data, list)
        assert len(data) >= 15, f"expected >=15 items, got {len(data)}"
        keys = {"key", "value", "cast", "group", "label", "is_readonly"}
        for item in data:
            assert keys.issubset(item.keys()), f"missing keys in {item}"
        groups = {i["group"] for i in data}
        assert {"company", "billing", "ai"}.issubset(groups)
        ai_key_configured = next((i for i in data if i["key"] == "ai.key_configured"), None)
        assert ai_key_configured is not None
        assert ai_key_configured["is_readonly"] is True

    def test_get_without_bearer_401(self):
        r = requests.get(f"{API}/settings",
                         headers={"Accept": "application/json"}, timeout=15)
        assert r.status_code == 401, f"expected 401 got {r.status_code}: {r.text[:200]}"

    def test_put_valid_updates_persist_and_reset(self, auth):
        payload = {"settings": [
            {"key": "company.name", "value": "ACME Test"},
            {"key": "billing.vat_percent", "value": 13.5},
        ]}
        r = requests.put(f"{API}/settings", json=payload, headers=auth, timeout=15)
        assert r.status_code == 200, r.text
        body = r.json()
        assert body.get("status") == "success"
        assert set(body.get("keys", [])) == {"company.name", "billing.vat_percent"}

        # Verify persistence
        r2 = requests.get(f"{API}/settings", headers=auth, timeout=15).json()["data"]
        m = {i["key"]: i["value"] for i in r2}
        assert m["company.name"] == "ACME Test"
        assert float(m["billing.vat_percent"]) == 13.5

        # Reset
        rr = requests.put(f"{API}/settings", json={"settings": [
            {"key": "company.name", "value": "Solarnet Internet"},
            {"key": "billing.vat_percent", "value": 12.0},
        ]}, headers=auth, timeout=15)
        assert rr.status_code == 200

    def test_put_unknown_key_422(self, auth):
        r = requests.put(f"{API}/settings",
                         json={"settings": [{"key": "not.a.real.key", "value": "x"}]},
                         headers=auth, timeout=15)
        assert r.status_code == 422, r.text
        assert "Unknown setting key" in r.text


# ============================================================
# (B) Mikrotik auto-comment
# ============================================================
def _mkmac():
    return "AA:BB:CC:%02X:%02X:%02X" % (
        random.randint(0, 255), random.randint(0, 255), random.randint(0, 255))


def _make_router_and_plan(pg, plan_dl=10, plan_ul=5, plan_price=999):
    cur = pg.cursor()
    router_id = str(uuid.uuid4())
    plan_id = str(uuid.uuid4())
    cur.execute("""
        INSERT INTO routers (id, name, host, username, password, port,
                             is_active, connection_status, created_at, updated_at)
        VALUES (%s, %s, %s, 'admin', 'x', 8728, TRUE, 'offline', now(), now())
    """, (router_id, f"TEST_R_{router_id[:8]}", f"192.168.99.{random.randint(2,254)}"))
    cur.execute("""
        INSERT INTO service_plans (id, name, download_speed, upload_speed, price,
                                   is_active, created_at, updated_at)
        VALUES (%s, %s, %s, %s, %s, TRUE, now(), now())
    """, (plan_id, f"TEST_PLAN_{plan_id[:6]}", plan_dl, plan_ul, plan_price))
    return router_id, plan_id


def _make_lease(pg, router_id, *, rate_limit=None, comment=None,
                is_dynamic=True, is_matched=False):
    cur = pg.cursor()
    lease_id = str(uuid.uuid4())
    mac = _mkmac()
    ip = f"10.0.99.{random.randint(2, 254)}"
    cur.execute("""
        INSERT INTO dhcp_leases (id, router_id, mac_address, ip_address, hostname,
                                 comment, rate_limit, is_dynamic, is_matched,
                                 status, server, last_seen_at, created_at, updated_at)
        VALUES (%s,%s,%s,%s, 'test-host', %s, %s, %s, %s, 'bound', 'default', now(), now(), now())
    """, (lease_id, router_id, mac, ip, comment, rate_limit,
          is_dynamic, is_matched))
    return lease_id, mac, ip


def _cleanup(pg, router_id, plan_id, lease_ids):
    cur = pg.cursor()
    for lid in lease_ids:
        cur.execute("DELETE FROM dhcp_leases WHERE id=%s", (lid,))
    # Delete customers created from these leases (by router)
    cur.execute("DELETE FROM customers WHERE router_id=%s", (router_id,))
    cur.execute("DELETE FROM service_plans WHERE id=%s", (plan_id,))
    cur.execute("DELETE FROM routers WHERE id=%s", (router_id,))


class TestMikrotikAutoComment:
    def test_offline_router_skips_but_updates_local_row(self, auth, pg):
        router_id, plan_id = _make_router_and_plan(pg, plan_dl=10, plan_ul=5)
        lease_id, mac, ip = _make_lease(pg, router_id,
                                        rate_limit=None, comment=None,
                                        is_dynamic=True, is_matched=False)
        try:
            r = requests.post(
                f"{API}/unregistered-leases/{lease_id}/quick-register",
                json={"service_plan_id": plan_id, "full_name": "TEST Auto Comment Client"},
                headers=auth, timeout=30)
            assert r.status_code == 201, r.text
            body = r.json()
            mt = body.get("mikrotik_sync")
            assert mt is not None, "expected mikrotik_sync in response"
            assert mt.get("success") is False
            assert mt.get("skipped") is True
            assert "not online" in mt.get("message", "").lower()

            # Verify local DhcpLease row was updated
            cur = pg.cursor()
            cur.execute("""SELECT comment, rate_limit, is_dynamic, is_matched
                           FROM dhcp_leases WHERE id=%s""", (lease_id,))
            comment, rate_limit, is_dynamic, is_matched = cur.fetchone()
            assert comment == "TEST Auto Comment Client"
            assert rate_limit == "10M/5M"
            assert is_dynamic is False
            assert is_matched is True
        finally:
            _cleanup(pg, router_id, plan_id, [lease_id])

    def test_preexisting_rate_limit_is_preserved_when_no_plan(self, auth, pg):
        router_id, plan_id = _make_router_and_plan(pg)
        lease_id, mac, ip = _make_lease(
            pg, router_id, rate_limit="20M/10M", comment="Pre Existing Name",
            is_dynamic=False, is_matched=False)
        try:
            # No service_plan_id supplied; rate_limit already on lease
            r = requests.post(
                f"{API}/unregistered-leases/{lease_id}/quick-register",
                json={"full_name": "TEST Preserve Rate"},
                headers=auth, timeout=30)
            assert r.status_code == 201, r.text

            cur = pg.cursor()
            cur.execute("""SELECT comment, rate_limit, is_dynamic, is_matched
                           FROM dhcp_leases WHERE id=%s""", (lease_id,))
            comment, rate_limit, is_dynamic, is_matched = cur.fetchone()
            assert rate_limit == "20M/10M"
            assert comment == "TEST Preserve Rate"
            assert is_matched is True
        finally:
            _cleanup(pg, router_id, plan_id, [lease_id])

    def test_regression_static_commented_lease_still_registers(self, auth, pg):
        router_id, plan_id = _make_router_and_plan(pg)
        lease_id, mac, ip = _make_lease(
            pg, router_id, rate_limit="10M/5M", comment="Existing Name",
            is_dynamic=False, is_matched=False)
        try:
            r = requests.post(
                f"{API}/unregistered-leases/{lease_id}/quick-register",
                json={"service_plan_id": plan_id},
                headers=auth, timeout=30)
            assert r.status_code == 201, r.text
            body = r.json()
            assert body.get("success") is True
            assert body["data"]["full_name"] == "Existing Name"
            # mikrotik_sync must be present with skipped=true (offline)
            assert body.get("mikrotik_sync", {}).get("skipped") is True
        finally:
            _cleanup(pg, router_id, plan_id, [lease_id])
