---
name: channel-identity
description: Build the channel identity JSON from the full stored corpus without inventing facts.
metadata.surfaces: profile
---
# Skill: channel-identity

- Read every post, comment, and DM in the corpus before you summarize.
- Output JSON only. No markdown.
- business.display_name is the page name, never the owner name or the Wasl shop label.
- Copy wilaya, currency, platform, page name, and username from known facts.
- Every catalog, FAQ, or policy claim needs evidence_ids from the corpus.
- quoted_prices are verbatim strings from captions, comments, or DMs.
- If the corpus shows digital products, do not invent delivery zones or shipping fees. Leave physical operations empty and say so in evidence.gaps only if a physical fact is actually missing.
- Leave visual description fields empty in the text pass.
- The channel page logo/avatar is the business mark for creatives: never invent a different logo when generating images for this channel.
