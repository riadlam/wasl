from __future__ import annotations

import json
import logging
from typing import Any, Awaitable, Callable

from app.config import get_settings
from app.llm import LlmClient
from app.middleware.agent_activity import activity
from app.middleware.metacognition import MetacognitionGate
from app.plugins.laravel_client import LaravelClient
from app.rag.store import KnowledgeStore
from app.agents.registry import AgentRegistry, default_registry

logger = logging.getLogger(__name__)

ToolHandler = Callable[[dict[str, Any], dict[str, Any]], Awaitable[dict[str, Any]]]


OWNER_TOOLS: list[dict[str, Any]] = [
    {
        "type": "function",
        "function": {
            "name": "knowledge_search",
            "description": "Search tenant business knowledge (brand, tone, products, FAQs, past posts).",
            "parameters": {
                "type": "object",
                "properties": {
                    "query": {"type": "string"},
                    "namespace": {
                        "type": "string",
                        "enum": ["brand", "products", "faqs", "policies", "tone", "posts", "memories"],
                    },
                    "top_k": {"type": "integer"},
                },
                "required": ["query"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "list_channels",
            "description": (
                "List connected social channels for this shop. Includes logo_url "
                "(custom upload or SocialAPI page picture) for branding on creatives."
            ),
            "parameters": {"type": "object", "properties": {}},
        },
    },
    {
        "type": "function",
        "function": {
            "name": "list_recent_posts",
            "description": (
                "Fetch recent live posts from connected SocialAPI channels (tone/topics/concepts). "
                "Use when drafting captions or matching the shop's recent content style. "
                "limit is 1–20 (default 10). Optionally pass socialapi_account_ids or channel_ids."
            ),
            "parameters": {
                "type": "object",
                "properties": {
                    "limit": {
                        "type": "integer",
                        "description": "Number of recent posts to fetch (1–20).",
                        "minimum": 1,
                        "maximum": 20,
                    },
                    "socialapi_account_ids": {
                        "type": "array",
                        "items": {"type": "string"},
                        "description": "Optional SocialAPI account ids; defaults to all connected channels.",
                    },
                    "channel_ids": {
                        "type": "array",
                        "items": {"type": "integer"},
                        "description": "Optional local Wasl channel ids from list_channels.",
                    },
                },
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_business_context",
            "description": "Load shop profile, channels, and identity context.",
            "parameters": {"type": "object", "properties": {}},
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_shop_reply_language",
            "description": (
                "Read this shop's owner-facing reply language from agent settings "
                "(Algerian Darija or French). Call before translating text for the owner."
            ),
            "parameters": {"type": "object", "properties": {}},
        },
    },
    {
        "type": "function",
        "function": {
            "name": "generate_image",
            "description": "Generate a marketing image via Fal (queued job).",
            "parameters": {
                "type": "object",
                "properties": {
                    "prompt": {"type": "string"},
                    "image_size": {"type": "string"},
                },
                "required": ["prompt"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "prepare_social_post",
            "description": "Prepare a social post (create/schedule) and return a pending approval card. Does not publish until confirmed.",
            "parameters": {
                "type": "object",
                "properties": {
                    "caption": {"type": "string"},
                    "socialapi_account_id": {"type": "string"},
                    "asset_ids": {"type": "array", "items": {"type": "integer"}},
                    "schedule_at": {"type": "string"},
                    "mcp_tool": {"type": "string"},
                },
                "required": ["caption", "socialapi_account_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "confirm_pending_action",
            "description": "Confirm the open pending action for this shop.",
            "parameters": {"type": "object", "properties": {}},
        },
    },
    {
        "type": "function",
        "function": {
            "name": "cancel_pending_action",
            "description": "Cancel the open pending action for this shop.",
            "parameters": {"type": "object", "properties": {}},
        },
    },
    {
        "type": "function",
        "function": {
            "name": "search_products",
            "description": "Search shop catalog products.",
            "parameters": {
                "type": "object",
                "properties": {"query": {"type": "string"}},
                "required": ["query"],
            },
        },
    },
]

# Always kept on the customer belt even when Laravel MCP allowlist omits them.
CUSTOMER_RAG_TOOL_NAMES: tuple[str, ...] = (
    "ask_identity_agent",
    "knowledge_search",
    "list_recent_posts",
)

CUSTOMER_TOOLS: list[dict[str, Any]] = [
    {
        "type": "function",
        "function": {
            "name": "knowledge_search",
            "description": "Search shop knowledge for grounded customer replies.",
            "parameters": {
                "type": "object",
                "properties": {
                    "query": {"type": "string"},
                    "namespace": {"type": "string"},
                    "top_k": {"type": "integer"},
                },
                "required": ["query"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "list_recent_posts",
            "description": (
                "Fetch the latest live posts from this shop's connected page (default 5, max 5 for customer). "
                "REQUIRED before saying an offer/pack/pass is unavailable — check if the page recently "
                "promoted it (e.g. weekly pass, diamond packs, events)."
            ),
            "parameters": {
                "type": "object",
                "properties": {
                    "limit": {
                        "type": "integer",
                        "description": "Number of recent posts (1–5, default 5).",
                        "minimum": 1,
                        "maximum": 5,
                    },
                    "socialapi_account_ids": {
                        "type": "array",
                        "items": {"type": "string"},
                    },
                    "channel_ids": {
                        "type": "array",
                        "items": {"type": "integer"},
                    },
                },
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "search_products",
            "description": (
                "Search this shop's active catalog (any SaaS tenant). Matches keywords individually "
                "and auto-broadens long queries. Empty query lists active SKUs. "
                "If count=0, read hint and retry shorter keywords or empty query + list_categories — "
                "one empty long query is not unavailability. Still check Identity and list_recent_posts."
            ),
            "parameters": {
                "type": "object",
                "properties": {
                    "query": {
                        "type": "string",
                        "description": "Short product keywords from the customer; empty lists catalog.",
                    },
                    "category": {"type": "string"},
                    "min_price": {"type": "number"},
                    "max_price": {"type": "number"},
                    "in_stock": {"type": "boolean"},
                    "limit": {"type": "integer"},
                    "sort": {
                        "type": "string",
                        "enum": ["relevance", "price_asc", "price_desc", "newest"],
                    },
                },
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_product",
            "description": "Get product details by id.",
            "parameters": {
                "type": "object",
                "properties": {"product_id": {"type": "integer"}},
                "required": ["product_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_product_stock",
            "description": "Get live stock for a product id.",
            "parameters": {
                "type": "object",
                "properties": {"product_id": {"type": "integer"}},
                "required": ["product_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "list_categories",
            "description": "List catalog categories this shop sells (games, top-ups, etc.).",
            "parameters": {"type": "object", "properties": {}},
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_shop_profile",
            "description": "Get shop profile and contact basics.",
            "parameters": {"type": "object", "properties": {}},
        },
    },
    {
        "type": "function",
        "function": {
            "name": "list_wilayas",
            "description": "List wilayas covered for delivery.",
            "parameters": {"type": "object", "properties": {}},
        },
    },
    {
        "type": "function",
        "function": {
            "name": "list_delivery_zones",
            "description": "List delivery zones for this shop.",
            "parameters": {"type": "object", "properties": {}},
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_delivery_price",
            "description": "Get delivery price for a wilaya/zone.",
            "parameters": {
                "type": "object",
                "properties": {
                    "wilaya": {"type": "string"},
                    "zone_id": {"type": "integer"},
                    "product_id": {"type": "integer"},
                    "quantity": {"type": "integer"},
                },
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "list_payment_methods",
            "description": (
                "List this shop's enabled payment methods (Flexy, BaridiMob, CCP) ordered by owner priority. "
                "Use after create_order to recommend priority-1 details, or when the client asks how to pay. "
                "Never invent methods not returned here."
            ),
            "parameters": {"type": "object", "properties": {}},
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_order",
            "description": (
                "Get order status by order number, or the latest order for this customer "
                "(includes phone, address, wilaya, digital_fulfillment from prior purchases — "
                "use those to CONFIRM returning-customer details). "
                "REQUIRED before telling the client an order is topped-up/shipped/done. "
                "Only status shipped or delivered (owner sets on Orders dashboard) means done; "
                "pending means still verifying."
            ),
            "parameters": {
                "type": "object",
                "properties": {
                    "order_id": {"type": "integer"},
                    "order_number": {"type": "string"},
                },
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_customer",
            "description": (
                "Load the current customer profile plus known_checkout from profile and their "
                "latest prior order (phone, wilaya, address, digital game IDs). Use these fields "
                "to CONFIRM warmly with returning customers instead of blank re-asking."
            ),
            "parameters": {"type": "object", "properties": {}},
        },
    },
    {
        "type": "function",
        "function": {
            "name": "update_customer",
            "description": "Save a customer field the client clearly provided.",
            "parameters": {
                "type": "object",
                "properties": {
                    "phone": {"type": "string"},
                    "name": {"type": "string"},
                    "wilaya": {"type": "string"},
                    "commune": {"type": "string"},
                    "address": {"type": "string"},
                    "email": {"type": "string"},
                },
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "create_order",
            "description": (
                "Create an order AFTER the client accepted an open offer and ALL required "
                "destination/fulfillment fields are collected (phone + digital IDs, or phone + "
                "wilaya/delivery for physical). NEVER call this before collecting those fields. "
                "Catalog: product_id (+ variant_id if needed). Post/manual: product_name + unit_price. "
                "Always need phone. PHYSICAL ONLY: wilaya + delivery_type. "
                "DIGITAL/game/pass/top-up: digital_fulfillment only — omit wilaya/commune/address. "
                "Pass ai_notes with a short owner-facing summary (game ID, zone, address). "
                "Payment numbers (Flexy/BaridiMob/CCP) come AFTER this tool succeeds — not before."
            ),
            "parameters": {
                "type": "object",
                "properties": {
                    "product_id": {"type": "integer"},
                    "variant_id": {"type": "integer"},
                    "product_name": {"type": "string"},
                    "offer_title": {"type": "string"},
                    "unit_price": {"type": "number"},
                    "quantity": {"type": "integer"},
                    "phone": {"type": "string"},
                    "product_type": {"type": "string", "enum": ["physical", "digital"]},
                    "wilaya": {
                        "type": "string",
                        "description": "PHYSICAL ONLY. Omit for digital.",
                    },
                    "commune": {
                        "type": "string",
                        "description": "PHYSICAL ONLY. Omit for digital.",
                    },
                    "address": {
                        "type": "string",
                        "description": "PHYSICAL ONLY. Omit for digital.",
                    },
                    "delivery_type": {
                        "type": "string",
                        "enum": ["home", "stopdesk"],
                        "description": "PHYSICAL ONLY. Omit for digital.",
                    },
                    "digital_fulfillment": {"type": "object"},
                    "offer_source": {"type": "string"},
                    "payment_method": {"type": "string"},
                    "ai_notes": {
                        "type": "string",
                        "description": "Owner-facing notes for the Orders inbox (game ID, zone, address).",
                    },
                    "notes": {"type": "string", "description": "Alias for ai_notes."},
                },
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "cancel_order",
            "description": "Cancel an order when policy allows.",
            "parameters": {
                "type": "object",
                "properties": {
                    "order_id": {"type": "integer"},
                    "order_number": {"type": "string"},
                    "reason": {"type": "string"},
                },
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "handoff_to_human",
            "description": "Hand the conversation to a human agent.",
            "parameters": {
                "type": "object",
                "properties": {"reason": {"type": "string"}},
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "mark_lead",
            "description": "Mark the customer as a lead with a label.",
            "parameters": {
                "type": "object",
                "properties": {"label": {"type": "string"}},
                "required": ["label"],
            },
        },
    },
]


def ensure_customer_rag_tools(
    tools: list[dict[str, Any]],
    catalog: list[dict[str, Any]] | None = None,
) -> list[dict[str, Any]]:
    """Union Identity + knowledge_search onto a filtered customer tool list.

    Laravel MCP allowlists omit A2A/RAG tools; without this merge the LLM never
    sees ask_identity_agent and invents false 'unavailable' answers.
    """
    by_name: dict[str, dict[str, Any]] = {}
    for t in tools:
        name = (t.get("function") or {}).get("name")
        if name:
            by_name[str(name)] = t
    source = catalog or CUSTOMER_TOOLS
    catalog_by_name = {
        str((t.get("function") or {}).get("name")): t
        for t in source
        if (t.get("function") or {}).get("name")
    }
    for name in CUSTOMER_RAG_TOOL_NAMES:
        if name in by_name:
            continue
        schema = catalog_by_name.get(name)
        if schema:
            by_name[name] = schema
    # Preserve input order, then append any missing RAG tools.
    ordered: list[dict[str, Any]] = []
    seen: set[str] = set()
    for t in tools:
        name = str((t.get("function") or {}).get("name") or "")
        if name and name not in seen:
            ordered.append(by_name[name])
            seen.add(name)
    for name in CUSTOMER_RAG_TOOL_NAMES:
        if name in by_name and name not in seen:
            ordered.append(by_name[name])
            seen.add(name)
    return ordered


class AgentToolRuntime:
    """Auto function-calling loop (SK/AF planning replacement)."""

    def __init__(
        self,
        llm: LlmClient | None = None,
        laravel: LaravelClient | None = None,
        knowledge: KnowledgeStore | None = None,
        registry: AgentRegistry | None = None,
    ) -> None:
        self.llm = llm or LlmClient()
        self.laravel = laravel or LaravelClient()
        self.knowledge = knowledge or KnowledgeStore(llm=self.llm)
        self.registry = registry or default_registry
        self.settings = get_settings()
        self.metacognition = MetacognitionGate(llm=self.llm)

    def filter_tools(self, catalog: list[dict[str, Any]], allowlist: list[str] | None) -> list[dict[str, Any]]:
        if not allowlist:
            return catalog
        allowed = set(allowlist)
        return [t for t in catalog if t["function"]["name"] in allowed]

    def tools_for_surface(self, surface: str, base: list[dict[str, Any]] | None = None) -> list[dict[str, Any]]:
        """Merge base tools with registered specialist as_tool() schemas."""
        catalog = list(base or (CUSTOMER_TOOLS if surface == "customer" else OWNER_TOOLS))
        # Caller id: conversational surfaces are not registered specialists, so pass None
        # (specialists exclude themselves when they become callers later).
        caller = None
        if surface in ("identity", "caption", "owner_approver", "customer_approver"):
            caller = surface
        return self.registry.merge_tools(catalog, caller_agent_id=caller)

    async def run(
        self,
        *,
        messages: list[dict[str, Any]],
        tools: list[dict[str, Any]],
        tenant: dict[str, Any],
        model: str | None = None,
        context: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        ctx = context or {}
        working = list(messages)
        tool_log: list[dict[str, Any]] = []
        generated_assets: list[dict[str, Any]] = []
        pending_image_jobs: list[dict[str, Any]] = []
        pending_action: dict[str, Any] | None = None
        citations: list[dict[str, Any]] = []
        usage = {"prompt_tokens": 0, "completion_tokens": 0, "cost_usd": 0.0, "fal_calls": 0, "calls_with_cost": 0}
        final_text = ""

        for _ in range(self.settings.max_tool_iterations):
            result = await self.llm.chat_completion(working, model=model, tools=tools or None)
            usage["prompt_tokens"] += result["usage"]["prompt_tokens"]
            usage["completion_tokens"] += result["usage"]["completion_tokens"]
            usage["fal_calls"] += 1
            usage["calls_with_cost"] += 1

            tool_calls = result.get("tool_calls") or []
            content = result.get("content") or ""
            if not tool_calls:
                final_text = content
                try:
                    activity().output("tool_loop final text (no more tools)", final_text)
                except Exception:
                    pass
                break

            working.append(
                {
                    "role": "assistant",
                    "content": content or None,
                    "tool_calls": tool_calls,
                }
            )

            for tc in tool_calls:
                name = tc["function"]["name"]
                raw_args = tc["function"].get("arguments") or "{}"
                try:
                    args = json.loads(raw_args) if isinstance(raw_args, str) else dict(raw_args)
                except json.JSONDecodeError:
                    args = {}
                tool_result = await self._dispatch(name, args, tenant, ctx)
                tool_log.append({"tool": name, "arguments": args, "result": tool_result})
                try:
                    activity().tool(name, args, tool_result)
                except Exception:
                    pass

                if name == "generate_image":
                    if tool_result.get("pending") and tool_result.get("job_id"):
                        pending_image_jobs.append({"id": int(tool_result["job_id"]), "status": "queued"})
                    elif tool_result.get("ok") and tool_result.get("asset_id"):
                        generated_assets.append(
                            {
                                "id": int(tool_result["asset_id"]),
                                "url": str(tool_result.get("url") or ""),
                                "mime": str(tool_result.get("mime") or "image/jpeg"),
                                "original_name": str(tool_result.get("original_name") or ""),
                            }
                        )
                if name == "prepare_social_post" and isinstance(tool_result.get("pending_action"), dict):
                    pending_action = tool_result["pending_action"]
                if name == "knowledge_search":
                    for hit in tool_result.get("hits") or []:
                        if isinstance(hit, dict):
                            citations.append(hit)
                if self.registry.has_tool(name):
                    for hit in tool_result.get("citations") or []:
                        if isinstance(hit, dict):
                            citations.append(hit)
                    answer = tool_result.get("answer")
                    if answer:
                        citations.append(
                            {
                                "namespace": "agent_reply",
                                "content": str(answer),
                                "source_id": tool_result.get("agent_id"),
                            }
                        )

                working.append(
                    {
                        "role": "tool",
                        "tool_call_id": tc["id"],
                        "content": json.dumps(tool_result, ensure_ascii=False)[:8000],
                    }
                )
        else:
            final_text = final_text or "I reached the tool iteration limit. Please try again with a shorter request."

        return {
            "reply": final_text or "Done.",
            "tool_calls": tool_log,
            "generated_assets": generated_assets,
            "pending_image_jobs": pending_image_jobs,
            "pending_action": pending_action,
            "citations": citations,
            "usage": usage,
        }

    async def _dispatch(
        self,
        name: str,
        args: dict[str, Any],
        tenant: dict[str, Any],
        context: dict[str, Any],
    ) -> dict[str, Any]:
        business_id = int(tenant["business_id"])
        user_id = tenant.get("user_id")
        surface = str(tenant.get("surface") or "owner")
        correlation_id = str(tenant.get("correlation_id") or "")

        if name == "knowledge_search":
            hits = await self.knowledge.search(
                business_id=business_id,
                query=str(args.get("query") or ""),
                namespace=args.get("namespace"),
                top_k=int(args.get("top_k") or 6),
            )
            return {"ok": True, "hits": hits}

        # Normalize SocialAPI list_posts → capped list_recent_posts.
        # Customer surface: max 5 (live offer check). Owner/campaign: max 20.
        if name in ("list_posts", "list_recent_posts"):
            name = "list_recent_posts"
            try:
                lim = int(args.get("limit") or (5 if surface == "customer" else 10))
            except (TypeError, ValueError):
                lim = 5 if surface == "customer" else 10
            cap = 5 if surface == "customer" else 20
            args = {**args, "limit": max(1, min(cap, lim))}

        if self.registry.has_tool(name):
            return await self.registry.invoke(
                name,
                args,
                {
                    **tenant,
                    "social_account_id": context.get("social_account_id") or tenant.get("social_account_id"),
                    "context": context,
                    "llm_model": tenant.get("llm_model") or context.get("llm_model"),
                },
            )

        if name == "prepare_social_post":
            blocked = self.metacognition.check_prepare_social_post(
                args,
                context.get("session_state") if isinstance(context.get("session_state"), dict) else {},
            )
            if blocked:
                return blocked

        # All mutating / shop tools go through Laravel SoR
        return await self.laravel.invoke_tool(
            business_id=business_id,
            surface=surface,
            tool=name,
            arguments=args,
            user_id=user_id,
            correlation_id=correlation_id,
            context=context,
        )
