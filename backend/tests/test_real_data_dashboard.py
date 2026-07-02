"""
Iteration 8: Real-data dashboard, HSGQ OLT, MikroTik sync, demo-user removal.
Validates that all mock/hardcoded values have been replaced with real DB queries.
"""
import os
import pytest
import requests

BASE_URL = os.environ.get("REACT_APP_BACKEND_URL",
                          "https://332130c3-4e6c-41e4-8dbc-cd41ae05eb3d.preview.emergentagent.com").rstrip("/")
ADMIN = {"email": "admin@ispbilling.local", "password": "password"}
DEMO = {"email": "demo@ispbilling.local", "password": "password"}


@pytest.fixture(scope="module")
def token():
    r = requests.post(f"{BASE_URL}/api/v1/auth/login", json=ADMIN, timeout=15)
    assert r.status_code == 200, r.text
    return r.json()["data"]["access_token"]


@pytest.fixture(scope="module")
def auth(token):
    return {"Authorization": f"Bearer {token}"}


@pytest.fixture(scope="module")
def router_id(auth):
    r = requests.get(f"{BASE_URL}/api/v1/routers", headers=auth, timeout=15)
    assert r.status_code == 200
    data = r.json().get("data", [])
    return data[0]["id"] if data else None


# ---------------- Auth ----------------

class TestAuth:
    def test_admin_login_returns_token(self):
        r = requests.post(f"{BASE_URL}/api/v1/auth/login", json=ADMIN)
        assert r.status_code == 200
        body = r.json()
        assert body["status"] == "success"
        assert body["data"]["access_token"]
        assert body["data"]["user"]["email"] == ADMIN["email"]

    def test_demo_user_removed(self):
        r = requests.post(f"{BASE_URL}/api/v1/auth/login", json=DEMO)
        assert r.status_code in (401, 200)
        body = r.json()
        assert body.get("status") == "error", f"Demo user should not exist: {body}"
        assert "invalid" in body.get("message", "").lower()


# ---------------- Dashboard metrics ----------------

class TestDashboardMetrics:
    def test_metrics_shape_and_real_values(self, auth):
        r = requests.get(f"{BASE_URL}/api/v1/dashboard/metrics", headers=auth)
        assert r.status_code == 200
        body = r.json()
        assert body["status"] == "success"
        d = body["data"]

        required = [
            "total_subscribers", "active_subscribers", "suspended_subscribers",
            "expired_subscribers", "today_revenue", "monthly_revenue",
            "subscribers_change_pct", "revenue_change_pct",
            "pending_payments", "overdue_invoices", "open_tickets",
            "resolved_today", "router_status", "recent_signups",
        ]
        for k in required:
            assert k in d, f"missing key {k}"

        # Types
        assert isinstance(d["today_revenue"], (int, float))
        assert isinstance(d["monthly_revenue"], (int, float))
        assert isinstance(d["recent_signups"], list)

        # Router status sub-shape
        rs = d["router_status"]
        for k in ("online", "offline", "error", "total"):
            assert k in rs
            assert isinstance(rs[k], int)
        assert rs["total"] == rs["online"] + rs["offline"] + rs["error"] or rs["total"] >= rs["online"]

        # Change pct is nullable or number
        assert d["subscribers_change_pct"] is None or isinstance(d["subscribers_change_pct"], (int, float))
        assert d["revenue_change_pct"] is None or isinstance(d["revenue_change_pct"], (int, float))

    def test_metrics_values_match_db(self, auth):
        """Cross-check counts by hitting other endpoints."""
        m = requests.get(f"{BASE_URL}/api/v1/dashboard/metrics", headers=auth).json()["data"]
        customers = requests.get(f"{BASE_URL}/api/v1/customers", headers=auth).json()
        # customers endpoint may be paginated; check that total_subscribers is at least length of first page
        cust_list = customers.get("data") if isinstance(customers.get("data"), list) else customers.get("data", {}).get("data", [])
        assert m["total_subscribers"] >= len(cust_list)


