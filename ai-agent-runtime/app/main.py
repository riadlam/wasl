from __future__ import annotations

import logging
from contextlib import asynccontextmanager

from fastapi import Depends, FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app.agents.business_identity import BusinessIdentityAgent
from app.agents.campaign_brief import CampaignBriefAgent
from app.agents.campaign_tease import CampaignTeaseAgent
from app.agents.customer import CustomerAgentService
from app.agents.owner import OwnerAgentService
from app.agents.post_crafter import PostCrafterAgent
from app.agents.post_enhancer import PostEnhancerAgent
from app.agents.campaign_slot import CampaignSlotAgent
from app.agents.registry import default_registry
from app.agents.translation import TranslationAgent
from app.auth import require_service_key, tenant_headers
from app.config import get_settings
from app.memory.long_term import LongTermMemory
from app.memory.sessions import SessionStore
from app.middleware.telemetry import get_tracer, setup_telemetry
from app.models.schemas import (
    AgentResponse,
    ApprovalResumeRequest,
    CampaignBriefRequest,
    CampaignBriefResponse,
    CampaignEnhanceRequest,
    CampaignEnhanceResponse,
    CampaignDraftSlotRequest,
    CampaignDraftSlotResponse,
    CampaignPlanSlotsRequest,
    CampaignPlanSlotsResponse,
    CampaignTeaseRequest,
    CampaignTeaseResponse,
    CustomerTurnRequest,
    IdentityBuildRequest,
    IdentityBuildResponse,
    KnowledgeIngestRequest,
    KnowledgeIngestResponse,
    KnowledgeSearchHit,
    KnowledgeSearchRequest,
    KnowledgeSearchResponse,
    OwnerChatRequest,
    PostCrafterPlanRequest,
    PostCrafterPlanResponse,
    PostCrafterUnderstandRequest,
    PostCrafterUnderstandResponse,
    TranslationRequest,
    TranslationResponse,
)
from app.rag.store import KnowledgeStore
from pydantic import BaseModel, Field

logger = logging.getLogger(__name__)
settings = get_settings()

sessions = SessionStore(ttl_seconds=settings.session_ttl_seconds)
knowledge = KnowledgeStore()
long_term = LongTermMemory(knowledge)
identity_agent = BusinessIdentityAgent(knowledge=knowledge)
owner_agent = OwnerAgentService(sessions=sessions)
customer_agent = CustomerAgentService(sessions=sessions)
campaign_brief_agent = CampaignBriefAgent(runtime=owner_agent.runtime)
campaign_tease_agent = CampaignTeaseAgent(runtime=owner_agent.runtime)
post_crafter_agent = PostCrafterAgent(runtime=owner_agent.runtime)
post_enhancer_agent = PostEnhancerAgent(runtime=owner_agent.runtime)
campaign_slot_agent = CampaignSlotAgent(runtime=owner_agent.runtime)
translation_agent = TranslationAgent(runtime=owner_agent.runtime)
# Share knowledge store with tool runtimes
owner_agent.runtime.knowledge = knowledge
customer_agent.runtime.knowledge = knowledge
identity_agent.knowledge = knowledge
identity_agent.llm = owner_agent.runtime.llm

# AF-style agent-as-tool: conversational agents can ask Identity (and future specialists).
default_registry.register(
    identity_agent,
    tool_name="ask_identity_agent",
    tool_description=identity_agent.description,
    arg_name="question",
)
default_registry.register(
    translation_agent,
    tool_name="ask_translation_agent",
    tool_description=translation_agent.description,
    arg_name="question",
)
owner_agent.runtime.registry = default_registry
customer_agent.runtime.registry = default_registry
campaign_brief_agent.runtime.registry = default_registry
campaign_tease_agent.runtime.registry = default_registry
post_crafter_agent.runtime.registry = default_registry
post_enhancer_agent.runtime.registry = default_registry
campaign_slot_agent.runtime.registry = default_registry


