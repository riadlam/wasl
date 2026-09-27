<?php

namespace App\AI\Prompts;

use App\AI\ReplyLanguage;
use App\AI\Skills\SkillRegistry;
use App\Models\AiProfilePerChannel;
use App\Models\Business;
use App\Models\SocialAccount;
use App\Models\User;

class OwnerAgentPrompt
{
    public function system(Business $business, ?User $actor = null, ?string $voiceLang = null): string
    {
        $channels = SocialAccount::query()
            ->where('business_id', $business->id)
            ->where('provider', 'socialapi')
            ->where('platform', '!=', 'simulator')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'disconnected');
            })
            ->get()
            ->map(fn (SocialAccount $a) => [
                'id' => $a->id,
                'platform' => $a->platform,
                'name' => $a->name,
                'username' => $a->username,
                'socialapi_account_id' => $a->socialapi_account_id,
            ])
            ->values()
            ->all();

        $profiles = AiProfilePerChannel::query()
            ->where('business_id', $business->id)
            ->ready()
            ->count();

        $channelsJson = json_encode($channels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $identityHint = $profiles > 0
            ? "Channel identity is stored in Supabase vectors for {$profiles} channel(s). Call get_business_context with include=[\"identity\"] for status + snippets, and knowledge_search (brand/tone/policies/faqs) for deeper facts. Add posts/comments/dms only for live inbox."
            : 'No ready channel identity vectors yet. Ask the owner or run the profile interview; do not invent page facts.';
        $actorName = $actor?->name ?: 'User';
        $replyLanguage = ReplyLanguage::instruction(ReplyLanguage::forBusiness($business));
        $speechHint = $voiceLang
            ? "Speech input hint only (not the reply language): {$voiceLang}."
            : '';
        $interviewBlock = $this->interviewBlock($business);
        $skills = app(SkillRegistry::class);
        $skillBlock = $skills->promptFor('owner', $business, [
            'reply_language' => ReplyLanguage::forBusiness($business),
            'page_name' => (string) $business->name,
            'offer_type' => 'unknown',
        ]);
        $tz = trim((string) ($business->timezone ?: 'Africa/Algiers'));
        $nowLocal = now($tz)->toIso8601String();
        $auto = $business->agentSettings;
        $autoFlags = json_encode([
            'ai_auto_publish_posts' => (bool) ($auto?->ai_auto_publish_posts ?? false),
            'ai_auto_reply_comments' => (bool) ($auto?->ai_auto_reply_comments ?? false),
            'ai_auto_send_dms' => (bool) ($auto?->ai_auto_send_dms ?? false),
            'ai_auto_reply_reviews' => (bool) ($auto?->ai_auto_reply_reviews ?? false),
            'ai_auto_moderate' => (bool) ($auto?->ai_auto_moderate ?? false),
        ], JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
You are Wasl’s shop AI assistant for {$business->name}, chatting with {$actorName} (owner/staff).
{$replyLanguage}
{$speechHint}
Keep replies short and clear.

You help manage channels and content. You have tools. Use them — do not invent results.
Shop timezone: {$tz}. Current local time: {$nowLocal}. Convert relative times ("tomorrow 10am") into ISO8601 using this timezone before calling schedule tools.

Hard rules:
- SocialAPI work uses MCP tools prefixed sapi_. Never invent account, post, comment, or message ids. Mutating tools wait for the chat confirm card unless the shop turned on the matching auto setting.
- AUTO SETTINGS (Settings page + tools): current flags = {$autoFlags}. false = confirm card required; true = that MCP write runs immediately. Call get_agent_auto_settings to refresh. When the owner asks to turn publish/comments/DMs/reviews auto on or off, call update_agent_auto_settings — do not invent that a flag changed.
- CONTEXT: Do not assume full identity in this prompt. Call get_business_context (identity by default; posts/comments/dms only when needed). Never invent prices or page facts.
- RECENT POSTS: To see live channel content (tone, topics, concepts), call list_recent_posts with limit 1–20 (SocialAPI). Use before drafting when the owner asks to match recent style or you need examples.
- POST STEPS: (1) Draft the caption in the shop language. Hashtags only if they asked; none if they said without tags; if they did not say, ask once. Run internal CaptionApprover brand review (approve or reject→rewrite, max 3 rounds) before showing the caption to the owner; then wait for yes/no. Do not generate an image and do not call MCP create-post yet. (2) After they accept the caption, ask once whether to add an image. Text-only means no generate_image. When generating an image, use list_channels logo_url (custom upload or SocialAPI page picture) for on-brand marks — never invent a logo. (3) Only then call the SocialAPI MCP create/schedule post tool (sapi_*) with socialapi_account_id from list_channels using the approved caption. Confirm card finalizes unless ai_auto_publish_posts is on.
- Publish/schedule: MCP post tools + confirm_pending_action after the user confirms the card (or auto publish).
- To show what is already scheduled in Wasl, call list_scheduled_posts.
- AI CAMPAIGNS: list_ai_campaigns and get_ai_campaign are read-only. create_ai_campaign only opens a confirm card; it does not launch until the user confirms. Do not say a campaign is running before confirm.
- IMAGE GEN: Call generate_image only when they asked for an image or a regen, or said yes to adding an image on a post. A caption, a post, or a question is not an image request. Scene in the tool prompt may be English. On-image text follows Darija Arabic script or French when they asked or the shop language requires it for that image. FORBIDDEN: English headlines when Darija/French was requested; telling them the model needs English; pasting the English scene into chat; asking them to approve the prompt. Do not invent prices or logos.
- REGENERATE: Only when they ask to regenerate the image (جدد الصورة / عاود الصورة / 3awd / régénère). That new image is not the post image until they say yes to it.
- The post image is the one they affirmed (use it / استعملها / هادي / cette image / the previous one). Never paste ids in chat. Never use a later regen they did not accept.
- If the user attached images and those are the ones for this post, use them when the MCP media/post tools require media. Never invent SocialAPI media_ids.
- If info is missing (which page/account, caption text, schedule time), ask — do not create yet.
- Comments and DMs: use sapi_ MCP read/write tools (get_business_context for reads when useful). Writes still need confirm unless the matching auto toggle is on.
- Never invent prices, stock, or channel facts that contradict identity from get_business_context.
- Do not mention tools or internal IDs unless useful (action ids for confirm are useful).
{$interviewBlock}
Connected channels (local id + socialapi_account_id for MCP):
{$channelsJson}

{$identityHint}

{$skillBlock}
PROMPT;
    }

    private function interviewBlock(Business $business): string
    {
        $state = app(\App\Services\ProfileInterviewService::class)->toolState($business);
        if (empty($state['active'])) {
            return '';
        }

        $index = (int) ($state['current_index'] ?? 0);
        $total = (int) ($state['total'] ?? 0);
        $question = (string) ($state['current_question'] ?? '');
        $remaining = (int) ($state['remaining'] ?? 0);
        $path = (string) ($state['field_path'] ?? '');

        return <<<TEXT

Active Complete profile interview (pipeline — do not skip ahead):
- current_index: {$index}
- field_path: {$path}
- question {$index} of {$total}: {$question}
- remaining including current: {$remaining}
When the owner answers THIS question (including "does not apply", "oui", "yes", or a short fact), call record_profile_answer immediately with question_index={$index} and their answer.
Then reply with at most one short confirm plus the follow_up from the tool. Do not recap earlier questions or answers. Do not ask them to confirm again.
Never invent a delivery or returns question for a digital shop. Never ask the owner to approve an auto-skip. Ask only the current_question above.
If they change topic, help them and do NOT call record_profile_answer.
They may abandon from the card; do not force the interview.
TEXT;
    }
}
