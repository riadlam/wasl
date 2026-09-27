from __future__ import annotations

from fastapi.testclient import TestClient

from app.main import app


def test_health():
    client = TestClient(app)
    response = client.get("/health")
    assert response.status_code == 200
    body = response.json()
    assert body["ok"] is True
    assert body["runtime"] == "sk"


def test_owner_chat_requires_key(monkeypatch):
    client = TestClient(app)
    response = client.post("/v1/owner/chat", json={"text": "hi"})
    assert response.status_code == 401