class RememberRequest(BaseModel):
    key: str = Field(min_length=1, max_length=120)
    content: str = Field(min_length=1)
    metadata: dict = Field(default_factory=dict)


@asynccontextmanager
async def lifespan(_app: FastAPI):
    logging.basicConfig(level=getattr(logging, settings.log_level.upper(), logging.INFO))
    setup_telemetry(settings.otel_service_name, settings.otel_exporter_otlp_endpoint or None)
    if knowledge.available:
        try:
            await knowledge.connect()
            logger.info("Connected to Supabase/Postgres knowledge store")
        except Exception as exc:
            logger.warning("Knowledge store connect failed (RAG disabled until fixed): %s", exc)
    yield
    await knowledge.close()


app = FastAPI(title="Wasl AI Agent Runtime", version="0.1.0", lifespan=lifespan)
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


@app.get("/health")
async def health() -> dict:
    return {
        "ok": True,
        "runtime": "sk",
        "knowledge": knowledge.available,
        "service": settings.otel_service_name,
    }


@app.post("/v1/owner/chat", response_model=AgentResponse, dependencies=[Depends(require_service_key)])
async def owner_chat(body: OwnerChatRequest, tenant: dict = Depends(tenant_headers)) -> AgentResponse:
    tracer = get_tracer()
    with tracer.start_as_current_span("owner.chat") as span:
        span.set_attribute("business_id", tenant["business_id"])
        span.set_attribute("surface", "owner")
        return await owner_agent.chat(body, tenant)


@app.post(
    "/v1/campaigns/brief",
    response_model=CampaignBriefResponse,
    dependencies=[Depends(require_service_key)],
)
async def campaign_brief(
    body: CampaignBriefRequest,
    tenant: dict = Depends(tenant_headers),
) -> CampaignBriefResponse:
    tracer = get_tracer()
    with tracer.start_as_current_span("campaign.brief") as span:
        span.set_attribute("business_id", tenant["business_id"])
        span.set_attribute("surface", "campaign_brief")
        try:
            result = await campaign_brief_agent.turn(
                tenant={**tenant, "llm_model": body.llm_model},
                context_block=body.context_block,
                language_hint=body.language_hint,
                history=[{"role": m.role, "content": m.content} for m in body.history],
                model=body.llm_model,
                correlation_id=str(tenant.get("correlation_id") or ""),
            )
            return CampaignBriefResponse(**result)
        except Exception as exc:
            logger.exception("campaign.brief failed")
            return CampaignBriefResponse(error=str(exc), message="Briefing failed. Try again.")


@app.post(
    "/v1/campaigns/tease",
    response_model=CampaignTeaseResponse,
    dependencies=[Depends(require_service_key)],
)
async def campaign_tease(
    body: CampaignTeaseRequest,
    tenant: dict = Depends(tenant_headers),
) -> CampaignTeaseResponse:
    tracer = get_tracer()
    with tracer.start_as_current_span("campaign.tease") as span:
        span.set_attribute("business_id", tenant["business_id"])
        span.set_attribute("surface", "campaign_tease")
        try:
            result = await campaign_tease_agent.run(
                tenant={**tenant, "llm_model": body.llm_model},
                focus=body.focus,
                platform=body.platform or "facebook",
                kind=body.kind or "post",
                model=body.llm_model,
                correlation_id=str(tenant.get("correlation_id") or ""),
                hard_business_rules=body.hard_business_rules or "",
            )
            return CampaignTeaseResponse(**result)
        except Exception as exc:
            logger.exception("campaign.tease failed")
            return CampaignTeaseResponse(error=str(exc), caption="")


