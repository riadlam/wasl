<?php

namespace App\AI\Tools\Owner;

use App\AI\Tools\AgentTool;
use App\Models\Business;

class GetAgentAutoSettings implements AgentTool
{
    public const FLAGS = [
        'ai_auto_publish_posts',
        'ai_auto_reply_comments',
        'ai_auto_send_dms',
        'ai_auto_reply_reviews',
        'ai_auto_moderate',
    ];

    public function name(): string
    {
        return 'get_agent_auto_settings';
    }

    public function description(): string
    {
        return 'Read shop AI auto-action settings (publish posts, reply comments, send DMs, reply reviews, moderate). When a flag is true, matching SocialAPI MCP writes run without the chat confirm card. When false, writes need confirm.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) [],
        ];
    }

    public function handle(Business $business, array $arguments, array $context = []): array
    {
        $settings = $business->agentSettings;
        if (! $settings) {
            return ['error' => 'Agent settings not found.'];
        }

        $flags = [];
        foreach (self::FLAGS as $key) {
            $flags[$key] = (bool) ($settings->{$key} ?? false);
        }

        return [
            'ok' => true,
            'flags' => $flags,
            'labels' => [
                'ai_auto_publish_posts' => 'Publish posts without confirm',
                'ai_auto_reply_comments' => 'Reply to comments without confirm',
                'ai_auto_send_dms' => 'Send DMs without confirm',
                'ai_auto_reply_reviews' => 'Reply to reviews without confirm',
                'ai_auto_moderate' => 'Moderate without confirm',
            ],
            'note' => 'false = chat confirm card required; true = MCP write runs immediately.',
        ];
    }
}
