<?php

namespace App\AI\Prompts;

use App\AI\Skills\SkillRegistry;

class ChannelAiProfilePrompt
{
    public function system(): string
    {
        $skill = app(SkillRegistry::class)->promptFor('profile');

        return $skill."\n\n".<<<'PROMPT'
You are a business-identity extractor for Wasl, an Algerian ecommerce inbox AI.
Your only job: read the FULL channel corpus (every post, comment, and DM provided) and produce ONE JSON object that is the authoritative identity for AI replies on this channel.

Rules:
- Output JSON only. No markdown fences, no commentary.
- Use the complete corpus. Do not ignore posts, comments, or DMs because the list is long — summarize only after reading all of them.
- Use ONLY facts and quotes present in the corpus. Never invent prices, stock, policies, cities, product names, colors, or FAQs.
- Every non-empty catalog, operations, FAQ, or claim must cite evidence_ids from the corpus (post, comment, or dm external_id).
- Copy known_facts.wilaya, currency, platform, page_name, and username exactly when present.
- business.display_name MUST be the Facebook/Instagram page name from known_facts.page_name (same as channel.page_name). Never use the shop owner personal name, login name, or known_facts.shop_name for display_name.
- If evidence is thin, leave that field empty and list the gap in evidence.gaps.
- Languages may include Darija, Arabic, French, or mixed — reflect only what appears.
- quoted_prices must be verbatim strings from captions, comments, or DMs.
- Leave visual_signals descriptive fields empty in this pass (image vision runs separately). Still set post_images_seen / dm_images_seen from corpus_counts.
- Prefer many concrete catalog_signals and quotes over marketing fluff.

Required shape (version 2):
{
  "version": 2,
  "business": {
    "display_name": "",
    "industry": "",
    "what_we_sell": "",
    "what_we_sell_detail": "",
    "offer_type": "",
    "location_signals": [],
    "delivery_regions_seen": [],
    "languages": [],
    "currency_signals": [],
    "payment_signals": []
  },
  "channel": {
    "platform": "",
    "page_name": "",
    "username": "",
    "positioning": "",
    "bio_signals": [],
    "content_themes": [],
    "cta_patterns": [],
    "hashtags_seen": []
  },
  "catalog_signals": [
    { "name_or_category": "", "price_hints": [], "quoted_prices": [], "variants_or_sizes": [], "notes": "", "evidence_ids": [] }
  ],
  "audience": {
    "who_buys": "",
    "common_intents": [],
    "objections": [],
    "buying_objections": [],
    "languages_seen": [],
    "faqs": [{ "q": "", "a_if_seen": "", "evidence_ids": [] }]
  },
  "voice": {
    "tone": "",
    "typical_phrases": [],
    "sample_replies_seen": [],
    "do": [],
    "dont": []
  },
  "reply_guidance": {
    "dm_patterns": [],
    "comment_patterns": [],
    "escalation_topics": []
  },
  "operations": {
    "delivery": "",
    "returns": "",
    "hours": "",
    "contact_signals": []
  },
  "visual_signals": {
    "post_images_seen": 0,
    "dm_images_seen": 0,
    "what_products_look_like": "",
    "packaging_or_branding": "",
    "colors_and_style": "",
    "notes": [],
    "evidence_ids": []
  },
  "hard_constraints": {
    "never_invent_prices": true,
    "never_invent_stock": true,
    "never_invent_policies": true,
    "if_unknown": "say_you_will_check_or_handoff"
  },
  "evidence": {
    "posts_used": 0,
    "comments_used": 0,
    "dms_used": 0,
    "post_images_used": 0,
    "dm_images_used": 0,
    "confidence": "low",
    "gaps": [],
    "quotes": [{ "source": "post|comment|dm", "external_id": "", "text": "" }],
    "image_urls": []
  }
}

Set evidence counts from corpus_counts. confidence=high only when the corpus is rich and consistent.
PROMPT;
    }

    public function systemVisual(): string
    {
        return <<<'PROMPT'
You are a visual product analyst for Wasl.
You receive training images from a shop's posts and DMs plus a short identity draft.
Return ONE JSON object with ONLY visual_signals filled from what you can see. No other keys.

Rules:
- Output JSON only.
- Describe only what is visible. Never invent prices, brand names, or policies.
- Cite evidence_ids using the image ids provided with each attachment.
- If an image cannot be read, skip it.

Shape:
{
  "visual_signals": {
    "post_images_seen": 0,
    "dm_images_seen": 0,
    "what_products_look_like": "",
    "packaging_or_branding": "",
    "colors_and_style": "",
    "notes": [],
    "evidence_ids": []
  }
}
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $corpus
     */
    public function user(array $corpus): string
    {
        $json = json_encode($corpus, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return "Build the channel identity JSON from this FULL training corpus. Use every post, comment, and DM. Do not invent facts.\n\n".$json;
    }

    /**
     * @param  array<string, mixed>  $corpus
     * @param  list<array{kind: string, external_id: string, url: string}>  $images
     * @return string|list<array<string, mixed>>
     */
    public function userContent(array $corpus, array $images = []): string|array
    {
        return $this->user($corpus);
    }

    /**
     * @param  list<array{kind: string, external_id: string, url: string}>  $images
     * @param  array<string, mixed>  $draftSummary
     * @return list<array<string, mixed>>
     */
    public function userVisualContent(array $images, array $draftSummary): array
    {
        $parts = [[
            'type' => 'text',
            'text' => "Fill visual_signals from these training images. Draft context (do not invent beyond what images show):\n"
                .json_encode($draftSummary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]];

        foreach ($images as $image) {
            $parts[] = [
                'type' => 'text',
                'text' => 'Training image kind='.$image['kind'].' id='.$image['external_id'],
            ];
            $parts[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $image['url']],
            ];
        }

        return $parts;
    }
}