@app.post(
    "/v1/campaigns/enhance",
    response_model=CampaignEnhanceResponse,
    dependencies=[Depends(require_service_key)],
)
async def campaign_enhance(
    body: CampaignEnhanceRequest,
    tenant: dict = Depends(tenant_headers),
) -> CampaignEnhanceResponse:
    tracer = get_tracer()
    with tracer.start_as_current_span("campaign.enhance") as span:
        span.set_attribute("business_id", tenant["business_id"])
        span.set_attribute("surface", "campaign_enhance")
        try:
            result = await post_enhancer_agent.enhance(
                tenant={**tenant, "llm_model": body.llm_model},
                previous_caption=body.previous_caption,
                previous_title=body.previous_title or "",
                previous_hashtags=body.previous_hashtags or [],
                focus=body.focus or "",
                platform=body.platform or "facebook",
                kind=body.kind or "post",
                content_mode=body.content_mode or "product_images",
                model=body.llm_model,
                correlation_id=str(tenant.get("correlation_id") or ""),
                forbidden_hooks=body.forbidden_hooks or "",
                slot_idea=body.slot_idea or "",
                slot_offer=body.slot_offer or "",
                hard_business_rules=body.hard_business_rules or "",
            )
            return CampaignEnhanceResponse(**result)
        except Exception as exc:
            logger.exception("campaign.enhance failed")
            return CampaignEnhanceResponse(error=str(exc), caption="")


@app.post(
    "/v1/campaigns/plan_slots",
    response_model=CampaignPlanSlotsResponse,
    dependencies=[Depends(require_service_key)],
)
async def campaign_plan_slots(
    body: CampaignPlanSlotsRequest,
    tenant: dict = Depends(tenant_headers),
) -> CampaignPlanSlotsResponse:
    tracer = get_tracer()
    with tracer.start_as_current_span("campaign.plan_slots") as span:
        span.set_attribute("business_id", tenant["business_id"])
        span.set_attribute("surface", "campaign_plan_slots")
        try:
            result = await campaign_slot_agent.plan_slots(
                tenant={**tenant, "llm_model": body.llm_model},
                slots=[s.model_dump() for s in body.slots],
                image_analyses=body.image_analyses or [],
                understanding=body.understanding or "",
                product_focus=body.product_focus or "",
                model=body.llm_model,
                correlation_id=str(tenant.get("correlation_id") or ""),
                hard_business_rules=body.hard_business_rules or "",
                process_decisions=body.process_decisions or "",
            )
            return CampaignPlanSlotsResponse(**result)
        except Exception as exc:
            logger.exception("campaign.plan_slots failed")
            return CampaignPlanSlotsResponse(error=str(exc), slots=[])


@app.post(
    "/v1/campaigns/draft_slot",
    response_model=CampaignDraftSlotResponse,
    dependencies=[Depends(require_service_key)],
)
async def campaign_draft_slot(
    body: CampaignDraftSlotRequest,
    tenant: dict = Depends(tenant_headers),
) -> CampaignDraftSlotResponse:
    tracer = get_tracer()
    with tracer.start_as_current_span("campaign.draft_slot") as span:
        span.set_attribute("business_id", tenant["business_id"])
        span.set_attribute("surface", "campaign_draft_slot")
        try:
            result = await campaign_slot_agent.draft_slot(
                tenant={**tenant, "llm_model": body.llm_model},
                focus=body.focus or "",
                platform=body.platform or "facebook",
                kind=body.kind or "post",
                content_mode=body.content_mode or "product_images",
                slot_idea=body.slot_idea or "",
                slot_offer=body.slot_offer or "",
                slot_angle=body.slot_angle or "",
                forbidden_hooks=body.forbidden_hooks or "",
                image_data_url=body.image_data_url,
                image_description=body.image_description or "",
                model=body.llm_model,
                correlation_id=str(tenant.get("correlation_id") or ""),
                hard_business_rules=body.hard_business_rules or "",
            )
            return CampaignDraftSlotResponse(**result)
        except Exception as exc:
            logger.exception("campaign.draft_slot failed")
            return CampaignDraftSlotResponse(error=str(exc), caption="")


