"""
Tests for iteration_12:
- RULE 1: DHCP sync must NEVER auto-create customers
- RULE 2: account_number must be exactly 10 digits, no letters
- REGRESSION: bulk-delete with invalid UUID must return 422 (not 500)
"""
import os
import re
import uuid
import pytest
import requests
import psycopg2
from datetime import datetime

BASE_URL = "https://332130c3-4e6c-41e4-8dbc-cd41ae05eb3d.preview.emergentagent.com"
API = f"{BASE_URL}/api/v1"

ADMIN_EMAIL = "admin@ispbilling.local"
ADMIN_PW = "password"

PG = dict(host="localhost", port=5432, dbname="isp_billing",
          user="isp_user", password="isp_secure_password")


@pytest.fixture(scope="session")
def token():
    r = requests.post(f"{API}/auth/login",
                      json={"email": ADMIN_EMAIL, "password": ADMIN_PW},
                      timeout=15)
    assert r.status_code == 200, f"login failed: {r.status_code} {r.text}"
    d = r.json()["data"]
    return d.get("access_token") or d.get("token")


@pytest.fixture(scope="session")
def headers(token):
    return {"Authorization": f"Bearer {token}",
            "Accept": "application/json",
            "Content-Type": "application/json"}


def customer_count():
    conn = psycopg2.connect(**PG)
    try:
        with conn.cursor() as c:
            c.execute("SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL")
            return c.fetchone()[0]
    finally:
        conn.close()


def _base_customer_payload(acct):
    return {
        "account_number": acct,
        "full_name": f"QA Acct {acct}",
        "address": "QA Street",
        "contact_number": "09171234567",
        "status": "active",
        "installation_date": "2026-01-01",
        "monthly_fee": 1500,
    }


# -------------- RULE 2: account_number validation --------------

class TestAccountNumberValidation:
    created_ids = []

    def test_valid_10_digits_returns_201(self, headers):
        acct = str(1000000000 + int(datetime.utcnow().timestamp()) % 8999999999)
        r = requests.post(f"{API}/customers", json=_base_customer_payload(acct),
                          headers=headers, timeout=15)
        assert r.status_code == 201, f"got {r.status_code}: {r.text}"
        data = r.json()["data"]
        assert data["account_number"] == acct
        TestAccountNumberValidation.created_ids.append(data["id"])

    @pytest.mark.parametrize("acct", ["ACC1234567", "123456789", "12345678901", "12345 6789A"])
    def test_invalid_account_number_returns_422(self, headers, acct):
        r = requests.post(f"{API}/customers", json=_base_customer_payload(acct),
                          headers=headers, timeout=15)
        assert r.status_code == 422, f"acct={acct} got {r.status_code}: {r.text}"

    def test_letters_error_message_contains_format(self, headers):
        r = requests.post(f"{API}/customers", json=_base_customer_payload("ACC1234567"),
                          headers=headers, timeout=15)
        assert r.status_code == 422
        body = r.text.lower()
        assert "format is invalid" in body or "format" in body, body

    def test_update_customer_letters_rejected(self, headers):
        # create valid customer first
        acct = str(2000000000 + int(datetime.utcnow().timestamp()) % 999999999)
        r = requests.post(f"{API}/customers", json=_base_customer_payload(acct),
                          headers=headers, timeout=15)
        assert r.status_code == 201
        cid = r.json()["data"]["id"]
        TestAccountNumberValidation.created_ids.append(cid)

        upd = requests.put(f"{API}/customers/{cid}",
                           json={"account_number": "ABC1234567"},
                           headers=headers, timeout=15)
        assert upd.status_code == 422, f"got {upd.status_code}: {upd.text}"

    @classmethod
    def teardown_class(cls):
        # cleanup created customers
        try:
            r = requests.post(f"{API}/auth/login",
                              json={"email": ADMIN_EMAIL, "password": ADMIN_PW}, timeout=10)
            tok = r.json()["data"].get("access_token") or r.json()["data"].get("token")
            h = {"Authorization": f"Bearer {tok}"}
            for cid in cls.created_ids:
                requests.delete(f"{API}/customers/{cid}", headers=h, timeout=10)
        except Exception as e:
            print(f"cleanup err: {e}")


# -------------- RULE 1: sync never auto-creates customers --------------

class TestSyncDoesNotAutoCreateCustomers:
    def _list_routers(self, headers):
        r = requests.get(f"{API}/routers", headers=headers, timeout=15)
        if r.status_code != 200:
            return []
        j = r.json()
        return j.get("data", j) if isinstance(j, dict) else j

    def test_sync_router_does_not_create_customers(self, headers):
        before = customer_count()
        routers = self._list_routers(headers)
        # If routers exist attempt sync (may fail because offline — that's fine as long as no customers created)
        attempted = 0
        for router in routers[:3] if isinstance(routers, list) else []:
            rid = router.get("id")
            if not rid:
                continue
            attempted += 1
            requests.post(f"{API}/routers/{rid}/sync-dhcp",
                          json={"auto_create_customers": True},  # even if user passes true, controller forces false
                          headers=headers, timeout=30)
        after = customer_count()
        assert after == before, f"customers created by sync: before={before} after={after} (attempts={attempted})"

    def test_sync_all_does_not_create_customers(self, headers):
        before = customer_count()
        # Attempt sync-all
        r = requests.post(f"{API}/unregistered-leases/sync-all",
                          json={"auto_create_customers": True},
                          headers=headers, timeout=60)
        # Accept any non-500 (routers may be offline)
        assert r.status_code != 500 or "auto" not in r.text.lower(), r.text
        after = customer_count()
        assert after == before, f"customers created by sync-all: before={before} after={after}"


