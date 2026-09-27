<?php

namespace App\AI\Tools\Owner;

use App\AI\Tools\AgentTool;
use App\Models\Business;
use App\Models\SocialAccount;
use App\Services\Campaigns\CampaignMcpGateway;
use App\Services\SocialApi\SocialApiPostsService;
use Throwable;

/**
 * Fetch the newest published posts for connected channels.
 * Uses SocialAPI REST (sort + client-side published_at) so agents see real recent captions,
 * not stale MCP blobs ordered by sync created_at.
 */
class ListRecentPosts implements AgentTool
{
    public const MAX_LIMIT = 20;

    public const DEFAULT_LIMIT = 10;

    /** Fetch a wider window then sort by published_at — SocialAPI "created_*" is sync time, not FB publish time. */
    public const FETCH_WINDOW = 40;

    public function __construct(
        private SocialApiPostsService $posts,
        private ?CampaignMcpGateway $mcp = null,
    ) {}

    public function name(): string
    {
        return 'list_recent_posts';
    }

    public function description(): string
    {
        return 'Fetch the latest published posts from connected SocialAPI channels (newest first by published_at). '
            .'Returns short caption lines for tone/offers (e.g. weekly pass). '
            .'Pass limit (1–20, default 10; customer DMs typically use 5). '
            .'Optionally filter by socialapi_account_ids or local channel_ids from list_channels.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => [
                    'type' => 'integer',
                    'description' => 'How many newest posts to return (1–20). Default 10.',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                ],
                'socialapi_account_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional SocialAPI account ids. Defaults to all connected channels.',
                ],
                'channel_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Optional local Wasl channel ids from list_channels.',
                ],
            ],
        ];
    }

    public function handle(Business $business, array $arguments, array $context = []): array
    {
        $limit = (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $accountIds = $this->resolveAccountIds($business, $arguments, $context);
        if ($accountIds === []) {
            return [
                'ok' => false,
                'error' => 'no_connected_channels',
                'message' => 'No connected SocialAPI channels found. Ask the owner to connect a page first.',
                'limit' => $limit,
            ];
        }

        $rows = [];
        $source = 'rest';
        $error = null;

        try {
            $fetch = max($limit, min(self::FETCH_WINDOW, 100));
            $remote = $this->posts->listPublishedPosts($accountIds, null, null, $fetch);
            $normalized = $this->posts->normalizeList($remote);
            $list = is_array($normalized['posts'] ?? null) ? $normalized['posts'] : [];
            $rows = $this->newestByPublishedAt($list, $limit);
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $source = 'mcp_fallback';
            try {
                $rows = $this->fromMcpFallback($accountIds, $limit);
            } catch (Throwable $mcpError) {
                return [
                    'ok' => false,
                    'error' => 'list_posts_failed',
                    'message' => $error.'; mcp: '.$mcpError->getMessage(),
                    'limit' => $limit,
                    'account_ids' => $accountIds,
                ];
            }
        }

        $captions = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $text = mb_substr(trim((string) ($row['caption'] ?? $row['text'] ?? '')), 0, 600);
            if ($text === '') {
                continue;
            }
            $captions[] = [
                'published_at' => (string) ($row['published_at'] ?? ''),
                'platform' => (string) ($row['platform'] ?? ''),
                'text' => $text,
                'permalink' => (string) ($row['permalink'] ?? ''),
            ];
        }

        $lines = [];
        foreach ($captions as $i => $c) {
            $lines[] = sprintf(
                "[%d] %s | %s\n%s",
                $i + 1,
                $c['published_at'] !== '' ? $c['published_at'] : 'unknown_date',
                $c['platform'] !== '' ? $c['platform'] : 'page',
                $c['text']
            );
        }

        return [
            'ok' => true,
            'limit' => $limit,
            'count' => count($captions),
            'capped_at' => self::MAX_LIMIT,
            'sorted_by' => 'published_at_desc',
            'source' => $source,
            'account_ids' => $accountIds,
            'posts' => $captions,
            'posts_text' => implode("\n---\n", $lines),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function newestByPublishedAt(array $rows, int $limit): array
    {
        usort($rows, function (array $a, array $b) {
            $ta = strtotime((string) ($a['published_at'] ?? '')) ?: 0;
            $tb = strtotime((string) ($b['published_at'] ?? '')) ?: 0;

            return $tb <=> $ta;
        });

        return array_slice(array_values($rows), 0, $limit);
    }

    /**
     * @param  list<string>  $accountIds
     * @return list<array<string, mixed>>
     */
    private function fromMcpFallback(array $accountIds, int $limit): array
    {
        if ($this->mcp === null) {
            throw new \RuntimeException('MCP gateway not available');
        }

        $raw = $this->mcp->listRecentPosts($accountIds, max($limit, min(self::FETCH_WINDOW, 20)));
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            // Plain text fallback — wrap as single pseudo-post
            $text = trim($raw);
            if ($text === '') {
                return [];
            }

            return [['published_at' => '', 'platform' => '', 'caption' => mb_substr($text, 0, 600), 'permalink' => '']];
        }

        $data = $decoded['data'] ?? $decoded;
        if (! is_array($data)) {
            return [];
        }

        $normalized = [];
        foreach ($data as $row) {
            if (! is_array($row)) {
                continue;
            }
            $n = $this->posts->normalizePost($row);
            if ($n !== null) {
                $normalized[] = $n;
            }
        }

        return $this->newestByPublishedAt($normalized, $limit);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    private function resolveAccountIds(Business $business, array $arguments, array $context = []): array
    {
        $fromArgs = [];
        if (is_array($arguments['socialapi_account_ids'] ?? null)) {
            foreach ($arguments['socialapi_account_ids'] as $id) {
                $id = trim((string) $id);
                if ($id !== '') {
                    $fromArgs[] = $id;
                }
            }
        }

        $channelIds = [];
        if (is_array($arguments['channel_ids'] ?? null)) {
            $channelIds = array_values(array_filter(array_map('intval', $arguments['channel_ids'])));
        }

        // Prefer the conversation's connected channel when calling from CustomerAgent.
        $ctxChannel = (int) ($context['social_account_id'] ?? $context['channel_id'] ?? 0);
        if ($ctxChannel > 0 && $channelIds === [] && $fromArgs === []) {
            $channelIds = [$ctxChannel];
        }

        $query = SocialAccount::query()
            ->where('business_id', $business->id)
            ->where('provider', 'socialapi')
            ->where('platform', '!=', 'simulator')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'disconnected');
            });

        if ($channelIds !== []) {
            $query->whereIn('id', $channelIds);
        }

        $fromDb = $query
            ->pluck('socialapi_account_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();

        // If context channel had no socialapi id, fall back to all shop channels.
        if ($fromDb === [] && $ctxChannel > 0) {
            $fromDb = SocialAccount::query()
                ->where('business_id', $business->id)
                ->where('provider', 'socialapi')
                ->where('platform', '!=', 'simulator')
                ->where(function ($q) {
                    $q->whereNull('status')->orWhere('status', '!=', 'disconnected');
                })
                ->pluck('socialapi_account_id')
                ->filter()
                ->map(fn ($id) => (string) $id)
                ->values()
                ->all();
        }

        if ($fromArgs !== []) {
            $allowed = array_flip($fromDb);

            return array_values(array_filter($fromArgs, fn (string $id) => isset($allowed[$id])));
        }

        return $fromDb;
    }
}
