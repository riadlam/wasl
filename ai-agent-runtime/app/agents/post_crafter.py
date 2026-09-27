from __future__ import annotations

import asyncio
import json
import logging
import re
from typing import Any

from app.agents.post_crafter_approver import PostCrafterApprover
from app.agents.registry import default_registry
from app.agents.tool_runtime import AgentToolRuntime
from app.llm import LlmClient
from app.middleware.agent_activity import activity

logger = logging.getLogger(__name__)

PLAN_SYSTEM = """You are PostCrafterExpertSocialMediaManager for Maghreb shops on Wasl.
Plan posts/stories for THIS shop's real field. Never invent products, prices, or stock.
Ground every product look claim in vision analysis and/or owner-accepted understanding.
Match brand tone from identity/memories — tone only, not product SKUs.

When content_mode is product_images (critical):
- ONLY feature products/offers that appear in vision analyses or owner-accepted understanding / brief_notes.
- Do NOT promote catalog products (e.g. monthly pass) that are absent from vision/understanding.
- If vision shows Weekly Pass / flash sale, stay on that — do not add unrelated SKUs from identity RAG.

Return ONLY valid JSON with keys:
  analysis: string (what you saw + shop field fit)
  plan_notes: string (campaign narrative: multi-product vs single SKU, angles)
  tease_caption: string (one sample caption for the owner tease)
  title: string (short hook)
  hashtags: string[] (3-5, with #)
  image_prompt: string (for AI creative when mode is ai_recent)
  product_focus: string (what to feature — must match vision/understanding)
  multi_product: boolean
"""


