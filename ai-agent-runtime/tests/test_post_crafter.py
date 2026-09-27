from __future__ import annotations

import pytest

from app.agents.post_crafter import PostCrafterAgent
from app.agents.post_crafter_approver import PostCrafterApprover


@pytest.mark.asyncio
async def test_post_crafter_plan_fills_plan_meta(monkeypatch):
    agent = PostCrafterAgent()

    async def fake_analyze(*, image_url: str, label: str = "", model: str | None = None):
        return {
            "ok": True,
            "url": image_url,
            "label": label,
            "description": "White sneakers on marble, single SKU, lifestyle vibe.",
            "usage": {"prompt_tokens": 2, "completion_tokens": 4},
        }

    async def fake_identity(**kwargs):
        return "[identity] Shoe boutique in Algiers, Darija tone."

    async def fake_fetch(**kwargs):
        return ("", [])

    async def fake_chat(messages, **kwargs):
        return {
            "content": """{
              "analysis": "Single product sneakers drop",
              "plan_notes": "Lifestyle carousel then story CTA",
              "tease_caption": "Sneakers weekend ready — DM us.",
              "title": "Weekend sneakers",
              "hashtags": ["#sneakers", "#algiers"],
              "image_prompt": "Clean product shot sneakers",
              "product_focus": "white sneakers",
              "multi_product": false
            }""",
            "usage": {"prompt_tokens": 10, "completion_tokens": 20},
        }

    async def fake_review(**kwargs):
        return {
            "decision": "approved",
            "score": 0.9,
            "reasons": ["vision_grounded"],
            "feedback": "",
            "approved": True,
        }

    monkeypatch.setattr(agent, "analyze_image", fake_analyze)
    monkeypatch.setattr(agent, "_identity_and_memories", fake_identity)
    monkeypatch.setattr(agent, "_fetch_recent_posts", fake_fetch)
    monkeypatch.setattr(agent.llm, "chat_completion", fake_chat)
    monkeypatch.setattr(agent.approver, "review", fake_review)

    out = await agent.plan(
        tenant={"business_id": 7},
        focus="Promote white sneakers",
        content_mode="product_images",
        image_urls=[{"url": "https://cdn.example/p.jpg", "label": "asset_1"}],
    )

    assert out["tease_caption"].startswith("Sneakers")
    assert out["plan_meta"]["product_focus"] == "white sneakers"
    assert len(out["plan_meta"]["image_analyses"]) == 1
    assert out["plan_meta"]["image_analyses"][0]["ok"] is True
    assert out["approved"] is True
    assert out["agents"]["planner"] == "PostCrafterAgent"


@pytest.mark.asyncio
async def test_post_crafter_understand_no_tease(monkeypatch):
    agent = PostCrafterAgent()

    async def fake_analyze(*, image_url: str, label: str = "", model: str | None = None, timeout_s: float = 60.0):
        return {
            "ok": True,
            "url": image_url,
            "label": label,
            "description": "Mobile Legends Weekly Pass flash sale, 1100 DZD.",
            "usage": {"prompt_tokens": 1, "completion_tokens": 2},
        }

    async def fake_chat(messages, **kwargs):
        return {
            "content": (
                '{"summary":"Weekly Pass flash sale on the creative.",'
                '"product_focus":"Weekly Pass","multi_product":false,'
                '"process_questions":[{"id":"lead_weekly","text":"Lead the campaign with Weekly Pass","default_on":true}]}'
            ),
            "usage": {"prompt_tokens": 3, "completion_tokens": 5},
        }

    async def fake_filter(**kwargs):
        return [
            {
                "id": "lead_weekly",
                "text": "Lead the campaign with Weekly Pass",
                "group": "process",
                "required": True,
                "default_on": True,
            }
        ]

    monkeypatch.setattr(agent, "analyze_image", fake_analyze)
    monkeypatch.setattr(agent.llm, "chat_completion", fake_chat)
    monkeypatch.setattr(agent.approver, "filter_process_cards", fake_filter)

    out = await agent.understand(
        tenant={"business_id": 11, "reply_language": "darija"},
        content_mode="product_images",
        image_urls=[{"url": "https://cdn.example/w.jpg", "label": "a1"}],
    )
    assert "Weekly Pass" in out["summary"]
    assert out["product_focus"] == "Weekly Pass"
    assert "tease_caption" not in out
    assert len(out["image_analyses"]) == 1
    assert out["claims"] == []
    assert len(out["process_options"]) == 1
    assert out["process_options"][0]["group"] == "process"


def test_post_crafter_approver_parse_reject():
    approver = PostCrafterApprover()
    parsed = approver._parse(
        '{"decision":"rejected","score":0.2,"reasons":["invented_price"],"feedback":"Remove price"}'
    )
    assert parsed["approved"] is False
    assert "invented_price" in parsed["reasons"]
