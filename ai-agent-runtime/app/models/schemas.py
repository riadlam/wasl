from __future__ import annotations

from typing import Any, Literal

from pydantic import BaseModel, Field


class Attachment(BaseModel):
    id: int | None = None
    url: str | None = None
    mime: str | None = None
    original_name: str | None = None
    kind: Literal["image", "audio", "video", "file", "other"] | None = None


class ChatMessage(BaseModel):
    role: Literal["user", "assistant", "system", "tool"]
    content: str


class OwnerChatRequest(BaseModel):
    text: str = ""
    voice_lang: str | None = None
    agent_chat_id: int | None = None
    attachments: list[Attachment] = Field(default_factory=list)
    history: list[ChatMessage] = Field(default_factory=list)
    system_prompt: str | None = None
    llm_model: str | None = None
    tool_allowlist: list[str] = Field(default_factory=list)


class CustomerTurnRequest(BaseModel):
    message_id: int
    conversation_id: int
    text: str = ""
    message_type: Literal["dm", "comment", "other"] = "dm"
    media_url: str | None = None
    media_type: str | None = None
    history: list[ChatMessage] = Field(default_factory=list)
    system_prompt: str | None = None
    llm_model: str | None = None
    allow_reply: bool = True
    customer_id: int | None = None
    social_account_id: int | None = None
    tool_allowlist: list[str] = Field(default_factory=list)
    known_checkout: list[str] = Field(default_factory=list)


class KnowledgeIngestRequest(BaseModel):
    business_id: int
    namespace: Literal["brand", "products", "faqs", "policies", "tone", "posts", "memories"] = "brand"
    source_type: str
    source_id: str
    content: str
    metadata: dict[str, Any] = Field(default_factory=dict)
    chunk_size: int = 800
    chunk_overlap: int = 120


class KnowledgeSearchRequest(BaseModel):
    query: str
    namespace: str | None = None
    top_k: int = 6


class ApprovalResumeRequest(BaseModel):
    approved: bool = True
    pending_action_id: int | None = None
    session_id: str | None = None
    notes: str | None = None


class UsagePayload(BaseModel):
    prompt_tokens: int = 0
    completion_tokens: int = 0
    cost_usd: float = 0.0
    fal_calls: int = 0
    calls_with_cost: int = 0


class AgentResponse(BaseModel):
    reply: str
    pending_action: dict[str, Any] | None = None
    approval_required: bool = False
    approval_id: str | None = None
    tool_calls: list[dict[str, Any]] = Field(default_factory=list)
    generated_assets: list[dict[str, Any]] = Field(default_factory=list)
    pending_image_jobs: list[dict[str, Any]] = Field(default_factory=list)
    usage: UsagePayload = Field(default_factory=UsagePayload)
    session_id: str | None = None
    citations: list[dict[str, Any]] = Field(default_factory=list)
    error: str | None = None
    runtime: str = "sk"


class KnowledgeIngestResponse(BaseModel):
    ok: bool = True
    chunks_upserted: int = 0
    business_id: int
    source_type: str
    source_id: str


class KnowledgeSearchHit(BaseModel):
    id: str
    content: str
    namespace: str
    source_type: str
    source_id: str
    score: float
    metadata: dict[str, Any] = Field(default_factory=dict)


class KnowledgeSearchResponse(BaseModel):
    hits: list[KnowledgeSearchHit] = Field(default_factory=list)


class IdentityBuildRequest(BaseModel):
    business_id: int
    social_account_id: int
    corpus: dict[str, Any] = Field(default_factory=dict)
    llm_model: str | None = None


class IdentityBuildResponse(BaseModel):
    ok: bool = True
    business_id: int
    social_account_id: int
    chunks_upserted: int = 0
    namespaces: list[str] = Field(default_factory=list)
    summary: str = ""
    storage: str = "supabase"
    error: str | None = None


class CampaignBriefRequest(BaseModel):
    context_block: str = ""
    language_hint: str = "Darija"
    history: list[ChatMessage] = Field(default_factory=list)
    llm_model: str | None = None


class CampaignBriefResponse(BaseModel):
    ready: bool = False
    message: str = ""
    question: str = ""
    tool_calls: list[dict[str, Any]] = Field(default_factory=list)
    approver: dict[str, Any] | None = None
    agents: dict[str, Any] = Field(default_factory=dict)
    usage: UsagePayload = Field(default_factory=UsagePayload)
    runtime: str = "sk"
    error: str | None = None


class CampaignTeaseRequest(BaseModel):
    focus: str = ""
    platform: str = "facebook"
    kind: str = "post"
    llm_model: str | None = None
    hard_business_rules: str = ""


class CampaignTeaseResponse(BaseModel):
    title: str = ""
    caption: str = ""
    hashtags: list[str] = Field(default_factory=list)
    image_prompt: str = ""
    approved: bool = False
    needs_owner_edit: bool = False
    rounds: list[dict[str, Any]] = Field(default_factory=list)
    agents: dict[str, Any] = Field(default_factory=dict)
    approver: dict[str, Any] | None = None
    usage: UsagePayload = Field(default_factory=UsagePayload)
    runtime: str = "sk"
    error: str | None = None


