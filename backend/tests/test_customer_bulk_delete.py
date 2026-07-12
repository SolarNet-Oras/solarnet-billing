"""Bulk-delete customers API tests."""
import os
import uuid
import pytest
import requests

BASE_URL = os.environ.get(
    "REACT_APP_BACKEND_URL",
    "https://332130c3-4e6c-41e4-8dbc-cd41ae05eb3d.preview.emergentagent.com",
).rstrip("/")
API = f"{BASE_URL}/api/v1"
ADMIN = {"email": "admin@ispbilling.local", "password": "password"}
LOWPRIV = {"email": "lowpriv@test.com", "password": "password123"}


@pytest.fixture(scope="module")
def admin_headers():
    r = requests.post(f"{API}/auth/login", json=ADMIN, timeout=15)
    assert r.status_code == 200, r.text[:300]
    tok = r.json().get("data", {}).get("access_token") or r.json().get("access_token")
    return {"Authorization": f"Bearer {tok}", "Accept": "application/json"}


@pytest.fixture(scope="module")
def lowpriv_headers():
    r = requests.post(f"{API}/auth/login", json=LOWPRIV, timeout=15)
    if r.status_code != 200:
        pytest.skip(f"lowpriv login unavailable: {r.status_code}")
    tok = r.json().get("data", {}).get("access_token") or r.json().get("access_token")
    return {"Authorization": f"Bearer {tok}", "Accept": "application/json"}


def _create_qa_customer(admin_headers, idx):
    """Create a throwaway customer with 'QA-' account_number prefix."""
    suffix = uuid.uuid4().hex[:8]
    payload = {
        "account_number": f"QA-{suffix}-{idx}",
        "full_name": f"QA Test {idx}",
        "address": "QA Address 123",
        "contact_number": f"0917{idx:07d}",
        "email": f"qa_{suffix}_{idx}@test.local",
        "installation_date": "2026-01-01",
        "monthly_fee": 500,
        "status": "active",
        "send_welcome_email": False,
    }
    r = requests.post(f"{API}/customers", headers=admin_headers, json=payload, timeout=15)
    assert r.status_code in (200, 201), f"create failed: {r.status_code} {r.text[:400]}"
    body = r.json()
    obj = body.get("customer") or body.get("data") or body
    if isinstance(obj, dict) and "id" not in obj and "data" in obj:
        obj = obj["data"]
    return obj["id"]


class TestBulkDeleteCustomers:
    def test_bulk_delete_three_customers_returns_deleted_3_and_gets_404(self, admin_headers):
        ids = [_create_qa_customer(admin_headers, i) for i in range(3)]
        r = requests.post(
            f"{API}/customers/bulk-delete",
            headers=admin_headers,
            json={"customer_ids": ids},
            timeout=20,
        )
        assert r.status_code == 200, f"expected 200, got {r.status_code}: {r.text[:400]}"
        body = r.json()
        # Accept status:success / deleted:3
        assert body.get("status") == "success" or body.get("success") is True, body
        deleted = body.get("deleted") or body.get("data", {}).get("deleted")
        assert deleted == 3, f"expected deleted=3, got {body}"
        # Confirm soft-delete: each GET returns 404
        for cid in ids:
            g = requests.get(f"{API}/customers/{cid}", headers=admin_headers, timeout=15)
            assert g.status_code == 404, f"customer {cid} still fetchable: {g.status_code}"

    def test_bulk_delete_empty_returns_422(self, admin_headers):
        r = requests.post(
            f"{API}/customers/bulk-delete",
            headers=admin_headers,
            json={"customer_ids": []},
            timeout=15,
        )
        assert r.status_code == 422, f"expected 422, got {r.status_code}: {r.text[:300]}"

    def test_bulk_delete_invalid_uuid_returns_422(self, admin_headers):
        r = requests.post(
            f"{API}/customers/bulk-delete",
            headers=admin_headers,
            json={"customer_ids": ["not-a-valid-uuid"]},
            timeout=15,
        )
        # Expected 422 per spec; backend currently 500 due to `exists:customers,id`
        # rule casting failure in Postgres for non-uuid strings.
        assert r.status_code == 422, f"expected 422, got {r.status_code}: {r.text[:300]}"

    def test_bulk_delete_unauthenticated_returns_401(self):
        r = requests.post(
            f"{API}/customers/bulk-delete",
            json={"customer_ids": [str(uuid.uuid4())]},
            timeout=15,
        )
        assert r.status_code == 401, f"expected 401, got {r.status_code}"

    def test_bulk_delete_without_permission_returns_403(self, lowpriv_headers):
        r = requests.post(
            f"{API}/customers/bulk-delete",
            headers=lowpriv_headers,
            json={"customer_ids": [str(uuid.uuid4())]},
            timeout=15,
        )
        assert r.status_code == 403, f"expected 403, got {r.status_code}: {r.text[:300]}"