class PostCrafterAgent:
    """Vision + identity grounded social campaign planner / tease author."""

    agent_id = "post_crafter"
    description = (
        "Expert Maghreb social media manager. Analyzes product/recent-post images, "
        "consults shop identity and campaign memories, then plans captions and creatives."
    )

    def __init__(
        self,
        runtime: AgentToolRuntime | None = None,
        approver: PostCrafterApprover | None = None,
        llm: LlmClient | None = None,
    ) -> None:
        self.runtime = runtime or AgentToolRuntime()
        self.llm = llm or self.runtime.llm
        self.approver = approver or PostCrafterApprover(llm=self.llm)

    async def analyze_image(
        self,
        *,
        image_url: str,
        label: str = "",
        model: str | None = None,
        timeout_s: float = 60.0,
    ) -> dict[str, Any]:
        url = (image_url or "").strip()
        if not url:
            return {"ok": False, "error": "empty_url", "description": ""}
        prompt = (
            "You are analyzing a shop product or social post image for Maghreb marketing. "
            "In 3-5 sentences describe: products visible, vibe/style, any text-in-image, "
            "whether this looks like a multi-product batch vs a single SKU, colors, and CTA cues. "
            f"Label: {label or 'asset'}."
        )
        try:
            result = await asyncio.wait_for(
                self.llm.chat_completion(
                    [
                        {
                            "role": "user",
                            "content": [
                                {"type": "text", "text": prompt},
                                {"type": "image_url", "image_url": {"url": url}},
                            ],
                        }
                    ],
                    model=model,
                    temperature=0.2,
                ),
                timeout=timeout_s,
            )
            desc = (result.get("content") or "").strip()
            return {
                "ok": True,
                "url": url if not url.startswith("data:") else f"data:image({label or 'asset'})",
                "label": label,
                "description": desc or f"Image at {label or 'asset'}",
                "usage": result.get("usage") or {},
            }
        except asyncio.TimeoutError:
            logger.info("post_crafter.analyze_image timeout label=%s after %ss", label, timeout_s)
            return {
                "ok": False,
                "url": url if not url.startswith("data:") else f"data:image({label or 'asset'})",
                "label": label,
                "description": f"Vision timed out for {label or 'image'}",
                "error": "timeout",
            }
        except Exception as exc:
            logger.info("post_crafter.analyze_image failed: %s", exc)
            return {
                "ok": False,
                "url": url if not url.startswith("data:") else f"data:image({label or 'asset'})",
                "label": label,
                "description": f"Vision unavailable for {label or url}: {exc}",
                "error": str(exc),
            }

    async def understand(
        self,
        *,
        tenant: dict[str, Any],
        image_urls: list[dict[str, Any]] | None = None,
        focus: str = "",
        content_mode: str = "product_images",
        model: str | None = None,
        correlation_id: str = "",
    ) -> dict[str, Any]:
        """Cheap vision-only pass — no tease caption / plan LLM."""
        business_id = int(tenant.get("business_id") or 0)
        usage = {
            "prompt_tokens": 0,
            "completion_tokens": 0,
            "cost_usd": 0.0,
            "fal_calls": 0,
            "calls_with_cost": 0,
        }
        with activity().turn(
            "PostCrafterAgent",
            business_id=business_id,
            correlation_id=correlation_id,
            extra={"surface": "post_crafter_understand", "content_mode": content_mode},
        ):
            activity().input(
                "post crafter understand",
                {"mode": content_mode, "images": len(image_urls or []), "focus": (focus or "")[:400]},
            )
            rows = [
                row for row in (image_urls or [])[:12]
                if str(row.get("url") or "").strip()
            ]

            async def _one(row: dict[str, Any]) -> dict[str, Any]:
                return await self.analyze_image(
                    image_url=str(row.get("url") or "").strip(),
                    label=str(row.get("label") or ""),
                    model=model,
                    timeout_s=55.0,
                )

            image_analyses: list[dict[str, Any]] = list(await asyncio.gather(*[_one(r) for r in rows])) if rows else []
            for analysis in image_analyses:
                self._merge_usage(usage, analysis.get("usage") or {})

            bits = [
                (a.get("description") or "").strip()
                for a in image_analyses
                if (a.get("description") or "").strip()
            ]
            if bits:
                summary = " | ".join(bits)
            elif (focus or "").strip():
                summary = f"Owner focus: {(focus or '').strip()[:500]}"
            else:
                summary = "No image analysis available yet."

            # Synthesis: trust vision (no owner confirm cards on image facts).
            # Ask only useful process decisions; language comes from shop settings.
            product_focus = ""
            multi = len(image_analyses) > 1
            reply_language = str(
                tenant.get("reply_language")
                or (tenant.get("business") or {}).get("reply_language")
                or ""
            ).strip()
            process_options: list[dict[str, Any]] = []
            if bits and self.llm:
                try:
                    synth = await self.llm.chat_completion(
                        [
                            {
                                "role": "system",
                                "content": (
                                    "You analyze Maghreb shop campaign images for Wasl. "
                                    "Vision is trusted — do NOT invent catalog products absent from vision notes.\n"
                                    "Return ONLY JSON with keys:\n"
                                    "summary: 2-3 short sentences of what is IN the images\n"
                                    "product_focus: main product/offer seen\n"
                                    "multi_product: boolean\n"
                                    "process_questions: 0-3 short YES/NO decision cards for the owner about "
                                    "HOW to turn these images into posts (group always \"process\"). "
                                    "Each: id (snake_case), text (one clear decision statement), default_on (bool).\n"
                                    "Good examples when 2+ creatives: separate post per image vs one combined campaign story; "
                                    "which offer to lead with.\n"
                                    "NEVER ask about: reply language, Darija, French, tone/energy, CTA reminders, "
                                    "or confirming what is already visible in the images "
                                    f"(shop reply language is already set: {reply_language or 'business settings'})."
                                ),
                            },
                            {
                                "role": "user",
                                "content": json.dumps(
                                    {
                                        "vision_notes": bits,
                                        "owner_focus": focus or "",
                                        "image_count": len(image_analyses),
                                        "reply_language_setting": reply_language or "(shop settings)",
                                    },
                                    ensure_ascii=False,
                                ),
                            },
                        ],
                        model=model,
                        temperature=0.2,
                    )
                    self._merge_usage(usage, synth.get("usage") or {})
                    parsed = self._parse_understand(synth.get("content") or "")
                    if parsed.get("summary"):
                        summary = parsed["summary"]
                    product_focus = str(parsed.get("product_focus") or "")
                    multi = bool(parsed.get("multi_product", multi))
                    raw_q = parsed.get("process_questions")
                    if not isinstance(raw_q, list):
                        raw_q = parsed.get("process_options") if isinstance(parsed.get("process_options"), list) else []
                    for i, c in enumerate(raw_q[:5]):
                        if not isinstance(c, dict):
                            continue
                        text = str(c.get("text") or c.get("label") or "").strip()
                        if not text:
                            continue
                        cid = str(c.get("id") or f"process_{i+1}").strip() or f"process_{i+1}"
                        process_options.append({
                            "id": cid[:64],
                            "text": text[:180],
                            "group": "process",
                            "required": True,
                            "default_on": bool(c.get("default_on", False)),
                        })
                except Exception as exc:
                    logger.info("post_crafter.understand synth skipped: %s", exc)

            if process_options:
                approved = await self.approver.filter_process_cards(
                    cards=process_options,
                    summary=summary,
                    reply_language=reply_language,
                    image_count=len(image_analyses),
                    model=model,
                )
                process_options = approved

            out = {
                "summary": summary,
                "product_focus": product_focus,
                "multi_product": multi,
                "image_analyses": [
                    {
                        "url": a.get("url"),
                        "label": a.get("label"),
                        "description": a.get("description"),
                        "ok": bool(a.get("ok")),
                    }
                    for a in image_analyses
                ],
                "claims": [],
                "process_options": process_options,
                "usage": usage,
                "runtime": "sk",
                "error": None,
                "agents": {
                    "planner": "PostCrafterAgent",
                    "step": "understand",
                    "approver": "PostCrafterApprover",
                },
            }
            activity().output(
                "post crafter understand done",
                {
                    "summary_len": len(summary),
                    "images": len(image_analyses),
                    "process_cards": len(process_options),
                },
            )
            return out

    @staticmethod
    def _parse_understand(raw: str) -> dict[str, Any]:
        text = (raw or "").strip()
        if text.startswith("```"):
            text = re.sub(r"^```(?:json)?\s*", "", text)
            text = re.sub(r"\s*```$", "", text)
        try:
            data = json.loads(text)
        except json.JSONDecodeError:
            m = re.search(r"\{[\s\S]*\}", text)
            if not m:
                return {}
            try:
                data = json.loads(m.group(0))
            except json.JSONDecodeError:
                return {}
        return data if isinstance(data, dict) else {}

    async def plan(
        self,
        *,
        tenant: dict[str, Any],
        focus: str,
        content_mode: str = "ai_recent",
        platform: str = "facebook",
        kind: str = "post",
        image_urls: list[dict[str, Any]] | None = None,
        channel_ids: list[int] | None = None,
        model: str | None = None,
        correlation_id: str = "",
        brief_notes: str = "",
    ) -> dict[str, Any]:
        business_id = int(tenant.get("business_id") or 0)
        usage = {
            "prompt_tokens": 0,
            "completion_tokens": 0,
            "cost_usd": 0.0,
            "fal_calls": 0,
            "calls_with_cost": 0,
        }
        with activity().turn(
            "PostCrafterAgent",
            business_id=business_id,
            correlation_id=correlation_id,
            extra={"surface": "post_crafter", "content_mode": content_mode, "platform": platform},
        ):
            activity().input(
                "post crafter plan",
                {"focus": (focus or "")[:800], "mode": content_mode, "images": len(image_urls or [])},
            )

            image_analyses: list[dict[str, Any]] = []
            for row in (image_urls or [])[:12]:
                url = str(row.get("url") or "").strip()
                if not url:
                    continue
                analysis = await self.analyze_image(
                    image_url=url,
                    label=str(row.get("label") or ""),
                    model=model,
                )
                self._merge_usage(usage, analysis.get("usage") or {})
                image_analyses.append(analysis)

            recent_visuals: list[dict[str, Any]] = []
            recent_posts_text = ""
            if content_mode == "ai_recent":
                recent_posts_text, recent_media = await self._fetch_recent_posts(
                    tenant=tenant,
                    channel_ids=channel_ids or [],
                )
                for media in recent_media[:6]:
                    analysis = await self.analyze_image(
                        image_url=media["url"],
                        label=media.get("label") or "recent_post",
                        model=model,
                    )
                    self._merge_usage(usage, analysis.get("usage") or {})
                    recent_visuals.append(analysis)

            identity_notes = await self._identity_and_memories(
                business_id=business_id,
                focus=focus,
                tenant=tenant,
                model=model,
            )

            vision_block = self._format_analyses(image_analyses, "Uploaded product images")
            recent_block = self._format_analyses(recent_visuals, "Recent post visuals")
            user_payload = {
                "shop_focus": focus,
                "brief_notes": brief_notes,
                "content_mode": content_mode,
                "platform": platform,
                "kind": kind,
                "vision_product_images": vision_block,
                "vision_recent_posts": recent_block,
                "recent_posts_text": recent_posts_text[:4000],
                "identity_and_memories": identity_notes[:6000],
            }

            result = await self.llm.chat_completion(
                [
                    {"role": "system", "content": PLAN_SYSTEM},
                    {"role": "user", "content": json.dumps(user_payload, ensure_ascii=False)},
                ],
                model=model,
                temperature=0.45,
            )
            self._merge_usage(usage, result.get("usage") or {})
            structured = self._parse_plan(result.get("content") or "", focus)

            vision_notes = "\n".join(
                a.get("description") or ""
                for a in (image_analyses + recent_visuals)
                if a.get("description")
            )
            approver = await self.approver.review(
                caption=structured["tease_caption"],
                plan_notes=structured["plan_notes"],
                vision_notes=vision_notes,
                identity_notes=identity_notes,
                model=model,
            )

            plan_meta = {
                "analysis": structured["analysis"],
                "plan_notes": structured["plan_notes"],
                "product_focus": structured["product_focus"],
                "multi_product": structured["multi_product"],
                "image_analyses": [
                    {
                        "url": a.get("url"),
                        "label": a.get("label"),
                        "description": a.get("description"),
                        "ok": bool(a.get("ok")),
                    }
                    for a in image_analyses
                ],
                "recent_visuals": [
                    {
                        "url": a.get("url"),
                        "label": a.get("label"),
                        "description": a.get("description"),
                        "ok": bool(a.get("ok")),
                    }
                    for a in recent_visuals
                ],
                "content_mode": content_mode,
                "platform": platform,
                "kind": kind,
            }

            out = {
                "analysis": structured["analysis"],
                "plan_notes": structured["plan_notes"],
                "tease_caption": structured["tease_caption"],
                "title": structured["title"],
                "hashtags": structured["hashtags"],
                "image_prompt": structured["image_prompt"],
                "product_focus": structured["product_focus"],
                "multi_product": structured["multi_product"],
                "plan_meta": plan_meta,
                "approved": bool(approver.get("approved")),
                "needs_owner_edit": not bool(approver.get("approved")),
                "approver": approver,
                "agents": {
                    "planner": "PostCrafterAgent",
                    "identity": "BusinessIdentityAgent",
                    "approver": "PostCrafterApprover",
                },
                "usage": usage,
                "runtime": "sk",
                "error": None,
            }
            activity().output("post crafter done", {"caption_len": len(out["tease_caption"]), "approved": out["approved"]})
            return out

    async def _fetch_recent_posts(
        self,
        *,
        tenant: dict[str, Any],
        channel_ids: list[int],
    ) -> tuple[str, list[dict[str, str]]]:
        media: list[dict[str, str]] = []
        text = ""
        try:
            args: dict[str, Any] = {"limit": 8}
            if channel_ids:
                args["channel_ids"] = channel_ids
            result = await self.runtime._dispatch("list_recent_posts", args, tenant, {})
            if not isinstance(result, dict):
                return "", []
            posts = result.get("posts") or result.get("items") or result.get("data") or []
            lines: list[str] = []
            if isinstance(posts, list):
                for i, post in enumerate(posts[:10]):
                    if not isinstance(post, dict):
                        continue
                    caption = str(post.get("caption") or post.get("text") or post.get("message") or "")[:400]
                    lines.append(f"- {caption}" if caption else f"- post#{i+1}")
                    for key in ("media_url", "image_url", "thumbnail_url", "full_picture", "permalink_url"):
                        url = str(post.get(key) or "").strip()
                        if url.startswith("http"):
                            media.append({"url": url, "label": f"recent_post_{i+1}"})
                            break
                    attachments = post.get("media") or post.get("attachments") or []
                    if isinstance(attachments, list):
                        for att in attachments[:2]:
                            if isinstance(att, dict):
                                u = str(att.get("url") or att.get("media_url") or "").strip()
                                if u.startswith("http"):
                                    media.append({"url": u, "label": f"recent_post_{i+1}_media"})
            text = "\n".join(lines)
            if not text:
                # Laravel may return a plain context string
                raw = result.get("context") or result.get("text") or result.get("message")
                text = str(raw)[:4000] if raw else json.dumps(result, ensure_ascii=False)[:3000]
        except Exception as exc:
            logger.info("post_crafter.list_recent_posts skipped: %s", exc)
        return text, media

    async def _identity_and_memories(
        self,
        *,
        business_id: int,
        focus: str,
        tenant: dict[str, Any],
        model: str | None,
    ) -> str:
        bits: list[str] = []
        try:
            mem_hits = await self.runtime.knowledge.search(
                business_id=business_id,
                query=(focus or "campaign preferences tone products")[:400],
                namespace="memories",
                top_k=6,
            )
            for h in mem_hits:
                if h.get("content"):
                    bits.append(f"[memories] {h['content']}")
        except Exception as exc:
            logger.info("post_crafter.memories skipped: %s", exc)

        try:
            brand_hits = await self.runtime.knowledge.search(
                business_id=business_id,
                query=(focus or "shop brand tone field")[:400],
                namespace=None,
                top_k=8,
            )
            for h in brand_hits:
                if h.get("content"):
                    ns = h.get("namespace") or "brand"
                    bits.append(f"[{ns}] {h['content']}")
        except Exception as exc:
            logger.info("post_crafter.brand RAG skipped: %s", exc)

        try:
            if default_registry.get("business_identity") or default_registry.has_tool("ask_identity_agent"):
                hop = await default_registry.invoke(
                    "ask_identity_agent",
                    {"question": f"Brand field, tone, and products relevant to: {(focus or '')[:500]}"},
                    {**tenant, "llm_model": model, "surface": "post_crafter"},
                )
                answer = str(hop.get("answer") or "").strip()
                if answer:
                    bits.append(f"[identity] {answer}")
        except Exception as exc:
            logger.info("post_crafter.identity skipped: %s", exc)

        return "\n".join(bits)

    @staticmethod
    def _format_analyses(rows: list[dict[str, Any]], heading: str) -> str:
        if not rows:
            return f"{heading}: (none)"
        lines = [f"{heading}:"]
        for i, row in enumerate(rows, 1):
            lines.append(f"{i}. [{row.get('label') or 'img'}] {row.get('description') or ''}")
        return "\n".join(lines)

    def _parse_plan(self, raw: str, focus: str) -> dict[str, Any]:
        text = (raw or "").strip()
        if text.startswith("```"):
            text = re.sub(r"^```(?:json)?\s*", "", text)
            text = re.sub(r"\s*```$", "", text)
        data: dict[str, Any] = {}
        try:
            data = json.loads(text)
        except json.JSONDecodeError:
            m = re.search(r"\{[\s\S]*\}", text)
            if m:
                try:
                    data = json.loads(m.group(0))
                except json.JSONDecodeError:
                    data = {}

        caption = str(data.get("tease_caption") or data.get("caption") or "").strip()
        if not caption:
            caption = text[:800] if text and not text.startswith("{") else (
                f"New drop for our community — {(focus or 'our bestsellers')[:120]}. DM us."
            )

        tags_raw = data.get("hashtags") if isinstance(data.get("hashtags"), list) else []
        tags: list[str] = []
        for t in tags_raw[:5]:
            tag = str(t).strip()
            if not tag:
                continue
            tags.append(tag if tag.startswith("#") else f"#{tag}")

        return {
            "analysis": str(data.get("analysis") or "").strip(),
            "plan_notes": str(data.get("plan_notes") or "").strip(),
            "tease_caption": caption,
            "title": str(data.get("title") or "").strip()[:120],
            "hashtags": tags,
            "image_prompt": str(data.get("image_prompt") or "Clean Maghreb shop social creative matching the caption").strip(),
            "product_focus": str(data.get("product_focus") or "").strip(),
            "multi_product": bool(data.get("multi_product")),
        }

    @staticmethod
    def _merge_usage(acc: dict[str, Any], extra: dict[str, Any]) -> None:
        if not isinstance(extra, dict):
            return
        for key in ("prompt_tokens", "completion_tokens", "fal_calls", "calls_with_cost"):
            acc[key] = int(acc.get(key) or 0) + int(extra.get(key) or 0)
        acc["cost_usd"] = float(acc.get("cost_usd") or 0) + float(extra.get("cost_usd") or 0)