# -------------- RULE 2 (quick-register generates 10-digit) --------------

class TestQuickRegisterAccountNumber:
    seeded_lease_id = None
    created_customer_id = None

    @classmethod
    def setup_class(cls):
        # Seed a DhcpLease row directly via SQL
        conn = psycopg2.connect(**PG)
        try:
            with conn.cursor() as c:
                # Introspect columns
                c.execute("""SELECT column_name, data_type, is_nullable, column_default
                             FROM information_schema.columns
                             WHERE table_name='dhcp_leases' ORDER BY ordinal_position""")
                cols = c.fetchall()
                print("dhcp_leases cols:", cols)
                # Find any router
                c.execute("SELECT id FROM routers LIMIT 1")
                row = c.fetchone()
                if not row:
                    # create a router
                    router_id = str(uuid.uuid4())
                    c.execute("""SELECT column_name, is_nullable, data_type FROM information_schema.columns
                                 WHERE table_name='routers' AND is_nullable='NO'""")
                    print("routers required cols:", c.fetchall())
                    # Instead insert a minimal router
                    c.execute("""INSERT INTO routers (id, name, host, port, username, password, api_port, use_ssl, status, created_at, updated_at)
                                 VALUES (%s,'qa-router','127.0.0.1',22,'x','x',8728,false,'offline',NOW(),NOW())""",
                              (router_id,))
                else:
                    router_id = row[0]
                cls.router_id = router_id
                lease_id = str(uuid.uuid4())
                mac = "AA:BB:CC:{:02X}:{:02X}:{:02X}".format(
                    uuid.uuid4().int & 0xFF, uuid.uuid4().int >> 8 & 0xFF, uuid.uuid4().int >> 16 & 0xFF)
                ip = f"10.99.{uuid.uuid4().int % 254}.{uuid.uuid4().int >> 8 & 0xFE + 1}"
                colnames = [c[0] for c in cols]
                # Build a minimal insert
                data = {
                    "id": lease_id,
                    "router_id": router_id,
                    "mac_address": mac,
                    "ip_address": ip,
                    "hostname": "QA-QUICKREG-HOST",
                    "comment": "QA quick-register test",
                    "status": "bound",
                    "server": "dhcp1",
                    "is_dynamic": False,
                    "is_matched": False,
                    "customer_id": None,
                    "last_seen_at": datetime.utcnow(),
                    "created_at": datetime.utcnow(),
                    "updated_at": datetime.utcnow(),
                }
                # only insert columns that exist
                keys = [k for k in data.keys() if k in colnames]
                vals = [data[k] for k in keys]
                # Also try to include router_id if required — set nullable
                placeholders = ",".join(["%s"] * len(keys))
                colstr = ",".join(f'"{k}"' for k in keys)
                sql = f'INSERT INTO dhcp_leases ({colstr}) VALUES ({placeholders})'
                c.execute(sql, vals)
                conn.commit()
                cls.seeded_lease_id = lease_id
                print(f"seeded lease {lease_id}")
        finally:
            conn.close()

    def test_quick_register_generates_10_digit_account(self, headers):
        assert TestQuickRegisterAccountNumber.seeded_lease_id
        r = requests.post(
            f"{API}/unregistered-leases/{TestQuickRegisterAccountNumber.seeded_lease_id}/quick-register",
            json={}, headers=headers, timeout=30)
        assert r.status_code in (200, 201), f"got {r.status_code}: {r.text}"
        j = r.json()
        # find account_number
        acct = None
        if isinstance(j, dict):
            for scope in [j.get("data"), j.get("customer"), j]:
                if isinstance(scope, dict) and "account_number" in scope:
                    acct = scope["account_number"]
                    if scope.get("id"):
                        TestQuickRegisterAccountNumber.created_customer_id = scope.get("id")
                    break
                if isinstance(scope, dict) and isinstance(scope.get("customer"), dict):
                    acct = scope["customer"].get("account_number")
                    TestQuickRegisterAccountNumber.created_customer_id = scope["customer"].get("id")
                    break
        assert acct, f"no account_number in response: {j}"
        assert re.fullmatch(r"\d{10}", acct), f"acct='{acct}' not 10 digits"

    @classmethod
    def teardown_class(cls):
        try:
            r = requests.post(f"{API}/auth/login",
                              json={"email": ADMIN_EMAIL, "password": ADMIN_PW}, timeout=10)
            tok = r.json()["data"].get("access_token") or r.json()["data"].get("token")
            h = {"Authorization": f"Bearer {tok}"}
            if cls.created_customer_id:
                requests.delete(f"{API}/customers/{cls.created_customer_id}", headers=h, timeout=10)
        except Exception as e:
            print(f"cleanup err: {e}")
        # Delete lease
        try:
            conn = psycopg2.connect(**PG)
            with conn.cursor() as c:
                c.execute("DELETE FROM dhcp_leases WHERE id=%s", (cls.seeded_lease_id,))
                conn.commit()
            conn.close()
        except Exception as e:
            print(f"lease cleanup err: {e}")


# -------------- REGRESSION: bulk-delete invalid UUID -> 422 --------------

class TestBulkDeleteInvalidUuid:
    def test_invalid_uuid_returns_422(self, headers):
        r = requests.post(f"{API}/customers/bulk-delete",
                          json={"customer_ids": ["not-a-uuid"]},
                          headers=headers, timeout=15)
        assert r.status_code == 422, f"got {r.status_code}: {r.text}"