@app.post(
    "/v1/post_crafter/plan",
    response_model=PostCrafterPlanResponse,
    dependencies=[Depends(require_service_key)],
)
async def post_crafter_plan(
    body: PostCrafterPlanRequest,
    tenant: dict = Depends(tenant_headers),
) -> PostCrafterPlanResponse:
    tracer = get_tracer()
    with tracer.start_as_current_span("post_crafter.plan") as span:
        span.set_attribute("business_id", tenant["business_id"])
        span.set_attribute("surface", "post_crafter")
        try:
            result = await post_crafter_agent.plan(
                tenant={**tenant, "llm_model": body.llm_model, "surface": "post_crafter"},
                focus=body.focus,
                content_mode=body.content_mode or "ai_recent",
                platform=body.platform or "facebook",
                kind=body.kind or "post",
                image_urls=[row.model_dump() for row in body.image_urls],
                channel_ids=list(body.channel_ids or []),
                model=body.llm_model,
                correlation_id=str(tenant.get("correlation_id") or ""),
                brief_notes=body.brief_notes or "",
            )
            return PostCrafterPlanResponse(**result)
        except Exception as exc:
            logger.exception("post_crafter.plan failed")
            return PostCrafterPlanResponse(error=str(exc), tease_caption="")


@app.post(
    "/v1/post_crafter/understand",
    response_model=PostCrafterUnderstandResponse,
    dependencies=[Depends(require_service_key)],
)
async def post_crafter_understand(
    body: PostCrafterUnderstandRequest,
    tenant: dict = Depends(tenant_headers),
) -> PostCrafterUnderstandResponse:
    tracer = get_tracer()
    with tracer.start_as_current_span("post_crafter.understand") as span:
        span.set_attribute("business_id", tenant["business_id"])
        span.set_attribute("surface", "post_crafter_understand")
        try:
            result = await post_crafter_agent.understand(
                tenant={
                    **tenant,
                    "llm_model": body.llm_model,
                    "surface": "post_crafter_understand",
                    "reply_language": body.reply_language or "",
                },
                image_urls=[row.model_dump() for row in body.image_urls],
                focus=body.focus or "",
                content_mode=body.content_mode or "product_images",
                model=body.llm_model,
                correlation_id=str(tenant.get("correlation_id") or ""),
            )
            return PostCrafterUnderstandResponse(**result)
        except Exception as exc:
            logger.exception("post_crafter.understand failed")
            return PostCrafterUnderstandResponse(error=str(exc), summary="")


@app.post(
    "/v1/translation/translate",
    response_model=TranslationResponse,
    dependencies=[Depends(require_service_key)],
)
async def translation_translate(
    body: TranslationRequest,
    tenant: dict = Depends(tenant_headers),
) -> TranslationResponse:
    tracer = get_tracer()
    with tracer.start_as_current_span("translation.translate") as span:
        span.set_attribute("business_id", tenant["business_id"])
        span.set_attribute("surface", "translation")
        try:
            result = await translation_agent.translate(
                tenant={**tenant, "llm_model": body.llm_model, "surface": "translation"},
                texts=dict(body.texts or {}),
                language_hint=body.language_hint or "",
                model=body.llm_model,
                correlation_id=str(tenant.get("correlation_id") or ""),
            )
            return TranslationResponse(**result)
        except Exception as exc:
            logger.exception("translation.translate failed")
            return TranslationResponse(error=str(exc), texts=dict(body.texts or {}))


@app.post("/v1/customer/turn", response_model=AgentResponse, dependencies=[Depends(require_service_key)])
async def customer_turn(body: CustomerTurnRequest, tenant: dict = Depends(tenant_headers)) -> AgentResponse:
    tracer = get_tracer()
    with tracer.start_as_current_span("customer.turn") as span:
        span.set_attribute("business_id", tenant["business_id"])
        span.set_attribute("surface", "customer")
        span.set_attribute("conversation_id", body.conversation_id)
        return await customer_agent.turn(body, tenant)


