import json
from pathlib import Path

paths = [
    Path(r"C:\Users\smartech_biskra\.cursor\projects\c-ai-chatbot\agent-transcripts\c280fa40-02e7-4b97-915c-7c049d8f9e6c\subagents\148de043-5dc4-47f4-b68a-568fe909757d.jsonl"),
    Path(r"C:\Users\smartech_biskra\.cursor\projects\c-ai-chatbot\agent-transcripts\c280fa40-02e7-4b97-915c-7c049d8f9e6c\subagents\b2634701-c684-49ac-a5c6-588c4118dca4.jsonl"),
    Path(r"C:\Users\smartech_biskra\.cursor\projects\c-ai-chatbot\agent-transcripts\c280fa40-02e7-4b97-915c-7c049d8f9e6c\subagents\708cda43-bef1-4ef3-9fcc-816853d86f37.jsonl"),
    Path(r"C:\Users\smartech_biskra\.cursor\projects\c-ai-chatbot\agent-transcripts\c280fa40-02e7-4b97-915c-7c049d8f9e6c\c280fa40-02e7-4b97-915c-7c049d8f9e6c.jsonl"),
]

out_dir = Path(r"c:\ai_chatbot\_recovery")
out_dir.mkdir(exist_ok=True)

markers = ("class ImageModelBillingTest", "class LlmMcpGateTest")
file_hints = ("ImageModelBillingTest.php", "LlmMcpGateTest.php")

for p in paths:
    if not p.exists():
        print("MISSING", p)
        continue
    print("===", p.name, "size", p.stat().st_size)
    hits = []
    with p.open(encoding="utf-8", errors="replace") as f:
        for lineno, line in enumerate(f, 1):
            try:
                data = json.loads(line)
            except Exception:
                continue
            msg = data.get("message", data)
            content = msg.get("content") if isinstance(msg, dict) else None
            if content is None:
                continue
            parts = content if isinstance(content, list) else [{"type": "text", "text": content}]
            for part in parts:
                if not isinstance(part, dict):
                    continue
                name = part.get("name") or part.get("type")
                text = part.get("text")
                if isinstance(text, str):
                    for m in markers:
                        if m in text:
                            hits.append((f"L{lineno}:{name}:text:{m}", text))
                inp = part.get("input")
                if isinstance(inp, dict):
                    path_hint = str(inp.get("path", ""))
                    for key in ("contents", "new_string", "old_string"):
                        val = inp.get(key)
                        if not isinstance(val, str) or len(val) < 80:
                            continue
                        relevant = any(h in path_hint for h in file_hints) or any(m in val for m in markers)
                        if relevant:
                            hits.append((f"L{lineno}:{name}:{key}:{path_hint}", val))
    print("hits", len(hits))
    for i, (loc, blob) in enumerate(hits):
        print(f"  [{i}] {loc} len={len(blob)}")
        # save large recoverable blobs
        if len(blob) > 500 and ("class ImageModelBillingTest" in blob or "class LlmMcpGateTest" in blob or "nsmespsce" in blob or "namespace Tests" in blob):
            safe = loc.replace(":", "_").replace("\\", "_").replace("/", "_")[:120]
            out = out_dir / f"{p.stem}_{i}_{safe}.txt"
            out.write_text(blob, encoding="utf-8")
            print("    saved", out.name)

print("done")