class TestQuickStats:
    def test_quick_stats_shape_and_no_hardcoded_values(self, auth):
        r = requests.get(f"{BASE_URL}/api/v1/dashboard/quick-stats", headers=auth)
        assert r.status_code == 200
        body = r.json()
        assert body["status"] == "success"
        stats = body["data"]
        assert isinstance(stats, list) and len(stats) == 4

        for s in stats:
            for k in ("label", "value", "change", "trend", "icon"):
                assert k in s, f"quick-stat missing {k}: {s}"
            assert s["trend"] in ("up", "down", "stable")

        # Forbidden hardcoded strings
        as_text = str(stats)
        for bad in ("+12%", "+8%", "99.9%", "1.2K"):
            assert bad not in as_text, f"hardcoded value found: {bad}"

        # Router Uptime card must show 'X / Y online'
        uptime = next(s for s in stats if s["label"] == "Router Uptime")
        assert "online" in uptime["change"] and "/" in uptime["change"], uptime


# ---------------- HSGQ OLT ----------------

class TestHsgqOlt:
    def test_list_no_fake_ont_data(self, auth):
        r = requests.get(f"{BASE_URL}/api/v1/hsgq-olt", headers=auth)
        assert r.status_code == 200
        body = r.json()
        assert body["success"] is True
        assert isinstance(body["data"], list)

    def test_ont_endpoints_return_not_implemented_for_valid_id(self, auth, router_id):
        if not router_id:
            pytest.skip("No router to use as OLT id")
        UUID = router_id  # reuse existing router row as OLT id
        FAKE_ONT = "00000000-0000-0000-0000-000000000001"

        # getOnts -> 200 with empty data + notice
        r = requests.get(f"{BASE_URL}/api/v1/hsgq-olt/{UUID}/onts", headers=auth)
        assert r.status_code == 200
        b = r.json()
        assert b["data"] == []
        assert "SNMP" in b.get("notice", "")
        text = str(b).lower()
        for name in ("john doe", "jane smith", "aa:bb:cc:dd:ee"):
            assert name not in text, f"fake ONT leaked: {name}"

        # discover -> 501
        r = requests.post(f"{BASE_URL}/api/v1/hsgq-olt/{UUID}/discover", headers=auth)
        assert r.status_code == 501
        assert r.json().get("code") == "NOT_IMPLEMENTED"

        # authorize -> 501 with valid payload
        r = requests.post(f"{BASE_URL}/api/v1/hsgq-olt/{UUID}/onts/{FAKE_ONT}/authorize",
                          headers=auth, json={"line_profile": "lp1", "service_profile": "sp1"})
        assert r.status_code == 501
        assert r.json().get("code") == "NOT_IMPLEMENTED"

        # reboot -> 501
        r = requests.post(f"{BASE_URL}/api/v1/hsgq-olt/{UUID}/onts/{FAKE_ONT}/reboot", headers=auth)
        assert r.status_code == 501

        # statistics -> 501
        r = requests.get(f"{BASE_URL}/api/v1/hsgq-olt/{UUID}/onts/{FAKE_ONT}/statistics", headers=auth)
        assert r.status_code == 501

    def test_ont_endpoints_404_for_nonexistent_uuid(self, auth):
        UUID = "00000000-0000-0000-0000-000000000000"
        r = requests.get(f"{BASE_URL}/api/v1/hsgq-olt/{UUID}/onts", headers=auth)
        assert r.status_code == 404


# ---------------- MikroTik router sync ----------------

class TestRouterSync:
    def test_sync_returns_real_result_not_placeholder(self, auth, router_id):
        if not router_id:
            pytest.skip("No router configured")
        r = requests.post(f"{BASE_URL}/api/v1/routers/{router_id}/sync", headers=auth, timeout=60)
        assert r.status_code in (200, 400, 502, 503), r.text
        body = r.json()
        text = str(body).lower()
        # Must not contain placeholder text
        assert "phase 5" not in text
        assert "will be implemented" not in text
        # Must have synced_items structure
        assert "synced_items" in body
        assert set(body["synced_items"].keys()) >= {"dhcp_leases", "queues", "system"}


# ---------------- Regressions ----------------

class TestRegressions:
    @pytest.mark.parametrize("ep", ["customers", "invoices", "service-plans", "tickets", "payments"])
    def test_core_endpoints_200(self, auth, ep):
        r = requests.get(f"{BASE_URL}/api/v1/{ep}", headers=auth)
        assert r.status_code == 200, f"{ep} -> {r.status_code} {r.text[:200]}"
