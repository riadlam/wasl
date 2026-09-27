<?php

namespace App\Services\Onboarding;

use App\Jobs\Onboarding\BuildChannelAiProfileJob;
use App\Models\Business;
use App\Models\BusinessTrainingSnapshot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SocialAccount;
use App\Services\ConversationService;
use App\Services\SocialApi\SocialApiInboxService;
use App\Services\SocialApi\SocialApiPostsService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

class OnboardingTrainingService
{
    public const POST_LIMIT = 20;

    public const COMMENTS_PER_POST = 20;

    public const CHAT_LIMIT = 20;

    public function __construct(
        private SocialApiPostsService $posts,
        private SocialApiInboxService $inbox,
        private ConversationService $conversations,
    ) {}

    public function train(Business $business): void
    {
        $accounts = $this->trainingAccounts($business);
        if ($accounts->isEmpty()) {
            throw new \RuntimeException('No connected SocialAPI accounts to train on.');
        }

        $this->patchMeta($business, [
            'started_at' => $business->onboarding_meta['started_at'] ?? now()->toIso8601String(),
            'error' => null,
            'posts' => 0,
            'comments' => 0,
            'chats' => 0,
            'account_ids' => $accounts->pluck('id')->values()->all(),
        ]);

        foreach ($accounts as $account) {
            $this->snapshotPage($business, $account);
        }

        $postCount = 0;
        $commentCount = 0;
        $chatCount = 0;

        foreach ($accounts as $account) {
            $accountId = (string) $account->socialapi_account_id;
            $platform = $this->trainingPlatform($account);
            $remote = $this->posts->listPublishedPosts([$accountId], null, null, self::POST_LIMIT, $platform);
            $normalized = $this->posts->normalizeList($remote);
            $postRows = array_slice($normalized['posts'], 0, self::POST_LIMIT);

            $metricIds = [];
            foreach ($postRows as $post) {
                $externalId = (string) ($post['platform_post_id'] ?? $post['id'] ?? '');
                if ($externalId === '' || $this->snapshotExists($business, 'post', $externalId)) {
                    continue;
                }
                $metricIds[] = $externalId;
            }
            $metricsMap = $metricIds !== [] ? $this->posts->metricsForPosts($metricIds) : [];

            foreach ($postRows as $post) {
                $externalId = (string) ($post['platform_post_id'] ?? $post['id'] ?? '');
                if ($externalId === '') {
                    continue;
                }

                if ($this->snapshotExists($business, 'post', $externalId)) {
                    $postCount++;
                    $commentCount += $this->commentSnapshotCount($business, $externalId);

                    continue;
                }

                $metrics = $metricsMap[$externalId]
                    ?? $metricsMap[(string) ($post['id'] ?? '')]
                    ?? [
                        'likes' => $post['like_count'] ?? 0,
                        'comments' => $post['comments_count'] ?? 0,
                        'shares' => $post['share_count'] ?? 0,
                        'saves' => 0,
                    ];

                $this->upsertSnapshot($business, $account, 'post', $externalId, $post['platform'] ?? $account->platform, $post['title'] ?? mb_substr((string) ($post['caption'] ?? ''), 0, 80), (string) ($post['caption'] ?? ''), [
                    'post' => $post,
                    'metrics' => $metrics,
                ]);
                $postCount++;
                $this->patchMeta($business, ['posts' => $postCount]);

                try {
                    $commentsRemote = $this->inbox->listCommentsOnPost($externalId, $accountId, null, self::COMMENTS_PER_POST);
                    $comments = $commentsRemote['data'] ?? $commentsRemote['comments'] ?? [];
                    if (! is_array($comments)) {
                        $comments = [];
                    }
                    $comments = array_slice($comments, 0, self::COMMENTS_PER_POST);

                    foreach ($comments as $comment) {
                        if (! is_array($comment)) {
                            continue;
                        }
                        $cid = (string) ($comment['id'] ?? $comment['platform_id'] ?? '');
                        if ($cid === '') {
                            continue;
                        }
                        $text = (string) ($comment['text'] ?? '');
                        $this->upsertSnapshot(
                            $business,
                            $account,
                            'comment',
                            $externalId.':'.$cid,
                            $comment['platform'] ?? $account->platform,
                            $comment['author_name'] ?? $comment['author_username'] ?? 'Comment',
                            $text,
                            [
                                'post_id' => $externalId,
                                'comment' => $comment,
                            ],
                        );
                        $commentCount++;
                    }
                    $this->patchMeta($business, ['comments' => $commentCount]);
                } catch (Throwable $e) {
                    Log::warning('onboarding.comments_fetch_failed', [
                        'business_id' => $business->id,
                        'post_id' => $externalId,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            // Latest 20 DM conversations + full message history
            try {
                $cursor = null;
                $fetched = 0;
                do {
                    $remoteInbox = $this->inbox->listConversations($accountId, $cursor, min(50, self::CHAT_LIMIT - $fetched), $platform);
                    $rows = $remoteInbox['data'] ?? $remoteInbox['conversations'] ?? [];
                    if (! is_array($rows)) {
                        $rows = [];
                    }

                    foreach ($rows as $row) {
                        if ($fetched >= self::CHAT_LIMIT || ! is_array($row)) {
                            break;
                        }

                        $remoteId = $row['id'] ?? $row['conversation_id'] ?? null;
                        if (! $remoteId) {
                            continue;
                        }

                        if ($this->dmAlreadyTrained($business, (string) $remoteId)) {
                            $fetched++;
                            $chatCount++;

                            continue;
                        }

                        $result = $this->importConversation($business, $account, $row);
                        if ($result) {
                            $fetched++;
                            $chatCount++;
                            $this->patchMeta($business, ['chats' => $chatCount]);

                            $this->upsertSnapshot(
                                $business,
                                $account,
                                'dm',
                                (string) $remoteId,
                                $row['platform'] ?? $account->platform,
                                $row['participant_name'] ?? $row['participant_username'] ?? 'Chat',
                                is_string($row['last_message'] ?? null) ? (string) $row['last_message'] : '',
                                ['conversation' => $row],
                            );
                        }
                    }

                    $pagination = is_array($remoteInbox['pagination'] ?? null) ? $remoteInbox['pagination'] : [];
                    $cursor = $pagination['next_cursor'] ?? $remoteInbox['next_cursor'] ?? null;
                    $hasMore = (bool) ($pagination['has_more'] ?? ($cursor !== null && $cursor !== ''));
                } while ($fetched < self::CHAT_LIMIT && $hasMore && $cursor);
            } catch (Throwable $e) {
                Log::warning('onboarding.chats_fetch_failed', [
                    'business_id' => $business->id,
                    'account_id' => $account->id,
                    'message' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        $this->patchMeta($business, [
            'posts' => $postCount,
            'comments' => $commentCount,
            'chats' => $chatCount,
            'completed_at' => now()->toIso8601String(),
            'error' => null,
        ]);

        $business->update([
            'onboarding_status' => Business::ONBOARDING_PHASEONE,
        ]);

        BuildChannelAiProfileJob::dispatch($business->id);
    }

    /**
     * Prefer Facebook / Instagram; fall back to any connected SocialAPI account.
     *
     * @return \Illuminate\Support\Collection<int, SocialAccount>
     */
    private function trainingAccounts(Business $business)
    {
        $base = SocialAccount::query()
            ->where('business_id', $business->id)
            ->where('provider', 'socialapi')
            ->where('platform', '!=', 'simulator')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'disconnected');
            })
            ->whereNotNull('socialapi_account_id')
            ->get();

        $preferred = $base->filter(function (SocialAccount $a) {
            $platform = $this->trainingPlatform($a) ?? $a->platform;

            return in_array($platform, ['facebook', 'instagram'], true);
        });

        return $preferred->isNotEmpty() ? $preferred->values() : $base->values();
    }

    /**
     * Never send platform=unknown to SocialAPI filters (returns empty).
     */
    private function trainingPlatform(SocialAccount $account): ?string
    {
        $platform = strtolower(trim((string) $account->platform));
        if ($platform !== '' && $platform !== 'unknown' && $platform !== 'simulator') {
            return $platform;
        }

        $meta = is_array($account->metadata) ? $account->metadata : [];
        $fromMeta = strtolower(trim((string) ($meta['platform'] ?? '')));
        if ($fromMeta !== '' && $fromMeta !== 'unknown') {
            return $fromMeta;
        }

        $link = strtolower(implode(' ', array_filter([
            (string) ($meta['profile_url'] ?? ''),
            (string) ($meta['page']['link'] ?? ''),
        ])));
        if (str_contains($link, 'facebook.com') || str_contains($link, 'fb.com')) {
            return 'facebook';
        }
        if (str_contains($link, 'instagram.com')) {
            return 'instagram';
        }

        return null;
    }

    private function snapshotPage(Business $business, SocialAccount $account): void
    {
        $content = trim(implode("\n", array_filter([
            $account->name ? 'Name: '.$account->name : null,
            $account->username ? 'Username: @'.$account->username : null,
            $account->platform ? 'Platform: '.$account->platform : null,
        ])));

        $this->upsertSnapshot(
            $business,
            $account,
            'page',
            (string) $account->socialapi_account_id,
            $account->platform,
            $account->name ?: 'Page',
            $content,
            [
                'name' => $account->name,
                'username' => $account->username,
                'avatar_url' => $account->avatar_url,
                'platform' => $account->platform,
            ],
        );

        if (! $business->name || $business->name === 'Wasl') {
            // leave shop name alone unless empty
        }
        if (empty($business->description) && $content !== '') {
            $business->update(['description' => mb_substr($content, 0, 500)]);
        }
    }

    private function snapshotExists(Business $business, string $source, string $externalId): bool
    {
        return BusinessTrainingSnapshot::query()
            ->where('business_id', $business->id)
            ->where('source', $source)
            ->where('external_id', $externalId)
            ->exists();
    }

    private function commentSnapshotCount(Business $business, string $postExternalId): int
    {
        return BusinessTrainingSnapshot::query()
            ->where('business_id', $business->id)
            ->where('source', 'comment')
            ->where('external_id', 'like', $postExternalId.':%')
            ->count();
    }

    private function dmAlreadyTrained(Business $business, string $remoteId): bool
    {
        if (! $this->snapshotExists($business, 'dm', $remoteId)) {
            return false;
        }

        $conversationId = Conversation::query()
            ->where('business_id', $business->id)
            ->where('socialapi_conversation_id', $remoteId)
            ->value('id');

        if (! $conversationId) {
            return false;
        }

        return Message::query()->where('conversation_id', $conversationId)->exists();
    }

    private function upsertSnapshot(
        Business $business,
        SocialAccount $account,
        string $source,
        string $externalId,
        ?string $platform,
        ?string $title,
        ?string $content,
        array $payload,
    ): void {
        $keys = [
            'business_id' => $business->id,
            'source' => $source,
            'external_id' => $externalId,
        ];
        $values = [
            'social_account_id' => $account->id,
            'platform' => $platform,
            'title' => $title,
            'content' => $content,
            'payload' => $payload,
        ];

        try {
            BusinessTrainingSnapshot::query()->updateOrCreate($keys, $values);
        } catch (UniqueConstraintViolationException) {
            $row = BusinessTrainingSnapshot::query()->where($keys)->first();
            if ($row) {
                $row->fill($values)->save();
            }
        }
    }

    /**
     * Import one remote conversation row + full message history.
     */
    private function importConversation(Business $business, SocialAccount $account, array $row): bool
    {
        // Use ConversationService::syncRemoteInbox pattern via a temporary public helper:
        // syncHistory requires an existing Conversation — upsert through service internals.
        $remoteId = $row['id'] ?? $row['conversation_id'] ?? null;
        if (! $remoteId) {
            return false;
        }

        // Direct upsert + full history for onboarding corpus.
        $status = $this->conversations->upsertRemoteConversationRow($business, $account, $row, false);

        $conversation = Conversation::query()
            ->forBusiness($business->id)
            ->where('socialapi_conversation_id', $remoteId)
            ->first();

        if ($conversation) {
            try {
                $this->conversations->syncHistory($conversation->fresh(['socialAccount']), null, 100, true);
            } catch (Throwable $e) {
                Log::warning('onboarding.dm_history_failed', [
                    'conversation_id' => $conversation->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $status === 'created' || $status === 'updated';
    }

    private function patchMeta(Business $business, array $patch): void
    {
        $meta = array_merge($business->onboarding_meta ?? [], $patch);
        $business->forceFill(['onboarding_meta' => $meta])->save();
        $business->refresh();
    }
}
