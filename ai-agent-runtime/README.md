# Wasl AI Agent Runtime (Microsoft Agent Framework / Semantic Kernel sidecar)

Python FastAPI sidecar that owns agent orchestration, RAG, sessions, and publish workflow hints.
Laravel remains the system of record for billing, SocialAPI, pending actions, and domain tools.

## Quick start

```bash
cd ai-agent-runtime
python -m venv .venv
# Windows: .venv\Scripts\activate
pip install -r requirements.txt
cp .env.example .env
# Apply supabase/schema.sql in your Supabase SQL editor
uvicorn app.main:app --host 0.0.0.0 --port 8090 --reload
```

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/health` | Liveness |
| POST | `/v1/owner/chat` | OwnerAgent turn |
| POST | `/v1/customer/turn` | CustomerAgent turn |
| POST | `/v1/knowledge/ingest` | Chunk + embed upsert |
| POST | `/v1/knowledge/search` | Tenant-scoped vector search |
| POST | `/v1/approvals/{id}/resume` | HITL resume after Laravel confirm |

Auth: `X-Runtime-Key` + `X-Business-Id` (+ optional `X-User-Id`, `X-Correlation-Id`, `X-Surface`).

## Laravel flag

Set `AI_RUNTIME=sk` and point `AI_RUNTIME_URL` at this service.
