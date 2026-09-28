<?php

namespace App\Services\Comments;

use App\Models\Business;
use App\Models\Message;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Cache;

/**
 * Hard guards against comment auto-reply storms.
 *
 * Cause of the Sep 28 loop: SocialAPI re-delivered our own page comment replies
 * as comment.received → agent treated them as customer comments → replied again.
 */
class CommentReplyGuard
{
    public const REPLIED_TTL_SECONDS = 86400;

    public const POST_RATE_WINDOW_SECONDS = 300;

    public const POST_RATE_MAX = 5;

    /**
     * @param  array<string, mixed>  $payload  SocialAPI comment.received data
     */
    public function isPageSelfComment(SocialAccount $account, array $payload): bool
    {
        $author = is_array($payload['author'] ?? null) ? $payload['author'] : [];
        $authorId = $this->str($author['id'] ?? $author['user_id'] ?? $author['platform_user_id'] ?? null);
        $authorName = mb_strtolower(trim($this->str($author['name'] ?? '') ?? ''));

        $pageIds = array_filter([
            $this->str($account->platform_account_id),
            $this->str($payload['page_id'] ?? null),
            $this->str(is_array($account->metadata) ? ($account->metadata['platform_page_id'] ?? null) : null),
            $this->str(is_array($account->metadata) ? ($account->metadata['page_id'] ?? null) : null),
            $this->pageIdFromPlatformPostId($this->str($payload['platform_post_id'] ?? null)),
        ]);

        if ($authorId !== null) {
            foreach ($pageIds as $pageId) {
                if ($pageId !== null && hash_equals($pageId, $authorId)) {
                    return true;
                }
                // Facebook page comments sometimes use page id without prefix noise.
                if ($pageId !== null && str_contains($pageId, $authorId)) {
                    return true;
                }
            }
        }

        $accountName = mb_strtolower(trim((string) ($account->name ?? '')));
        if ($authorName !== '' && $accountName !== '' && hash_equals($accountName, $authorName)) {
            return true;
        }

        return false;
    }

    /**
     * True when this inbound text is identical to a recent AI comment reply we already sent
     * on the same post (echo without matching author id).
     */
    public function matchesRecentOutboundReply(Business $business, ?string $postId, string $text): bool
    {
        $text = trim($text);
        $postId = is_string($postId) ? trim($postId) : '';
        if ($text === '' || $postId === '') {
            return false;
        }

        return Message::query()
            ->where('business_id', $business->id)
            ->where('direction', 'outbound')
            ->where('type', 'comment')
            ->where('ai_generated', true)
            ->where('created_at', '>=', now()->subMinutes(60))
            ->where('text', $text)
            ->where(function ($q) use ($postId) {
                $q->where('metadata->post_id', $postId)
                    ->orWhere('metadata->inbound->post_id', $postId);
            })
            ->exists();
    }

    public function alreadyRepliedToComment(int $businessId, ?string $commentId): bool
    {
        $commentId = is_string($commentId) ? trim($commentId) : '';
        if ($commentId === '') {
            return false;
        }

        if (Cache::has($this->repliedKey($businessId, $commentId))) {
            return true;
        }

        return Message::query()
            ->where('business_id', $businessId)
            ->where('direction', 'outbound')
            ->where('type', 'comment')
            ->where('ai_generated', true)
            ->where('created_at', '>=', now()->subDay())
            ->where(function ($q) use ($commentId) {
                $q->where('metadata->comment_id', $commentId)
                    ->orWhere('metadata->inbound->id', $commentId);
            })
            ->exists();
    }

    public function markReplied(int $businessId, ?string $commentId): void
    {
        $commentId = is_string($commentId) ? trim($commentId) : '';
        if ($commentId === '') {
            return;
        }
        Cache::put($this->repliedKey($businessId, $commentId), 1, self::REPLIED_TTL_SECONDS);
    }

    public function postRateLimited(int $businessId, ?string $postId): bool
    {
        $postId = is_string($postId) ? trim($postId) : '';
        if ($postId === '') {
            return false;
        }
        $key = $this->postRateKey($businessId, $postId);
        $count = (int) Cache::get($key, 0);

        return $count >= self::POST_RATE_MAX;
    }

    public function hitPostRate(int $businessId, ?string $postId): void
    {
        $postId = is_string($postId) ? trim($postId) : '';
        if ($postId === '') {
            return;
        }
        $key = $this->postRateKey($businessId, $postId);
        if (! Cache::has($key)) {
            Cache::put($key, 1, self::POST_RATE_WINDOW_SECONDS);

            return;
        }
        Cache::increment($key);
    }

    /**
     * Should we run AI / private-DM for this inbound comment?
     *
     * @param  array<string, mixed>  $payload
     */
    public function shouldProcessInboundComment(
        Business $business,
        SocialAccount $account,
        array $payload,
        string $text,
        ?string $postId,
        ?string $commentId,
    ): bool {
        if ($this->isPageSelfComment($account, $payload)) {
            return false;
        }
        if ($this->matchesRecentOutboundReply($business, $postId, $text)) {
            return false;
        }
        if ($this->alreadyRepliedToComment((int) $business->id, $commentId)) {
            return false;
        }
        if ($this->postRateLimited((int) $business->id, $postId)) {
            return false;
        }

        return true;
    }

    private function repliedKey(int $businessId, string $commentId): string
    {
        return 'comment_ai_replied:'.$businessId.':'.$commentId;
    }

    private function postRateKey(int $businessId, string $postId): string
    {
        return 'comment_ai_post_rate:'.$businessId.':'.$postId;
    }

    private function pageIdFromPlatformPostId(?string $platformPostId): ?string
    {
        if ($platformPostId === null || $platformPostId === '') {
            return null;
        }
        if (str_contains($platformPostId, '_')) {
            return explode('_', $platformPostId, 2)[0] ?: null;
        }

        return null;
    }

    private function str(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }
}
