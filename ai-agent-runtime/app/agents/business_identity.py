from __future__ import annotations

import json
import logging
import re
from typing import Any

from app.llm import LlmClient
from app.middleware.agent_activity import activity
from app.rag.store import KnowledgeStore

logger = logging.getLogger(__name__)

IDENTITY_SYSTEM = """You are BusinessIdentityAgent for a Maghreb commerce SaaS (Wasl).
Given page posts, comments, and DMs from onboarding training, synthesize a grounded business identity.
Return ONLY valid JSON with these keys (strings or arrays of short strings):
{
  "summary": "2-4 sentence identity overview",
  "brand": ["facts about the shop/brand"],
  "tone": ["voice and language style notes"],
  "audience": ["who they sell to"],
  "policies": ["delivery, returns, payments, hours if evidenced"],
  "faqs": ["likely Q&A pairs from corpus"],
  "hard_constraints": ["never invent X; always do Y"],
  "sample_replies": ["short example reply patterns in shop language"]
}
Use only evidence from the corpus. If unknown, omit or mark as unknown — never invent prices.
Prefer Darija/French notes when the corpus uses them.
"""

CONSULT_SYSTEM = """You are BusinessIdentityAgent answering another Wasl AI agent about THIS shop only.
Use ONLY the provided evidence snippets from the shop's identity vectors.
Never invent prices, stock, delivery fees, policies, or product categories not in evidence.

Voice for peer agents (critical):
- Write facts in FIRST PERSON as the shop: "we sell", "we offer", "our site", "عندنا", "نقدر نشحن".
- NEVER say "this shop sells", "they offer", "their website", or narrate the brand name in third person ("عندهم", "موقعهم").
- Peer CustomerAgent will paste your answer into a customer DM — third person sounds like a stranger reviewing the brand.

If evidence is missing or insufficient, say you do not know and suggest asking the shop owner.
When asked about posts/captions/style, quote 1-2 short concrete cues from sample posts (hooks, emoji patterns, CTAs).
Reply in the same language as the question when possible. Be concise (2-6 sentences).
"""


