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
| POST | `/v1/memory/remember` | Store durable memory (campaign briefs, etc.) |
| POST | `/v1/campaigns/plan_slots` | Stage 1: Build content matrix for all slots |
| POST | `/v1/campaigns/draft_slot` | Stage 2: Draft one slot with assigned matrix row |
| POST | `/v1/campaigns/enhance` | Regenerate/enhance an existing caption |

### Campaign flow (Plan globally, generate locally)

1. **Stage 1 `plan_slots`** — Called once at launch. Receives all slots + image analyses + understanding + `hard_business_rules` (SHOULD/MUST NOT). Returns a content matrix: idea, offer, content_pillar, content_angle, hook_type, cta_type, tone, story_type per slot. Stored in `plan_meta.slot_plans`.

2. **Stage 2 `draft_slot`** — Called per slot. Receives the assigned matrix row + structured DO-NOT-REPEAT list + `hard_business_rules`. Uses RAG (identity, memories, recent posts) to draft the caption. Returns title, caption, hashtags, image_prompt.

Memory key for campaign context: `ai_campaign:{id}` — survives approval/cancel.

Auth: `X-Runtime-Key` + `X-Business-Id` (+ optional `X-User-Id`, `X-Correlation-Id`, `X-Surface`).

## Laravel flag

Set `AI_RUNTIME=sk` and point `AI_RUNTIME_URL` at this service.