@app.post(
    "/v1/knowledge/ingest",
    response_model=KnowledgeIngestResponse,
    dependencies=[Depends(require_service_key)],
)
async def knowledge_ingest(body: KnowledgeIngestRequest) -> KnowledgeIngestResponse:
    tracer = get_tracer()
    with tracer.start_as_current_span("knowledge.ingest") as span:
        span.set_attribute("business_id", body.business_id)
        span.set_attribute("namespace", body.namespace)
        count = await knowledge.upsert_document(
            business_id=body.business_id,
            namespace=body.namespace,
            source_type=body.source_type,
            source_id=body.source_id,
            content=body.content,
            metadata=body.metadata,
            chunk_size=body.chunk_size,
            chunk_overlap=body.chunk_overlap,
        )
        return KnowledgeIngestResponse(
            ok=True,
            chunks_upserted=count,
            business_id=body.business_id,
            source_type=body.source_type,
            source_id=body.source_id,
        )


@app.post(
    "/v1/knowledge/search",
    response_model=KnowledgeSearchResponse,
    dependencies=[Depends(require_service_key)],
)
async def knowledge_search(body: KnowledgeSearchRequest, tenant: dict = Depends(tenant_headers)) -> KnowledgeSearchResponse:
    hits = await knowledge.search(
        business_id=tenant["business_id"],
        query=body.query,
        namespace=body.namespace,
        top_k=body.top_k,
    )
    return KnowledgeSearchResponse(hits=[KnowledgeSearchHit(**h) for h in hits])


@app.post(
    "/v1/approvals/{approval_id}/resume",
    response_model=AgentResponse,
    dependencies=[Depends(require_service_key)],
)
async def approval_resume(
    approval_id: str,
    body: ApprovalResumeRequest,
    tenant: dict = Depends(tenant_headers),
) -> AgentResponse:
    """Resume after Laravel HITL confirm/cancel. Laravel remains executor; this updates session state."""
    session_ref = body.session_id or "default"
    session = sessions.get_or_create(tenant["business_id"], "owner", session_ref)
    if body.approved:
        session.setdefault("state", {})["publish_step"] = "done"
        reply = f"Approval {approval_id} accepted. Laravel will execute the pending action."
    else:
        session.setdefault("state", {})["publish_step"] = "idle"
        reply = f"Approval {approval_id} cancelled."
    sessions.save(session)
    return AgentResponse(
        reply=reply,
        approval_required=False,
        approval_id=approval_id,
        session_id=str(session["id"]),
        runtime="sk",
    )


@app.post("/v1/memory/remember", dependencies=[Depends(require_service_key)])
async def memory_remember(body: RememberRequest, tenant: dict = Depends(tenant_headers)) -> dict:
    ok = await long_term.remember(
        business_id=tenant["business_id"],
        key=body.key,
        content=body.content,
        metadata=body.metadata,
    )
    return {"ok": ok, "business_id": tenant["business_id"], "key": body.key}


@app.post(
    "/v1/identity/build",
    response_model=IdentityBuildResponse,
    dependencies=[Depends(require_service_key)],
)
async def identity_build(body: IdentityBuildRequest) -> IdentityBuildResponse:
    tracer = get_tracer()
    with tracer.start_as_current_span("identity.build") as span:
        span.set_attribute("business_id", body.business_id)
        span.set_attribute("social_account_id", body.social_account_id)
        try:
            result = await identity_agent.build(
                business_id=body.business_id,
                social_account_id=body.social_account_id,
                corpus=body.corpus or {},
                model=body.llm_model,
            )
            return IdentityBuildResponse(**result)
        except Exception as exc:
            logger.exception("identity.build failed")
            return IdentityBuildResponse(
                ok=False,
                business_id=body.business_id,
                social_account_id=body.social_account_id,
                error=str(exc),
            )