class BusinessIdentityAgent:
    """Identity specialist: build (onboarding) + consult (agent-as-tool Q&A over Supabase)."""

    agent_id = "identity"
    description = (
        "Ask BusinessIdentityAgent about this shop's brand, tone, audience, policies, and FAQs. "
        "Prefer this over raw knowledge_search for business-identity questions."
    )
    SOURCE_TYPE = "channel_identity"
    CONSULT_NAMESPACES = ("brand", "tone", "policies", "faqs", "posts")
    # Campaign brief only needs style + samples — fewer DB round-trips after one embed.
    BRIEF_CONSULT_NAMESPACES = ("brand", "tone", "posts")

    def __init__(self, llm: LlmClient | None = None, knowledge: KnowledgeStore | None = None) -> None:
        self.llm = llm or LlmClient()
        self.knowledge = knowledge or KnowledgeStore(llm=self.llm)

    async def build(
        self,
        *,
        business_id: int,
        social_account_id: int,
        corpus: dict[str, Any],
        model: str | None = None,
    ) -> dict[str, Any]:
        with activity().turn(
            "BusinessIdentityAgent",
            business_id=business_id,
            extra={"mode": "build", "social_account_id": social_account_id},
        ):
            activity().input(
                "identity build corpus summary",
                {
                    "counts": corpus.get("counts") or corpus.get("corpus_counts"),
                    "account": corpus.get("account"),
                    "known_facts": corpus.get("known_facts"),
                    "posts": len(corpus.get("posts") or []),
                    "comments": len(corpus.get("comments") or []),
                    "dms": len(corpus.get("dms") or []),
                },
            )
            identity, usage = await self._synthesize(corpus, model=model)
            activity().output("identity synthesized (in-memory)", identity)
            docs = self._chunk_documents(identity, corpus)
            total = 0
            namespaces: list[str] = []
            for namespace, content, source_suffix in docs:
                if not content.strip():
                    continue
                source_id = f"{social_account_id}:{source_suffix}"
                count, embed_usage = await self.knowledge.upsert_document(
                    business_id=business_id,
                    namespace=namespace,
                    source_type=self.SOURCE_TYPE,
                    source_id=source_id,
                    content=content,
                    metadata={
                        "social_account_id": social_account_id,
                        "platform": (corpus.get("account") or {}).get("platform"),
                        "storage": "supabase",
                    },
                )
                total += count
                usage = self._merge_usage(usage, embed_usage)
                if namespace not in namespaces:
                    namespaces.append(namespace)
                activity().section(
                    f"upsert chunk ns={namespace} id={source_id}",
                    content[:1500],
                    kind="RAG-WRITE",
                )

            out = {
                "ok": True,
                "business_id": business_id,
                "social_account_id": social_account_id,
                "chunks_upserted": total,
                "namespaces": namespaces,
                "summary": str(identity.get("summary") or ""),
                "storage": "supabase",
                "usage": usage,
            }
            activity().output("identity build result", out)
            return out

    async def consult(
        self,
        question: str,
        *,
        business_id: int,
        context: dict[str, Any] | None = None,
        model: str | None = None,
    ) -> dict[str, Any]:
        """Answer another agent about this shop using Supabase identity vectors only."""
        q = (question or "").strip()
        activity().input(
            "BusinessIdentityAgent consult (question from peer agent)",
            {"question": q, "business_id": business_id, "context": context or {}},
        )
        if not q:
            out = {"ok": False, "error": "empty_question", "answer": "", "unknown": True}
            activity().agent_answer("BusinessIdentityAgent", "(empty question)", title="BusinessIdentityAgent ANSWER")
            return out

        ctx = context or {}
        social_account_id = ctx.get("social_account_id")
        citations: list[dict[str, Any]] = []
        namespaces_used: list[str] = []

        surface = str(ctx.get("surface") or ctx.get("from_surface") or "").lower()
        namespaces = (
            self.BRIEF_CONSULT_NAMESPACES
            if surface in ("campaign_brief", "campaign_tease", "owner_brief")
            else self.CONSULT_NAMESPACES
        )

        # One embedding for all namespaces (was N embeds = ~40s+ on brief).
        used_multi = False
        search_multi = getattr(self.knowledge, "search_namespaces", None)
        if callable(search_multi):
            try:
                raw = await search_multi(
                    business_id=business_id,
                    query=q,
                    namespaces=namespaces,
                    top_k_per_ns=3,
                )
                if isinstance(raw, list):
                    used_multi = True
                    for hit in raw:
                        if isinstance(hit, dict):
                            citations.append(hit)
                            ns = str(hit.get("namespace") or "")
                            if ns and ns not in namespaces_used:
                                namespaces_used.append(ns)
            except Exception as exc:
                logger.warning("identity search_namespaces failed, falling back: %s", exc)

        if not used_multi:
            for ns in namespaces:
                hits = await self.knowledge.search(
                    business_id=business_id,
                    query=q,
                    namespace=ns,
                    top_k=3,
                )
                if hits:
                    namespaces_used.append(ns)
                for hit in hits:
                    if isinstance(hit, dict):
                        citations.append(hit)

        if len(citations) < 2:
            broad = await self.knowledge.search(
                business_id=business_id,
                query=q,
                namespace=None,
                top_k=6,
            )
            for hit in broad:
                if isinstance(hit, dict) and hit not in citations:
                    citations.append(hit)

        # Prefer chunks for this channel when social_account_id is known.
        if social_account_id is not None:
            sid = str(social_account_id)
            preferred = [
                h
                for h in citations
                if sid in str(h.get("source_id") or "")
                or str((h.get("metadata") or {}).get("social_account_id") or "") == sid
            ]
            if preferred:
                citations = preferred + [h for h in citations if h not in preferred]

        citations = citations[:8]
        evidence_lines = []
        for i, hit in enumerate(citations, start=1):
            content = str(hit.get("content") or "").strip()
            if not content:
                continue
            ns = hit.get("namespace") or "?"
            evidence_lines.append(f"[{i}] ({ns}) {content[:800]}")

        if not evidence_lines:
            out = {
                "ok": True,
                "unknown": True,
                "answer": (
                    "I do not have enough identity evidence in Supabase for this shop yet. "
                    "Ask the shop owner, or wait until channel identity training finishes."
                ),
                "citations": [],
                "namespaces_used": namespaces_used,
            }
            activity().agent_answer(
                "BusinessIdentityAgent",
                out["answer"],
                title="BusinessIdentityAgent ANSWER (unknown / no vectors)",
            )
            return out

        evidence_block = "\n".join(evidence_lines)
        result = await self.llm.chat_completion(
            [
                {"role": "system", "content": CONSULT_SYSTEM},
                {
                    "role": "user",
                    "content": (
                        f"Question from peer agent ({ctx.get('from_agent') or 'unknown'}):\n{q}\n\n"
                        f"Evidence:\n{evidence_block}"
                    ),
                },
            ],
            model=model,
            temperature=0.1,
        )
        answer = str(result.get("content") or "").strip()
        if not answer:
            answer = "I could not form a grounded answer from the available identity evidence."

        out = {
            "ok": True,
            "unknown": False,
            "answer": answer,
            "citations": [
                {
                    "namespace": h.get("namespace"),
                    "content": h.get("content"),
                    "score": h.get("score"),
                    "source_id": h.get("source_id"),
                }
                for h in citations
            ],
            "namespaces_used": namespaces_used or list({str(h.get("namespace")) for h in citations if h.get("namespace")}),
        }
        activity().agent_answer(
            "BusinessIdentityAgent",
            answer,
            title="BusinessIdentityAgent ANSWER → peer agent",
        )
        return out

    async def _synthesize(self, corpus: dict[str, Any], model: str | None = None) -> tuple[dict[str, Any], dict[str, Any]]:
        compact = self._compact_corpus(corpus)
        result = await self.llm.chat_completion(
            [
                {"role": "system", "content": IDENTITY_SYSTEM},
                {"role": "user", "content": json.dumps(compact, ensure_ascii=False)[:120000]},
            ],
            model=model,
            temperature=0.15,
        )
        raw_usage = result.get("usage") or {}
        usage = {
            "prompt_tokens": int(raw_usage.get("prompt_tokens") or 0),
            "completion_tokens": int(raw_usage.get("completion_tokens") or 0),
            "cost_usd": float(raw_usage.get("cost_usd") or raw_usage.get("cost") or 0),
            "fal_calls": 1,
            "calls_with_cost": 1 if float(raw_usage.get("cost_usd") or raw_usage.get("cost") or 0) > 0 else 0,
        }
        return self._parse_identity(result.get("content") or ""), usage

    @staticmethod
    def _merge_usage(into: dict[str, Any], extra: dict[str, Any] | None) -> dict[str, Any]:
        if not extra:
            return into
        out = dict(into)
        for key in ("prompt_tokens", "completion_tokens", "fal_calls", "calls_with_cost"):
            out[key] = int(out.get(key) or 0) + int(extra.get(key) or 0)
        out["cost_usd"] = float(out.get("cost_usd") or 0) + float(extra.get("cost_usd") or 0)
        return out

    def _compact_corpus(self, corpus: dict[str, Any]) -> dict[str, Any]:
        posts = []
        for p in (corpus.get("posts") or [])[:20]:
            if isinstance(p, dict):
                posts.append(
                    {
                        "caption": (p.get("caption") or p.get("content") or "")[:500],
                        "title": p.get("title"),
                    }
                )
        comments = []
        for c in (corpus.get("comments") or [])[:40]:
            if isinstance(c, dict):
                comments.append({"content": str(c.get("content") or "")[:300], "author": c.get("author")})
        dms = []
        for d in (corpus.get("dms") or [])[:20]:
            if isinstance(d, dict):
                msgs = d.get("messages") if isinstance(d.get("messages"), list) else []
                dms.append(
                    {
                        "participant": d.get("participant"),
                        "messages": [str(m)[:200] for m in msgs[:8]],
                    }
                )
        return {
            "known_facts": corpus.get("known_facts") or {},
            "account": corpus.get("account") or {},
            "counts": corpus.get("counts") or corpus.get("corpus_counts") or {},
            "page": corpus.get("page") or [],
            "posts": posts,
            "comments": comments,
            "dms": dms,
        }

    def _chunk_documents(self, identity: dict[str, Any], corpus: dict[str, Any]) -> list[tuple[str, str, str]]:
        """Return list of (namespace, content, source_suffix)."""
        docs: list[tuple[str, str, str]] = []
        summary = str(identity.get("summary") or "").strip()
        if summary:
            docs.append(("brand", f"Business identity summary:\n{summary}", "summary"))

        mapping = [
            ("brand", "brand", "brand"),
            ("tone", "tone", "tone"),
            ("brand", "audience", "audience"),
            ("policies", "policies", "policies"),
            ("faqs", "faqs", "faqs"),
            ("brand", "hard_constraints", "constraints"),
            ("tone", "sample_replies", "sample_replies"),
        ]
        for namespace, key, suffix in mapping:
            value = identity.get(key)
            text = self._as_text(key, value)
            if text:
                docs.append((namespace, text, suffix))

        # Light post evidence for tone matching
        post_bits = []
        for p in (corpus.get("posts") or [])[:8]:
            if isinstance(p, dict):
                cap = str(p.get("caption") or p.get("content") or "").strip()
                if cap:
                    post_bits.append(cap[:400])
        if post_bits:
            docs.append(("posts", "Sample post captions from training:\n" + "\n---\n".join(post_bits), "posts"))

        return docs

    def _as_text(self, key: str, value: Any) -> str:
        if value is None:
            return ""
        if isinstance(value, str):
            return f"{key}:\n{value.strip()}"
        if isinstance(value, list):
            lines = [str(v).strip() for v in value if str(v).strip()]
            if not lines:
                return ""
            return f"{key}:\n- " + "\n- ".join(lines)
        if isinstance(value, dict):
            return f"{key}:\n" + json.dumps(value, ensure_ascii=False)
        return f"{key}:\n{value}"

    def _parse_identity(self, raw: str) -> dict[str, Any]:
        text = raw.strip()
        if text.startswith("```"):
            text = re.sub(r"^```(?:json)?\s*", "", text)
            text = re.sub(r"\s*```$", "", text)
        try:
            data = json.loads(text)
        except json.JSONDecodeError:
            match = re.search(r"\{[\s\S]*\}", text)
            if not match:
                return {"summary": text[:1000], "brand": [text[:500]] if text else []}
            try:
                data = json.loads(match.group(0))
            except json.JSONDecodeError:
                return {"summary": text[:1000], "brand": [text[:500]] if text else []}
        return data if isinstance(data, dict) else {"summary": str(data)}
