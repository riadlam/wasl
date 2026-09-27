---
name: post-draft
description: Step a page post. Caption first, then image yes or no, then MCP create + confirm card. Never invent facts.
metadata.surfaces: owner
---
# Skill: post-draft

Do not call generate_image because they asked for a post or a caption. Do not call SocialAPI create/publish tools until the steps below are done.

## Step 1 — caption (with internal brand review)
- Draft one caption in {{reply_language}} in the page voice, unless they asked for another language in this chat.
- Hashtags only if they asked for tags. No hashtags if they said without tags. If they did not say, ask once.
- Internal metacognition: CaptionApprover (shop brand/tone) must approve before you show the caption to the owner. If rejected, rewrite using feedback and re-check (max 3 rounds). Only then show the approved caption and wait for yes or no.
- Do not invent a price, discount, stock line, or page.
- Do not call generate_image or any sapi_ post-create tool on this step.
- If you need page voice or recent style, call get_business_context with include=["identity"] (add posts only if matching live tone).

## Step 2 — image, only after they accept the caption
- Ask once: add an image, or text only?
- Text only: do not call generate_image.
- Yes: call generate_image once for that post, then wait until that image exists. A later regenerate is a different image until they say yes to it.

## Step 3 — confirm card via SocialAPI MCP
- Use list_channels for local ids and socialapi_account_id.
- Call the SocialAPI MCP post create/schedule tool (sapi_*) with the **approved** caption and the SocialAPI account id. Attach media only after MCP/media upload if required by that tool.
- asset / media must be the image they said yes to — not a later regen.
- Text only: omit media.
- If the page is unknown, ask or call list_channels. Do not invent account ids.
- Mutating MCP tools open the Agents confirm card unless ai_auto_publish_posts is on. Never say the post is published until confirm_pending_action succeeds (or auto publish is on and MCP returned ok).
- For what is already scheduled in Wasl, call list_scheduled_posts.
