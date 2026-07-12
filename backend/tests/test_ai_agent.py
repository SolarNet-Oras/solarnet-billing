"""Backend tests for Floating AI Agent - /api/v1/ai/*"""
import os
import re
import time
import pytest
import requests
import psycopg2

BASE = "https://332130c3-4e6c-41e4-8dbc-cd41ae05eb3d.preview.emergentagent.com"
ADMIN_EMAIL = "admin@ispbilling.local"
ADMIN_PW = "password"

UUID_RE = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$", re.I)


@pytest.fixture(scope="session")
def token():
    r = requests.post(f"{BASE}/api/v1/auth/login",
                      json={"email": ADMIN_EMAIL, "password": ADMIN_PW}, timeout=30)
    assert r.status_code == 200, r.text
    return r.json()["data"]["access_token"]


@pytest.fixture(scope="session")
def auth_headers(token):
    return {"Authorization": f"Bearer {token}", "Content-Type": "application/json", "Accept": "application/json"}


@pytest.fixture(scope="session")
def first_chat(auth_headers):
    """Do one chat that triggers get_network_status; reuse across tests."""
    r = requests.post(f"{BASE}/api/v1/ai/chat", headers=auth_headers,
                      json={"message": "Give me a network status summary"}, timeout=60)
    assert r.status_code == 200, r.text
    return r.json()


# ---------------- Chat: primary tool call ----------------
class TestChatNetworkStatus:
    def test_network_status(self, first_chat):
        assert first_chat["success"] is True
        data = first_chat["data"]
        assert isinstance(data.get("assistant"), str) and len(data["assistant"]) > 0
        assert UUID_RE.match(data["conversation_id"])
        assert data["model"] == "gpt-5.4-mini"
        assert isinstance(data.get("tool_calls"), list) and len(data["tool_calls"]) >= 1
        names = [tc["name"] for tc in data["tool_calls"]]
        assert "get_network_status" in names
        tc = next(t for t in data["tool_calls"] if t["name"] == "get_network_status")
        assert "result" in tc
        result = tc["result"]
        # result.customers.total should be a number
        assert "customers" in result
        assert isinstance(result["customers"].get("total"), (int, float))


# ---------------- Multi-turn ----------------
class TestMultiTurn:
    def test_followup(self, first_chat, auth_headers):
        conv_id = first_chat["data"]["conversation_id"]
        r = requests.post(f"{BASE}/api/v1/ai/chat", headers=auth_headers,
                          json={"message": "From the earlier summary, how many customers are there?",
                                "conversation_id": conv_id}, timeout=60)
        assert r.status_code == 200, r.text
        d = r.json()["data"]
        assert d["conversation_id"] == conv_id
        assert isinstance(d["assistant"], str) and len(d["assistant"]) > 0


# ---------------- Validation & Auth ----------------
class TestValidationAndAuth:
    def test_empty_message_422(self, auth_headers):
        r = requests.post(f"{BASE}/api/v1/ai/chat", headers=auth_headers,
                          json={"message": ""}, timeout=30)
        assert r.status_code == 422

    def test_no_token_401(self):
        r = requests.post(f"{BASE}/api/v1/ai/chat",
                          json={"message": "hi"},
                          headers={"Content-Type": "application/json", "Accept": "application/json"},
                          timeout=30)
        assert r.status_code == 401


