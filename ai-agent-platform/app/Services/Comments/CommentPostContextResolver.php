<?php

namespace App\Services\Comments;

use App\Models\Business;
use App\Models\SocialAccount;
use App\Services\SocialApi\SocialApiPostsService;
use Throwable;

/**
 * Resolve the parent post caption/media for a public comment so CustomerAgent
 * can answer in-context (not with a generic "DM us").
 */
class CommentPostContextResolver
{
    public function __construct(private SocialApiPostsService $posts) {}

    /**
     * @return array{post_id: string, caption: string, platform: string, permalink: string, block: string}|null
     */
    public function forComment(Business $business, ?SocialAccount $account, ?string $platformPostId): ?array
    {
        $postId = is_string($platformPostId) ? trim($platformPostId) : '';
        if ($postId === '') {
            return null;
        }

        $caption = '';
        $platform = (string) ($account?->platform ?? '');
        $permalink = '';

        try {
            $remote = $this->posts->getPost($postId);
            $row = is_array($remote['data'] ?? null) ? $remote['data'] : $remote;
            if (is_array($row)) {
                $normalized = $this->posts->normalizePost($row);
                if (is_array($normalized)) {
                    $caption = trim((string) ($normalized['caption'] ?? ''));
                    $platform = (string) ($normalized['platform'] ?? $platform);
                    $permalink = (string) ($normalized['permalink'] ?? '');
                }
                if ($caption === '') {
                    $caption = trim((string) ($row['caption'] ?? $row['text'] ?? $row['message'] ?? ''));
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        $caption = mb_substr($caption, 0, 1200);
        $lines = [
            '## PARENT POST (the post this customer commented on — answer in this context)',
            'platform_post_id: '.$postId,
        ];
        if ($platform !== '') {
            $lines[] = 'platform: '.$platform;
        }
        if ($permalink !== '') {
            $lines[] = 'permalink: '.$permalink;
        }
        $lines[] = $caption !== ''
            ? "caption:\n{$caption}"
            : 'caption: (unavailable — use ask_identity_agent + knowledge_search + list_recent_posts; do not invent the offer)';

        return [
            'post_id' => $postId,
            'caption' => $caption,
            'platform' => $platform,
            'permalink' => $permalink,
            'block' => implode("\n", $lines),
        ];
    }
}
