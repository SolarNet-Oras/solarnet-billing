"""Regression tests for the 502 login bug fix (mbstring extension)."""
import os
import pytest
import requests

BASE = os.environ.get("VITE_API_URL", "https://332130c3-4e6c-41e4-8dbc-cd41ae05eb3d.preview.emergentagent.com").rstrip("/")
API = f"{BASE}/api"
V1 = f"{API}/v1"

ADMIN = {"email": "admin@ispbilling.local", "password": "password"}


@pytest.fixture(scope="module")
def token():
    r = requests.post(f"{V1}/auth/login", json=ADMIN, timeout=30)
    assert r.status_code == 200, f"Login failed: {r.status_code} {r.text[:400]}"
    data = r.json()
    assert data.get("status") == "success"
    assert "access_token" in data.get("data", {})
    return data["data"]["access_token"]


def test_health():
    r = requests.get(f"{API}/health", timeout=15)
    assert r.status_code == 200, r.text[:300]
    assert r.json().get("status") == "ok"


def test_login_success():
    r = requests.post(f"{V1}/auth/login", json=ADMIN, timeout=30)
    assert r.status_code == 200, f"{r.status_code} {r.text[:400]}"
    j = r.json()
    assert j["status"] == "success"
    assert isinstance(j["data"]["access_token"], str) and len(j["data"]["access_token"]) > 10


def test_login_wrong_credentials():
    r = requests.post(f"{V1}/auth/login", json={"email": "admin@ispbilling.local", "password": "wrongpassword123"}, timeout=30)
    assert r.status_code == 401, f"Expected 401, got {r.status_code} {r.text[:300]}"


def test_customers_requires_auth():
    r = requests.get(f"{V1}/customers", timeout=15)
    assert r.status_code == 401


def test_customers_with_auth(token):
    r = requests.get(f"{V1}/customers", headers={"Authorization": f"Bearer {token}"}, timeout=30)
    assert r.status_code == 200, r.text[:300]


def test_customers_search_uses_mbstring(token):
    r = requests.get(f"{V1}/customers?search=test", headers={"Authorization": f"Bearer {token}"}, timeout=30)
    assert r.status_code == 200, f"mbstring regression: {r.status_code} {r.text[:400]}"


def test_service_plans(token):
    r = requests.get(f"{V1}/service-plans", headers={"Authorization": f"Bearer {token}"}, timeout=30)
    assert r.status_code == 200, r.text[:300]