# ---------------- Other tools (batched: 1 call each) ----------------
class TestOtherTools:
    def test_list_customers(self, auth_headers):
        r = requests.post(f"{BASE}/api/v1/ai/chat", headers=auth_headers,
                          json={"message": "Show me active customers"}, timeout=90)
        assert r.status_code == 200, r.text
        d = r.json()["data"]
        names = [tc["name"] for tc in d.get("tool_calls", [])]
        assert "list_customers" in names, f"tool_calls: {d.get('tool_calls')}"
        tc = next(t for t in d["tool_calls"] if t["name"] == "list_customers")
        assert "result" in tc
        # rows array
        res = tc["result"]
        assert "rows" in res and isinstance(res["rows"], list)

    def test_search_ip(self, auth_headers):
        r = requests.post(f"{BASE}/api/v1/ai/chat", headers=auth_headers,
                          json={"message": "Who is on IP 10.10.10.55?"}, timeout=90)
        assert r.status_code == 200, r.text
        d = r.json()["data"]
        names = [tc["name"] for tc in d.get("tool_calls", [])]
        assert "search_by_mac_or_ip" in names, f"tool_calls: {d.get('tool_calls')}"

    def test_unregistered(self, auth_headers):
        r = requests.post(f"{BASE}/api/v1/ai/chat", headers=auth_headers,
                          json={"message": "Any unregistered clients ready to register?"}, timeout=90)
        assert r.status_code == 200, r.text
        d = r.json()["data"]
        names = [tc["name"] for tc in d.get("tool_calls", [])]
        assert "list_unregistered_leases" in names, f"tool_calls: {d.get('tool_calls')}"


# ---------------- Conversations list / messages / delete ----------------
class TestConversations:
    def test_list_conversations(self, auth_headers, first_chat):
        r = requests.get(f"{BASE}/api/v1/ai/conversations", headers=auth_headers, timeout=30)
        assert r.status_code == 200
        data = r.json()["data"]
        assert isinstance(data, list) and len(data) >= 1
        first = data[0]
        for k in ("id", "title", "created_at", "updated_at"):
            assert k in first
        # must include the first_chat conversation
        ids = [c["id"] for c in data]
        assert first_chat["data"]["conversation_id"] in ids

    def test_messages_chronological(self, auth_headers, first_chat):
        cid = first_chat["data"]["conversation_id"]
        r = requests.get(f"{BASE}/api/v1/ai/conversations/{cid}/messages", headers=auth_headers, timeout=30)
        assert r.status_code == 200, r.text
        payload = r.json()["data"]
        msgs = payload["messages"]
        assert isinstance(msgs, list) and len(msgs) >= 2
        roles = {m["role"] for m in msgs}
        assert "user" in roles and "assistant" in roles
        # chronological order
        times = [m["created_at"] for m in msgs]
        assert times == sorted(times)

    def test_delete_then_404(self, auth_headers):
        # Create a throwaway conversation using the cheapest tool call already done? Make new short chat.
        r = requests.post(f"{BASE}/api/v1/ai/chat", headers=auth_headers,
                          json={"message": "hi"}, timeout=60)
        assert r.status_code == 200
        cid = r.json()["data"]["conversation_id"]

        d = requests.delete(f"{BASE}/api/v1/ai/conversations/{cid}", headers=auth_headers, timeout=30)
        assert d.status_code == 200

        g = requests.get(f"{BASE}/api/v1/ai/conversations/{cid}/messages", headers=auth_headers, timeout=30)
        assert g.status_code == 404


# ---------------- Audit trail (DB) ----------------
class TestAuditTrail:
    def test_audit_logs_exist(self, first_chat):
        conn = psycopg2.connect(host="localhost", port=5432, dbname="isp_billing",
                                user="isp_user", password="isp_secure_password")
        try:
            cur = conn.cursor()
            cur.execute("""
                SELECT tool_name, arguments, result, latency_ms, status
                FROM ai_audit_logs
                WHERE conversation_id = %s
                ORDER BY created_at
            """, (first_chat["data"]["conversation_id"],))
            rows = cur.fetchall()
            assert len(rows) >= 1, "No audit logs found for conversation"
            found = False
            for tool_name, args, result, latency, status in rows:
                if tool_name == "get_network_status":
                    found = True
                    assert args is not None
                    assert result is not None
                    assert latency is not None and latency > 0
                    assert status == "ok"
            assert found, f"get_network_status audit missing; got: {[r[0] for r in rows]}"
        finally:
            conn.close()


# ---------------- Security: no OpenAI key in responses ----------------
class TestSecurity:
    def test_no_sk_key_in_frontend(self):
        r = requests.get(f"{BASE}/", timeout=30)
        assert "sk-" not in r.text, "Found 'sk-' in landing HTML!"

    def test_no_key_in_chat_response(self, first_chat):
        raw = str(first_chat)
        assert "sk-" not in raw
