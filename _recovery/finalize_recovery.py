from pathlib import Path

def a_to_s(s: str) -> str:
    return s.replace("a", "s")


def finalize(recovered: str, corrupted: str, extras: list[tuple[str, str]]) -> str:
    fixed = recovered
    for old, new in extras:
        fixed = fixed.replace(old, new)
    if a_to_s(fixed) != corrupted:
        # show remaining diffs
        for i, (a, b) in enumerate(zip(a_to_s(fixed).splitlines(), corrupted.splitlines()), 1):
            if a != b:
                print(f"STILL L{i}")
                print(" TR ", a)
                print(" COR", b)
        raise SystemExit("mismatch")
    return fixed


out_dir = Path(r"c:\ai_chatbot\_recovery")

img_rec = (out_dir / "recovered_ImageModelBillingTest.php").read_text(encoding="utf-8")
img_cor = Path(
    r"c:\ai_chatbot\ai-agent-platform\tests\Feature\ImageModelBillingTest.php"
).read_text(encoding="utf-8")

img_extras = [
    ("$owner->update(['wallet_tokens' => 100]);", "$owner->update(['wallet_balance_ds' => 200]);"),
    ("$owner->update(['wallet_tokens' => 50]);", "$owner->update(['wallet_balance_ds' => 50]);"),
    (
        "$owner->update(['wallet_tokens' => 100, 'wallet_currency' => 'DZD']);",
        "$owner->update(['wallet_balance_ds' => 200, 'wallet_currency' => 'DZD']);",
    ),
    ("$owner->update(['wallet_tokens' => 0]);", "$owner->update(['wallet_balance_ds' => 0]);"),
    ("$owner->update(['wallet_tokens' => 200]);", "$owner->update(['wallet_balance_ds' => 200]);"),
    ("->wallet_tokens", "->wallet_balance_ds"),
    ("'wallet.tokens'", "'wallet.balance_ds'"),
    ("'tokens' => 14,", "'amount_da' => 14,"),
    ("'tokens' => 25,", "'amount_da' => 25,"),
    # User factory / migration simulation parts may already use wallet_balance_ds in corrupted
    ("'wallet_tokens' => 4,", "'wallet_balance_ds' => 4,"),
    ("ROUND(wallet_tokens * 25, 2)", "ROUND(wallet_balance_ds * 25, 2)"),
    ("'wallet_tokens' =>", "'wallet_balance_ds' =>"),
]

# Check which of wallet_tokens remain in recovered before replacements related to migration block
print("wallet_tokens occurrences in recovered Image:", img_rec.count("wallet_tokens"))
for i, line in enumerate(img_rec.splitlines(), 1):
    if "wallet_tokens" in line or "'tokens'" in line or "wallet.tokens" in line:
        print(f"  L{i}: {line}")

img_final = finalize(img_rec, img_cor, img_extras)
(out_dir / "final_ImageModelBillingTest.php").write_text(img_final, encoding="utf-8")
print("OK ImageModelBillingTest", len(img_final))

gate_rec = (out_dir / "recovered_LlmMcpGateTest.php").read_text(encoding="utf-8")
gate_cor = Path(
    r"c:\ai_chatbot\ai-agent-platform\tests\Feature\LlmMcpGateTest.php"
).read_text(encoding="utf-8")

gate_extras = [
    ("$owner->update(['wallet_tokens' => 100]);", "$owner->update(['wallet_balance_ds' => 200]);"),
    ("->wallet_tokens", "->wallet_balance_ds"),
]
gate_final = finalize(gate_rec, gate_cor, gate_extras)
(out_dir / "final_LlmMcpGateTest.php").write_text(gate_final, encoding="utf-8")
print("OK LlmMcpGateTest", len(gate_final))