class CampaignEnhanceRequest(BaseModel):
    previous_caption: str = ""
    previous_title: str = ""
    previous_hashtags: list[str] = Field(default_factory=list)
    focus: str = ""
    platform: str = "facebook"
    kind: str = "post"
    content_mode: str = "product_images"
    llm_model: str | None = None
    forbidden_hooks: str = ""
    slot_idea: str = ""
    slot_offer: str = ""
    hard_business_rules: str = ""


class CampaignEnhanceResponse(BaseModel):
    title: str = ""
    caption: str = ""
    hashtags: list[str] = Field(default_factory=list)
    image_prompt: str = ""
    improvement_notes: str = ""
    agents: dict[str, Any] = Field(default_factory=dict)
    usage: UsagePayload = Field(default_factory=UsagePayload)
    runtime: str = "sk"
    error: str | None = None


class CampaignPlanSlotItem(BaseModel):
    slot_id: int
    kind: str = "post"
    day_index: int = 1
    asset_id: int | None = None


class CampaignPlanSlotsRequest(BaseModel):
    slots: list[CampaignPlanSlotItem] = Field(default_factory=list)
    image_analyses: list[dict[str, Any]] = Field(default_factory=list)
    understanding: str = ""
    product_focus: str = ""
    llm_model: str | None = None


class CampaignPlanSlotsResponse(BaseModel):
    slots: list[dict[str, Any]] = Field(default_factory=list)
    agents: dict[str, Any] = Field(default_factory=dict)
    usage: UsagePayload = Field(default_factory=UsagePayload)
    runtime: str = "sk"
    error: str | None = None


class CampaignDraftSlotRequest(BaseModel):
    focus: str = ""
    platform: str = "facebook"
    kind: str = "post"
    content_mode: str = "product_images"
    slot_idea: str = ""
    slot_offer: str = ""
    slot_angle: str = ""
    forbidden_hooks: str = ""
    image_data_url: str | None = None
    image_description: str = ""
    llm_model: str | None = None
    hard_business_rules: str = ""


class CampaignDraftSlotResponse(BaseModel):
    title: str = ""
    caption: str = ""
    hashtags: list[str] = Field(default_factory=list)
    image_prompt: str = ""
    agents: dict[str, Any] = Field(default_factory=dict)
    usage: UsagePayload = Field(default_factory=UsagePayload)
    runtime: str = "sk"
    error: str | None = None


class PostCrafterImageRef(BaseModel):
    url: str
    label: str = ""


class PostCrafterPlanRequest(BaseModel):
    focus: str = ""
    brief_notes: str = ""
    content_mode: str = "ai_recent"
    platform: str = "facebook"
    kind: str = "post"
    image_urls: list[PostCrafterImageRef] = Field(default_factory=list)
    channel_ids: list[int] = Field(default_factory=list)
    llm_model: str | None = None


class PostCrafterPlanResponse(BaseModel):
    analysis: str = ""
    plan_notes: str = ""
    tease_caption: str = ""
    title: str = ""
    hashtags: list[str] = Field(default_factory=list)
    image_prompt: str = ""
    product_focus: str = ""
    multi_product: bool = False
    plan_meta: dict[str, Any] = Field(default_factory=dict)
    approved: bool = False
    needs_owner_edit: bool = False
    agents: dict[str, Any] = Field(default_factory=dict)
    approver: dict[str, Any] | None = None
    usage: UsagePayload = Field(default_factory=UsagePayload)
    runtime: str = "sk"
    error: str | None = None


class TranslationRequest(BaseModel):
    texts: dict[str, str] = Field(default_factory=dict)
    language_hint: str = ""
    llm_model: str | None = None


class TranslationResponse(BaseModel):
    texts: dict[str, str] = Field(default_factory=dict)
    language: str = ""
    label: str = ""
    agents: dict[str, Any] = Field(default_factory=dict)
    usage: UsagePayload = Field(default_factory=UsagePayload)
    runtime: str = "sk"
    error: str | None = None


class PostCrafterUnderstandRequest(BaseModel):
    focus: str = ""
    content_mode: str = "product_images"
    image_urls: list[PostCrafterImageRef] = Field(default_factory=list)
    llm_model: str | None = None
    reply_language: str = ""


class PostCrafterUnderstandResponse(BaseModel):
    summary: str = ""
    product_focus: str = ""
    multi_product: bool = False
    image_analyses: list[dict[str, Any]] = Field(default_factory=list)
    claims: list[dict[str, Any]] = Field(default_factory=list)
    process_options: list[dict[str, Any]] = Field(default_factory=list)
    agents: dict[str, Any] = Field(default_factory=dict)
    usage: UsagePayload = Field(default_factory=UsagePayload)
    runtime: str = "sk"
    error: str | None = None
