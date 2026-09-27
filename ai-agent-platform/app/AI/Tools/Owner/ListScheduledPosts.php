<?php

namespace App\AI\Tools\Owner;

use App\AI\Tools\AgentTool;
use App\Models\Business;
use App\Services\ScheduledPostService;
use App\Support\CurrentBusiness;
use Throwable;

class ListScheduledPosts implements AgentTool
{
    public function __construct(private ScheduledPostService $posts) {}

    public function name(): string
    {
        return 'list_scheduled_posts';
    }

    public function description(): string
    {
        return 'List posts already scheduled for this shop (same queue as the Schedule posts UI). Use when the owner asks what is scheduled, upcoming posts, or the schedule calendar.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max posts to return (1–25, default 10)',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Optional caption search text',
                ],
            ],
            'required' => [],
        ];
    }

    public function handle(Business $business, array $arguments, array $context = []): array
    {
        $limit = (int) ($arguments['limit'] ?? 10);
        $limit = max(1, min(25, $limit));
        $search = isset($arguments['search']) ? trim((string) $arguments['search']) : null;
        $search = $search !== '' ? $search : null;

        $previous = CurrentBusiness::id();
        CurrentBusiness::set($business->id);

        try {
            $result = $this->posts->list('scheduled', null, $limit, $search);
        } catch (Throwable $e) {
            return ['error' => $e->getMessage() ?: 'Could not load scheduled posts.'];
        } finally {
            CurrentBusiness::set($previous);
        }

        $posts = [];
        foreach ($result['posts'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $targets = [];
            foreach (is_array($row['targets'] ?? null) ? $row['targets'] : [] as $t) {
                if (! is_array($t)) {
                    continue;
                }
                $targets[] = [
                    'local_account_id' => $t['local_account_id'] ?? null,
                    'account_name' => $t['account_name'] ?? null,
                    'platform' => $t['platform'] ?? null,
                ];
            }
            $posts[] = [
                'id' => $row['id'] ?? null,
                'text' => $row['text'] ?? '',
                'status' => $row['status'] ?? null,
                'scheduled_at' => $row['scheduled_at'] ?? null,
                'targets' => $targets,
                'media_count' => is_array($row['media'] ?? null) ? count($row['media']) : 0,
            ];
        }

        return [
            'ok' => true,
            'count' => count($posts),
            'posts' => $posts,
            'has_more' => (bool) ($result['pagination']['has_more'] ?? false),
        ];
    }
}
