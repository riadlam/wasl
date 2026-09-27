<?php

namespace App\AI\Tools\Owner;

use App\AI\Tools\AgentTool;
use App\Models\Business;
use Illuminate\Support\Carbon;

/**
 * Dedicated schedule entry-point for the owner chat agent.
 * Reuses draft_create_post pending-action flow (confirm still required).
 */
class DraftSchedulePost implements AgentTool
{
    public function __construct(private DraftCreatePost $draft) {}

    public function name(): string
    {
        return 'draft_schedule_post';
    }

    public function description(): string
    {
        return 'Draft a SCHEDULED post for confirmation. Does NOT publish now. Call only after the caption is confirmed and the owner chose image or text-only. Requires account_ids, text, and future scheduled_at. asset_ids must be the image they said yes to, not a later regen. Omit asset_ids for text-only.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Local social account ids from list_channels',
                ],
                'text' => [
                    'type' => 'string',
                    'description' => 'Post caption / text',
                ],
                'scheduled_at' => [
                    'type' => 'string',
                    'description' => 'Required ISO8601 future datetime when the post should go live (prefer shop timezone when converting relative times like "tomorrow 10am").',
                ],
                'media_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional SocialAPI media ids already uploaded',
                ],
                'asset_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Exact agent asset ids the owner said yes to. Not a later regeneration. Omit for text-only.',
                ],
            ],
            'required' => ['account_ids', 'text', 'scheduled_at'],
        ];
    }

    public function handle(Business $business, array $arguments, array $context = []): array
    {
        $raw = trim((string) ($arguments['scheduled_at'] ?? ''));
        if ($raw === '') {
            return ['error' => 'scheduled_at is required — ask when they want the post to go live.'];
        }

        try {
            $when = Carbon::parse($raw);
        } catch (\Throwable) {
            return ['error' => 'scheduled_at must be a valid date/time.'];
        }

        if ($when->lessThanOrEqualTo(now())) {
            return ['error' => 'scheduled_at must be in the future.'];
        }

        $arguments['scheduled_at'] = $when->utc()->toIso8601String();
        $arguments['publish_now'] = false;

        $result = $this->draft->handle($business, $arguments, $context);
        if (! empty($result['ok'])) {
            $result['mode'] = 'schedule';
            $result['ask_user'] = 'Show the schedule time clearly, then ask the user to confirm before calling confirm_pending_action.';
        }

        return $result;
    }
}
