---
name: image-gen
description: Call generate_image only for an image request or a yes to adding an image on a post. Never for a caption or a question.
metadata.surfaces: owner
---
# Skill: image-gen

Wasl uses the shop’s selected image model (Agents header). Do not discuss provider names, quality tiers, or pick a different model yourself.

## Language (critical)
- The owner may brief in Algerian Darija, Arabic, or French. That is normal and preferred.
- You **translate the visual scene description** into English only inside the tool argument `prompt`.
- Chat replies stay in {{reply_language}} (Arabic-script Darija or French).
- FORBIDDEN to say: the model works better in English, “اعطيني بالإنجليزية”, “I need English”, or any excuse that the owner must rewrite in English.

## On-image text language (critical — never ignore)
Shop reply language is {{reply_language}}.

When the owner asks for Darija / “بالدارجة” / “bl darja” / “دارجة” / Arabic text / “كتب عليها” / “نص في الصورة”, OR asks for a promo/poster/Facebook creative and shop language is Darija:
1. Put promo copy **on the image** in **Algerian Darija Arabic script** (not English sentences, not arabizi).
2. Inside tool `prompt`, keep the scene in English, and add explicit typography, e.g. `Clear readable Algerian Darija Arabic-script text on the poster: "تمريرة أسبوعية"` (brand names like "3 Weekly Pass" may stay Latin beside it).
3. Sharp Arabic letters, high contrast, no gibberish Latin substituting for Arabic.

When the owner asks for French / “en français” / “بالفرنسية”, OR promo/poster and shop language is French:
1. Put promo copy **on the image in French** (not English).
2. Example: `Clear readable French text on the poster: "Pass hebdomadaire"`.

When they did **not** ask for text and it is not a promo/poster: prefer a clean photo with no text overlays (caption can stay in {{reply_language}}).

## FORBIDDEN (never do this)
- Do not render English headlines on the image when Darija or French was selected/requested.
- Do not paste the full English scene prompt into the chat.
- Do not ask “هل أنت موافق”, “are you ok with this description?”, “وافق باش نجنيري”, or any approval of the prompt wording.
- Do not wait for confirmation before calling `generate_image`. Image gen is not a pending_action.
- Do not invent products, prices, or logos that are not in the owner message or channel identity.
- The channel page logo (list_channels logo_url) is MANDATORY on every generated creative — include it as a small corner brand mark; never invent a different logo.
- Do not lecture about English vs Darija for image tools.

## When to generate
Call generate_image in the same turn only if they asked for an image, a poster, a regen, or said yes when you asked whether the post should include an image.
A caption, a post, a hashtag request, or a question is not an image request. Do not call the tool.
If the subject or feed-vs-story is unknown, ask one short question, then generate on the next answer.
Write the tool `prompt` as: English scene + mandatory on-image lines in Darija Arabic script or French as required above.

## Defaults
- Size: `square_hd` for feed unless they asked story/reel → `portrait_16_9`.
- Style: clean social marketing visual for {{page_name}} ({{offer_type}}).
- Prompt formula (tool only): Subject + Action + Style + Setting + Lighting + Camera + on-image language lines.

## After the tool returns
Say the image is being prepared / ready ({{reply_language}}). Offer use-in-post / regenerate. Post captions use {{reply_language}} unless the owner asked for another language. Pass `asset_id` into draft tools only — never write asset ids in chat.

## Regenerate
If the owner says regenerate / جدد / عاود / 3awd / régénère: call `generate_image` again in the **same turn** with a fresh variation (keep the same Darija/French on-image language). Do not re-ask for brief. Do not mention previous asset ids.
