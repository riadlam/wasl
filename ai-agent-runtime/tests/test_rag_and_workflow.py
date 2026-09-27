from __future__ import annotations

from app.rag.store import chunk_text
from app.workflows.publish import PublishWorkflow


def test_chunk_text_splits_with_overlap():
    text = "a" * 1000
    chunks = chunk_text(text, chunk_size=400, overlap=50)
    assert len(chunks) >= 2
    assert all(len(c) <= 400 for c in chunks)


def test_publish_workflow_advances_on_prepare():
    wf = PublishWorkflow()
    session = {"state": {}}
    wf.observe_tools(
        session,
        [{"tool": "prepare_social_post", "result": {"pending_action": {"id": 9}}}],
    )
    assert session["state"]["publish_step"] == "pending_approval"
    assert session["state"]["pending_action_id"] == 9


def test_publish_workflow_instruction_idle():
    wf = PublishWorkflow()
    text = wf.instruction_for_state({})
    assert "idle" in text
