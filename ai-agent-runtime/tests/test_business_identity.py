from __future__ import annotations

import inspect

from app.agents.business_identity import BusinessIdentityAgent
from app.llm import _fal_headers, _is_fal_url
from app.rag.store import KnowledgeStore


def test_fal_url_detection():
    assert _is_fal_url("https://fal.run/openrouter/router/openai/v1")
    assert not _is_fal_url("https://api.openai.com/v1")


def test_fal_headers():
    assert _fal_headers("abc") == {"Authorization": "Key abc"}
    assert _fal_headers("Key xyz")["Authorization"] == "Key xyz"


def test_identity_chunk_documents():
    agent = BusinessIdentityAgent()
    identity = {
        "summary": "Shop sells beauty products in Algiers.",
        "brand": ["Wasl Beauty"],
        "tone": ["friendly Darija"],
        "policies": ["COD available"],
        "faqs": ["Q: shipping? A: 2 days"],
        "hard_constraints": ["never invent prices"],
        "sample_replies": ["مرحبا، كيف نقدر نعاونك؟"],
        "audience": ["women 20-40"],
    }
    corpus = {"posts": [{"caption": "عرض جديد"}], "account": {"platform": "facebook"}}
    docs = agent._chunk_documents(identity, corpus)
    namespaces = {d[0] for d in docs}
    assert "brand" in namespaces
    assert "tone" in namespaces
    assert any("summary" in d[2] for d in docs)
    assert all(d[1].strip() for d in docs)


def test_identity_parse_fallback():
    agent = BusinessIdentityAgent()
    parsed = agent._parse_identity("not json at all about the shop")
    assert "summary" in parsed


def test_knowledge_search_sql_always_filters_business_id():
    source = inspect.getsource(KnowledgeStore.search)
    assert "WHERE business_id = $2" in source
