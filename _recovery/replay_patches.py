"""Replay Write + StrReplace ops from transcript to reconstruct latest test files."""
import json
from pathlib import Path

transcript = Path(
    r"C:\Users\smartech_biskra\.cursor\projects\c-ai-chatbot\agent-transcripts"
    r"\c280fa40-02e7-4b97-915c-7c049d8f9e6c\c280fa40-02e7-4b97-915c-7c049d8f9e6c.jsonl"
)
out_dir = Path(r"c:\ai_chatbot\_recovery")
out_dir.mkdir(exist_ok=True)

targets = {
    r"c:\ai_chatbot\ai-agent-platform\tests\Feature\ImageModelBillingTest.php": None,
    r"c:\ai_chatbot\ai-agent-platform\tests\Feature\LlmMcpGateTest.php": None,
}

ops = []
with transcript.open(encoding="utf-8", errors="replace") as f:
    for lineno, line in enumerate(f, 1):
        try:
            data = json.loads(line)
        except Exception:
            continue
        msg = data.get("message", data)
        content = msg.get("content") if isinstance(msg, dict) else None
        if not isinstance(content, list):
            continue
        for part in content:
            if not isinstance(part, dict) or part.get("type") != "tool_use":
                continue
            name = part.get("name")
            inp = part.get("input") or {}
            path = inp.get("path")
            if path not in targets:
                continue
            if name == "Write" and "contents" in inp:
                ops.append((lineno, "Write", path, inp["contents"], None))
            elif name == "StrReplace":
                ops.append((lineno, "StrReplace", path, inp.get("new_string"), inp.get("old_string")))

print(f"ops: {len(ops)}")
files = {p: None for p in targets}
failed = []
for lineno, kind, path, new, old in ops:
    if kind == "Write":
        files[path] = new
        print(f"L{lineno} Write {Path(path).name} len={len(new)}")
    elif kind == "StrReplace":
        cur = files[path]
        if cur is None:
            failed.append((lineno, path, "no base"))
            print(f"L{lineno} FAIL no base for {Path(path).name}")
            continue
        if old not in cur:
            # try once with normalize newlines
            if old and old.replace("\r\n", "\n") in cur.replace("\r\n", "\n"):
                cur = cur.replace("\r\n", "\n")
                old = old.replace("\r\n", "\n")
                new = new.replace("\r\n", "\n") if new else new
            else:
                failed.append((lineno, path, "old_string missing"))
                print(f"L{lineno} FAIL old_string missing in {Path(path).name} old_len={len(old or '')}")
                continue
        files[path] = cur.replace(old, new, 1)
        print(f"L{lineno} StrReplace OK {Path(path).name} -> len={len(files[path])}")

for path, content in files.items():
    if content is None:
        print("NO CONTENT", path)
        continue
    name = Path(path).name
    out = out_dir / f"recovered_{name}"
    out.write_text(content, encoding="utf-8")
    print(f"wrote {out} ({len(content)} bytes, {content.count(chr(10))+1} lines)")
    # sanity
    print("  has namespace Tests\\Feature:", "namespace Tests\\Feature" in content)
    print("  has class:", "class ImageModelBillingTest" in content or "class LlmMcpGateTest" in content)
    print("  first line:", content.splitlines()[0] if content else None)

print("failed count:", len(failed))
for item in failed:
    print("  ", item)
