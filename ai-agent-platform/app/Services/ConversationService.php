<?php

namespace App\Services;

use App\Events\ConversationUpdated;
use App\Events\InboxMessageCreated;
use App\Jobs\AI\ProcessIncomingMessageJob;
use App\Jobs\Social\SendSocialMessageJob;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\CustomerSocialProfile;
use App\Models\Message;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SocialApi\SocialApiInboxService;
use App\Support\CurrentBusiness;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\UniqueConstraintViolationException;

class ConversationService
{
    public function __construct(
        private CustomerService $customers,
        private SocialApiInboxService $socialInbox,
    ) {}

    public function list(?Business $business = null)
    {
        $business ??= CurrentBusiness::require();
        $query = Conversation::query()
            ->forBusiness($business->id)
            ->with(['customer.socialProfiles', 'socialAccount', 'messages' => fn ($q) => $q->orderBy('created_at')->orderBy('id')])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        $this->applyChannelScope($query);

        return $query
            ->get()
            ->map(fn (Conversation $c) => $this->toInboxArray($c));
    }

    public function find(int $id, ?Business $business = null): Conversation
    {
        $business ??= CurrentBusiness::require();

        $query = Conversation::query()
            ->forBusiness($business->id)
            ->with([
                'customer.socialProfiles',
                'socialAccount',
                'messages' => fn ($q) => $q->orderBy('created_at')->orderBy('id'),
                'agentRuns.toolCalls',
            ]);

        $this->applyChannelScope($query);

        return $query->findOrFail($id);
    }

    /**
     * Restrict conversations to channels assigned to the current shop user.
     */
    private function applyChannelScope($query): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        $scope = $user->scopedSocialAccountIds();
        if ($scope === null) {
            return;
        }

        if ($scope === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('social_account_id', $scope);
    }

    /**
     * Import DM threads from SocialAPI into the local inbox (same list as app.social-api.ai/inbox).
     */
    public function syncRemoteInbox(?Business $business = null, bool $withMessages = true): array
    {
        $business ??= CurrentBusiness::require();

        $accounts = SocialAccount::query()
            ->forBusiness($business->id)
            ->where('provider', 'socialapi')
            ->where('status', '!=', 'disconnected')
            ->whereNotNull('socialapi_account_id')
            ->get();

        $imported = 0;
        $updated = 0;
        $errors = [];

        foreach ($accounts as $account) {
            try {
                $cursor = null;
                do {
                    $remote = $this->socialInbox->listConversations(
                        $account->socialapi_account_id,
                        $cursor,
                        100,
                        $account->platform !== 'simulator' ? $account->platform : null,
                    );
                    $rows = $remote['data'] ?? $remote['conversations'] ?? [];
                    if (! is_array($rows)) {
                        $rows = [];
                    }

                    foreach ($rows as $row) {
                        if (! is_array($row)) {
                            continue;
                        }
                        $result = $this->upsertRemoteConversation($business, $account, $row, $withMessages);
                        if ($result === 'created') {
                            $imported++;
                        } elseif ($result === 'updated') {
                            $updated++;
                        }
                    }

                    $pagination = is_array($remote['pagination'] ?? null) ? $remote['pagination'] : [];
                    $cursor = $pagination['next_cursor'] ?? $remote['next_cursor'] ?? null;
                    $hasMore = (bool) ($pagination['has_more'] ?? ($cursor !== null && $cursor !== ''));
                } while ($hasMore && $cursor);
            } catch (\Throwable $e) {
                $errors[] = [
                    'account_id' => $account->id,
                    'platform' => $account->platform,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'imported' => $imported,
            'updated' => $updated,
            'errors' => $errors,
            'conversations' => $this->list($business),
        ];
    }

    private function upsertRemoteConversation(Business $business, SocialAccount $account, array $row, bool $withMessages): string
    {
        return $this->upsertRemoteConversationRow($business, $account, $row, $withMessages);
    }

    /**
     * Upsert a SocialAPI conversation row (used by inbox sync + onboarding training).
     */
    public function upsertRemoteConversationRow(Business $business, SocialAccount $account, array $row, bool $withMessages = true): string
    {
        $remoteId = $row['id'] ?? $row['conversation_id'] ?? null;
        if (! $remoteId) {
            return 'skipped';
        }

        // Prefer matching this shop's linked account; fall back to account_id from the row.
        $accountId = $row['account_id'] ?? null;
        if ($accountId && $accountId !== $account->socialapi_account_id) {
            $matched = SocialAccount::query()
                ->forBusiness($business->id)
                ->where('socialapi_account_id', $accountId)
                ->first();
            if ($matched) {
                $account = $matched;
            }
        }

        $customer = $this->customers->findOrCreate($business, [
            'name' => $row['participant_name'] ?? $row['participant_id'] ?? 'Customer',
            'platform' => $row['platform'] ?? $account->platform,
            'platform_user_id' => $row['participant_id'] ?? $row['user_id'] ?? $row['platform_id'] ?? null,
            'username' => $row['participant_username'] ?? $row['participant_id'] ?? null,
            'avatar_url' => $row['participant_picture'] ?? null,
        ]);

        $existing = Conversation::query()
            ->forBusiness($business->id)
            ->where('socialapi_conversation_id', $remoteId)
            ->first();

        // Reconnect can mint a new SocialAPI conversation id for the same customer thread.
        if (! $existing) {
            $participantId = $row['participant_id'] ?? $row['user_id'] ?? $row['platform_id'] ?? null;
            if (is_string($participantId) && $participantId !== '') {
                $existing = Conversation::query()
                    ->forBusiness($business->id)
                    ->where('social_account_id', $account->id)
                    ->whereHas('customer', function ($q) use ($participantId) {
                        $q->where('platform_user_id', $participantId);
                    })
                    ->orderByDesc('id')
                    ->first();
            }
            if (! $existing && $customer->id) {
                $existing = Conversation::query()
                    ->forBusiness($business->id)
                    ->where('social_account_id', $account->id)
                    ->where('customer_id', $customer->id)
                    ->orderByDesc('id')
                    ->first();
            }
        }

        $lastAt = null;
        if (! empty($row['last_message_at'])) {
            try {
                $lastAt = Carbon::parse($row['last_message_at']);
            } catch (\Throwable) {
                $lastAt = null;
            }
        }

        if ($existing) {
            $existing->fill(array_filter([
                'social_account_id' => $account->id,
                'socialapi_conversation_id' => $remoteId,
                'platform' => $row['platform'] ?? $account->platform,
                'customer_id' => $existing->customer_id ?: $customer->id,
                'last_message_at' => $lastAt,
            ], fn ($v) => $v !== null));
            $existing->save();
            $conversation = $existing;
            $status = 'updated';
        } else {
            try {
                $conversation = Conversation::query()->create([
                    'business_id' => $business->id,
                    'social_account_id' => $account->id,
                    'socialapi_conversation_id' => $remoteId,
                    'platform' => $row['platform'] ?? $account->platform,
                    'customer_id' => $customer->id,
                    'status' => 'open',
                    'ai_enabled' => true,
                    'last_message_at' => $lastAt ?? now(),
                ]);
                $status = 'created';
            } catch (UniqueConstraintViolationException) {
                $conversation = Conversation::query()
                    ->forBusiness($business->id)
                    ->where('socialapi_conversation_id', $remoteId)
                    ->firstOrFail();
                $conversation->fill(array_filter([
                    'social_account_id' => $account->id,
                    'platform' => $row['platform'] ?? $account->platform,
                    'customer_id' => $conversation->customer_id ?: $customer->id,
                    'last_message_at' => $lastAt,
                ], fn ($v) => $v !== null));
                $conversation->save();
                $status = 'updated';
            }
        }

        if ($withMessages && $conversation->socialapi_conversation_id) {
            try {
                // One page only during list sync — full history loads when the thread is opened.
                $this->syncHistory($conversation->fresh(['socialAccount']), null, 100, false);
            } catch (\Throwable) {
                // List import still succeeds even if message history fails for one thread.
            }
        }

        $conversation->refresh();

        // Fallback preview if SocialAPI list has a snippet but message history is empty.
        if ($conversation->messages()->doesntExist() && ! empty($row['last_message'])) {
            try {
                $preview = new Message;
                $preview->forceFill([
                    'business_id' => $business->id,
                    'conversation_id' => $conversation->id,
                    'customer_id' => $customer->id,
                    'direction' => 'inbound',
                    'type' => 'text',
                    'sender_type' => 'customer',
                    'sender_id' => $customer->id,
                    'text' => (string) $row['last_message'],
                    'ai_generated' => false,
                    'status' => 'received',
                    'metadata' => ['source' => 'socialapi_list_preview'],
                    'created_at' => $lastAt ?? now(),
                    'updated_at' => $lastAt ?? now(),
                ]);
                $preview->save();
            } catch (\Throwable) {
                // Race: another import already added a real or preview message.
            }
        }

        return $status;
    }

    /**
     * Pull DM history from SocialAPI into local messages (cursor pages per docs).
     * When $exhaust is true (default for thread open), follow next_cursor until done.
     *
     * @see https://docs.social-api.ai/guides/pagination
     * @see https://docs.social-api.ai/api-reference/inbox/list-messages-in-a-conversation
     */
    public function syncHistory(Conversation $conversation, ?string $cursor = null, int $limit = 100, bool $exhaust = true): array
    {
        $conversation->loadMissing('socialAccount');

        if (
            $conversation->platform === 'simulator'
            || ! $conversation->socialapi_conversation_id
            || $conversation->socialAccount?->provider !== 'socialapi'
        ) {
            return [
                'imported' => 0,
                'has_more' => false,
                'next_cursor' => null,
                'conversation' => $this->toInboxArray($this->find($conversation->id)),
            ];
        }

        $limit = max(1, min(200, $limit));
        $imported = 0;
        $pageCursor = $cursor;
        $pages = 0;
        $maxPages = $exhaust ? 100 : 1;
        $hasMore = false;
        $nextCursor = null;

        do {
            $remote = $this->socialInbox->listMessages(
                $conversation->socialapi_conversation_id,
                $pageCursor,
                $limit,
            );

            $rows = $remote['data'] ?? $remote['messages'] ?? [];
            if (! is_array($rows)) {
                $rows = [];
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if ($this->upsertRemoteMessage($conversation, $row)) {
                    $imported++;
                }
            }

            $pagination = is_array($remote['pagination'] ?? null) ? $remote['pagination'] : [];
            $nextCursor = $pagination['next_cursor'] ?? $remote['next_cursor'] ?? null;
            if (is_string($nextCursor) && $nextCursor === '') {
                $nextCursor = null;
            }
            $hasMore = (bool) ($pagination['has_more'] ?? ($nextCursor !== null));
            $pageCursor = $nextCursor;
            $pages++;
        } while ($exhaust && $hasMore && $pageCursor && $pages < $maxPages);

        $this->repairConversationMessageOrder($conversation);

        $business = Business::query()->find($conversation->business_id);
        $fresh = $this->find($conversation->id, $business);
        try {
            Event::dispatch(new ConversationUpdated($fresh));
        } catch (\Throwable) {
            // History import must succeed even if realtime broadcast fails.
        }

        return [
            'imported' => $imported,
            'has_more' => $hasMore && (bool) $nextCursor,
            'next_cursor' => $nextCursor,
            'conversation' => $this->toInboxArray($fresh),
        ];
    }

    private function upsertRemoteMessage(Conversation $conversation, array $row): bool
    {
        $remoteId = $row['id'] ?? $row['message_id'] ?? null;
        if (! $remoteId) {
            return false;
        }

        $attachmentUrl = $row['attachment_url'] ?? null;
        if (is_string($attachmentUrl) && $attachmentUrl === '') {
            $attachmentUrl = null;
        }
        $attachmentType = $row['attachment_type'] ?? null;
        if (is_string($attachmentType) && $attachmentType === '') {
            $attachmentType = null;
        }

        $platformId = $row['platform_id'] ?? null;
        if (is_string($platformId) && $platformId === '') {
            $platformId = null;
        }

        $createdAt = null;
        if (! empty($row['created_at'])) {
            try {
                $createdAt = Carbon::parse($row['created_at']);
            } catch (\Throwable) {
                $createdAt = null;
            }
        }

        $existing = Message::query()->where('socialapi_message_id', $remoteId)->first();
        if (! $existing && $platformId) {
            // Webhooks often store a different SocialAPI id; platform_id is the stable key.
            // Search the whole shop so reconnect/history import cannot fork duplicates
            // into a second conversation for the same Meta message.
            $existing = Message::query()
                ->where('business_id', $conversation->business_id)
                ->where('metadata->platform_id', $platformId)
                ->orderBy('id')
                ->first();
        }

        // Outbound AI/human echoes sometimes lack platform_id until list sync — match text+time in-thread.
        if (! $existing) {
            $text = trim((string) ($row['text'] ?? $row['content'] ?? $row['message'] ?? ''));
            $direction = $this->mapRemoteDirection($row['direction'] ?? null);
            if ($text !== '' && $createdAt) {
                $existing = Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('direction', $direction)
                    ->where('text', $text)
                    ->whereBetween('created_at', [
                        $createdAt->copy()->subSeconds(90),
                        $createdAt->copy()->addSeconds(90),
                    ])
                    ->orderBy('id')
                    ->first();
            }
        }

        $probe = new Message([
            'media_url' => $attachmentUrl,
            'media_type' => $attachmentType,
            'type' => 'text',
            'metadata' => $row,
        ]);
        $kind = $this->resolveMediaKind($probe, $attachmentUrl) ?: 'text';

        if ($existing) {
            $dirty = false;
            if ((int) $existing->conversation_id !== (int) $conversation->id) {
                $existing->conversation_id = $conversation->id;
                $dirty = true;
            }
            if ($existing->socialapi_message_id !== $remoteId) {
                $existing->socialapi_message_id = $remoteId;
                $dirty = true;
            }
            if ($attachmentUrl && ! $existing->media_url) {
                $existing->media_url = $attachmentUrl;
                $dirty = true;
            }
            if ($attachmentType && ! $existing->media_type) {
                $existing->media_type = $attachmentType;
                $dirty = true;
            }
            if ($kind !== 'text' && $existing->type === 'text') {
                $existing->type = $kind;
                $dirty = true;
            }
            if ($createdAt && (
                ! $existing->created_at
                || abs($existing->created_at->diffInSeconds($createdAt)) > 1
            )) {
                $existing->created_at = $createdAt;
                $existing->updated_at = $createdAt;
                $dirty = true;
            }
            $meta = is_array($existing->metadata) ? $existing->metadata : [];
            $existing->metadata = array_merge($meta, $row);
            $dirty = true;
            if ($dirty) {
                $existing->save();
            }
            $this->applyRemoteMetaAdAttribution($conversation, $row);

            return false;
        }

        $direction = $this->mapRemoteDirection($row['direction'] ?? null);

        try {
            $message = new Message;
            $message->forceFill([
                'business_id' => $conversation->business_id,
                'conversation_id' => $conversation->id,
                'customer_id' => $conversation->customer_id,
                'socialapi_message_id' => $remoteId,
                'direction' => $direction,
                'type' => $kind,
                'sender_type' => $direction === 'inbound' ? 'customer' : 'user',
                'sender_id' => $direction === 'inbound' ? $conversation->customer_id : null,
                'text' => $row['text'] ?? '',
                'media_url' => $attachmentUrl,
                'media_type' => $attachmentType ?: ($kind !== 'text' ? $kind : null),
                'ai_generated' => false,
                'status' => $row['status'] ?? 'received',
                'metadata' => $row,
                'created_at' => $createdAt ?? now(),
                'updated_at' => $createdAt ?? now(),
            ]);
            $message->save();
        } catch (UniqueConstraintViolationException) {
            // Concurrent import already persisted this remote message.
            return false;
        } catch (\Throwable) {
            // Skip a single bad row so one oversized field cannot abort the whole page.
            return false;
        }

        $this->applyRemoteMetaAdAttribution($conversation, $row);

        if ($createdAt && (! $conversation->last_message_at || $createdAt->greaterThan($conversation->last_message_at))) {
            $conversation->forceFill(['last_message_at' => $createdAt])->save();
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function applyRemoteMetaAdAttribution(Conversation $conversation, array $row): void
    {
        $customer = $conversation->customer;
        if (! $customer && $conversation->customer_id) {
            $customer = $conversation->customer()->first();
        }
        if (! $customer) {
            return;
        }

        $this->customers->applyMetaAdAttribution($customer, $row, (int) $conversation->id);
    }

    private function mapRemoteDirection(?string $direction): string
    {
        $value = strtolower((string) $direction);

        return match ($value) {
            'out', 'outbound', 'outgoing', 'sent', 'business', 'page', 'agent' => 'outbound',
            'in', 'inbound', 'incoming', 'received', 'customer' => 'inbound',
            default => 'inbound',
        };
    }

    public function simulateInbound(array $data, ?Business $business = null): Conversation
    {
        $business ??= CurrentBusiness::require();
        $account = $business->simulatorAccount;
        abort_unless($account, 422, 'Simulator channel is missing.');

        $customer = $this->customers->findOrCreate($business, array_merge($data, [
            'platform' => 'simulator',
            'username' => $data['username'] ?? $data['name'] ?? null,
        ]));

        $conversation = Conversation::query()->firstOrCreate(
            [
                'business_id' => $business->id,
                'social_account_id' => $account->id,
                'customer_id' => $customer->id,
            ],
            [
                'platform' => 'simulator',
                'status' => 'open',
                'ai_enabled' => true,
            ],
        );

        $message = $this->storeMessage($conversation, [
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'sender_type' => 'customer',
            'sender_id' => $customer->id,
            'text' => $data['text'],
            'ai_generated' => false,
        ]);

        if ($conversation->ai_enabled) {
            ProcessIncomingMessageJob::dispatchFor($message);
        }

        return $this->find($conversation->id, $business);
    }

    public function humanReply(Conversation $conversation, User $user, string $text): Message
    {
        $message = $this->storeMessage($conversation, [
            'customer_id' => $conversation->customer_id,
            'direction' => 'outbound',
            'sender_type' => 'user',
            'sender_id' => $user->id,
            'text' => $text,
            'ai_generated' => false,
        ]);

        Bus::dispatch(new SendSocialMessageJob($message->id));

        return $message;
    }

    public function storeOutboundAi(Conversation $conversation, string $text, ?string $model = null, array $metadata = []): Message
    {
        $inbound = $conversation->messages()
            ->where('direction', 'inbound')
            ->latest('id')
            ->first();
        $inboundMeta = is_array($inbound?->metadata) ? $inbound->metadata : [];
        $kind = $metadata['kind'] ?? (($inbound?->type === 'comment' || isset($inboundMeta['post_id']) || isset($inboundMeta['metadata']['post_id'])) ? 'comment_reply' : null);

        $message = $this->storeMessage($conversation, [
            'customer_id' => $conversation->customer_id,
            'direction' => 'outbound',
            'sender_type' => 'agent',
            'type' => $kind === 'comment_reply' || $kind === 'private_reply' ? 'comment' : 'text',
            'text' => $text,
            'ai_generated' => true,
            'ai_model' => $model,
            'metadata' => array_filter(array_merge([
                'kind' => $kind,
                'post_id' => $this->extractPostId($inboundMeta),
                'comment_id' => $this->extractCommentId($inboundMeta),
                'inbound' => $inboundMeta ?: null,
            ], $metadata)),
        ]);

        if ($kind === 'comment_reply') {
            app(\App\Services\Comments\CommentReplyGuard::class)->markReplied(
                (int) $conversation->business_id,
                $this->extractCommentId($inboundMeta),
            );
            app(\App\Services\Comments\CommentReplyGuard::class)->hitPostRate(
                (int) $conversation->business_id,
                $this->extractPostId($inboundMeta),
            );
        }

        Bus::dispatch(new SendSocialMessageJob($message->id));

        return $message;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function extractPostId(array $meta): ?string
    {
        foreach ([
            $meta['post_id'] ?? null,
            $meta['platform_post_id'] ?? null,
            $meta['metadata']['post_id'] ?? null,
            $meta['post']['id'] ?? null,
            $meta['post']['platform_id'] ?? null,
        ] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function extractCommentId(array $meta): ?string
    {
        foreach ([
            $meta['comment_id'] ?? null,
            $meta['platform_comment_id'] ?? null,
            $meta['id'] ?? null,
            $meta['platform_id'] ?? null,
            $meta['metadata']['comment_id'] ?? null,
            $meta['metadata']['id'] ?? null,
            $meta['comment']['id'] ?? null,
            $meta['comment']['platform_id'] ?? null,
        ] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    public function storeMessage(Conversation $conversation, array $payload): Message
    {
        $message = Message::query()->create(array_merge([
            'business_id' => $conversation->business_id,
            'conversation_id' => $conversation->id,
            'type' => 'text',
            'status' => 'sent',
        ], $payload));

        $conversation->forceFill([
            'last_message_at' => now(),
            'status' => $conversation->status === 'closed' ? 'open' : $conversation->status,
        ])->save();

        Event::dispatch(new InboxMessageCreated($message));
        Event::dispatch(new ConversationUpdated($conversation));

        return $message;
    }

    public function handoff(Conversation $conversation, User $user): Conversation
    {
        $conversation->update([
            'ai_enabled' => false,
            'status' => 'human',
            'assigned_to' => $user->id,
        ]);

        $fresh = $conversation->fresh(['customer.socialProfiles', 'socialAccount', 'messages']);
        Event::dispatch(new ConversationUpdated($fresh));

        try {
            $business = \App\Models\Business::query()->find($fresh->business_id);
            if ($business) {
                app(\App\Services\Telegram\TelegramMerchantNotifier::class)->aiNeedsHuman(
                    $business,
                    $fresh,
                    'Inbox handoff by '.$user->name,
                );
            }
        } catch (\Throwable) {
        }

        return $fresh;
    }

    public function resumeAi(Conversation $conversation): Conversation
    {
        $conversation->update([
            'ai_enabled' => true,
            'status' => 'open',
        ]);

        $fresh = $conversation->fresh(['customer.socialProfiles', 'socialAccount', 'messages']);
        Event::dispatch(new ConversationUpdated($fresh));

        return $fresh;
    }

    public function toInboxArray(Conversation $conversation): array
    {
        $conversation->loadMissing(['customer.socialProfiles', 'socialAccount', 'messages']);
        $customer = $conversation->customer;
        $account = $conversation->socialAccount;
        $last = $this->sortedMessages($conversation)->last();
        $unreplied = $last && $last->direction === 'inbound';
        $profile = $this->profileFor($conversation);
        $metadata = is_array($customer?->metadata) ? $customer->metadata : [];
        $insights = $metadata['insights'] ?? [];
        $ads = $customer
            ? $this->customers->metaAdFields($customer, $conversation)
            : ['meta_ad_id' => null, 'meta_ad_title' => null];
        $pageName = $account?->name
            ?: ($account?->username ? '@'.ltrim((string) $account->username, '@') : null);

        return [
            'id' => $conversation->id,
            'name' => $customer?->name ?: ($profile?->username ?: 'Customer'),
            'city' => $customer?->wilaya ?: '',
            'wilaya' => $customer?->wilaya,
            'commune' => $customer?->commune,
            'phone' => $customer?->phone,
            'email' => $customer?->email,
            'language' => $customer?->language,
            'platform' => $conversation->platform,
            'username' => $profile?->username,
            'avatar_url' => $profile?->avatar_url ?: ($profile?->metadata['avatar_url'] ?? null),
            'profile_url' => $profile?->profile_url,
            'insights' => is_array($insights) ? $insights : [],
            'meta_ad_id' => $ads['meta_ad_id'],
            'meta_ad_title' => $ads['meta_ad_title'],
            'account_id' => $account?->id,
            'account_name' => $pageName,
            'account_username' => $account?->username,
            'account_avatar_url' => $account?->avatar_url,
            'channel' => $conversation->platform === 'simulator' ? 'Simulator' : ucfirst($conversation->platform),
            'assignment' => $conversation->assigned_to ? 'mine' : 'unassigned',
            'lifecycle' => $this->lifecycleFor($customer),
            'unreplied' => $unreplied,
            'blocked' => false,
            'status' => $conversation->status === 'closed' ? 'closed' : 'open',
            'ai_enabled' => (bool) $conversation->ai_enabled,
            'human' => $conversation->status === 'human' || ! $conversation->ai_enabled,
            'sortAt' => $conversation->last_message_at?->getTimestamp() * 1000 ?? $conversation->id,
            'time' => optional($conversation->last_message_at)?->diffForHumans() ?? 'now',
            'preview' => $this->previewFor($last),
            'messages' => $this->sortedMessages($conversation)
                ->map(fn (Message $m) => $this->messageToInboxArray($m))
                ->values()
                ->all(),
        ];
    }

    private function lifecycleFor(?\App\Models\Customer $customer): string
    {
        if (! $customer) {
            return 'new';
        }
        if (is_string($customer->lead_status) && $customer->lead_status !== '') {
            return $customer->lead_status;
        }
        if ($customer->lifecycle === 'customer') {
            return 'customer';
        }

        return 'new';
    }

    /**
     * Oldest → newest using SocialAPI metadata time when DB created_at was wrong.
     */
    private function sortedMessages(Conversation $conversation)
    {
        $conversation->loadMissing('messages');

        return $conversation->messages
            ->sort(function (Message $a, Message $b) {
                $ta = $this->effectiveMessageTimestamp($a);
                $tb = $this->effectiveMessageTimestamp($b);
                if ($ta === $tb) {
                    return $a->id <=> $b->id;
                }

                return $ta <=> $tb;
            })
            ->values();
    }

    private function effectiveMessageTimestamp(Message $message): float
    {
        $meta = is_array($message->metadata) ? $message->metadata : [];
        $raw = $meta['created_at'] ?? null;
        if (is_string($raw) && $raw !== '') {
            try {
                return (float) Carbon::parse($raw)->getPreciseTimestamp(6);
            } catch (\Throwable) {
                // fall through
            }
        }

        if ($message->created_at) {
            return (float) $message->created_at->getPreciseTimestamp(6);
        }

        return (float) $message->id;
    }

    /**
     * Public entry for reconnect / merge cleanup.
     */
    public function dedupeConversationMessages(int $conversationId): void
    {
        $conversation = Conversation::query()->find($conversationId);
        if (! $conversation) {
            return;
        }

        $this->repairConversationMessageOrder($conversation);
    }

    /**
     * Fix stored timestamps from metadata and drop duplicate platform_id rows.
     */
    private function repairConversationMessageOrder(Conversation $conversation): void
    {
        $messages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->get();

        $byPlatform = [];
        foreach ($messages as $message) {
            $meta = is_array($message->metadata) ? $message->metadata : [];
            if (! empty($meta['created_at'])) {
                try {
                    $remoteAt = Carbon::parse($meta['created_at']);
                    if (! $message->created_at || abs($message->created_at->diffInSeconds($remoteAt)) > 1) {
                        $message->forceFill([
                            'created_at' => $remoteAt,
                            'updated_at' => $message->updated_at ?: $remoteAt,
                        ])->save();
                    }
                } catch (\Throwable) {
                    // ignore bad remote timestamps
                }
            }

            $platformId = $meta['platform_id'] ?? null;
            if (! is_string($platformId) || $platformId === '') {
                continue;
            }

            if (! isset($byPlatform[$platformId])) {
                $byPlatform[$platformId] = $message;
                continue;
            }

            $keep = $byPlatform[$platformId];
            // Prefer the row that already has the UUID-style SocialAPI id + richer metadata.
            $keepIsUuid = (bool) preg_match('/^[0-9a-f-]{36}$/i', (string) $keep->socialapi_message_id);
            $msgIsUuid = (bool) preg_match('/^[0-9a-f-]{36}$/i', (string) $message->socialapi_message_id);

            if ($msgIsUuid && ! $keepIsUuid) {
                if ($keep->media_url && ! $message->media_url) {
                    $message->media_url = $keep->media_url;
                    $message->media_type = $message->media_type ?: $keep->media_type;
                }
                $message->save();
                $keep->delete();
                $byPlatform[$platformId] = $message;
            } else {
                if ($message->media_url && ! $keep->media_url) {
                    $keep->media_url = $message->media_url;
                    $keep->media_type = $keep->media_type ?: $message->media_type;
                    $keep->save();
                }
                $message->delete();
            }
        }

        Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('socialapi_message_id')
            ->where('metadata->source', 'socialapi_list_preview')
            ->delete();

        // Drop near-duplicate bubbles (webhook/AI echo + SocialAPI list of the same send).
        $ordered = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $prev = null;
        foreach ($ordered as $message) {
            if (! $prev) {
                $prev = $message;
                continue;
            }

            $sameText = trim((string) $prev->text) !== ''
                && trim((string) $prev->text) === trim((string) $message->text);
            $sameDirection = $prev->direction === $message->direction;
            $closeInTime = $prev->created_at && $message->created_at
                && abs($prev->created_at->diffInSeconds($message->created_at)) <= 15;

            if ($sameText && $sameDirection && $closeInTime) {
                $prevIsUuid = (bool) preg_match('/^[0-9a-f-]{36}$/i', (string) $prev->socialapi_message_id);
                $msgIsUuid = (bool) preg_match('/^[0-9a-f-]{36}$/i', (string) $message->socialapi_message_id);

                if ($msgIsUuid && ! $prevIsUuid) {
                    if ($prev->media_url && ! $message->media_url) {
                        $message->media_url = $prev->media_url;
                        $message->media_type = $message->media_type ?: $prev->media_type;
                        $message->save();
                    }
                    $prev->delete();
                    $prev = $message;
                } elseif ($prevIsUuid && ! $msgIsUuid) {
                    if ($message->media_url && ! $prev->media_url) {
                        $prev->media_url = $message->media_url;
                        $prev->media_type = $prev->media_type ?: $message->media_type;
                        $prev->save();
                    }
                    $message->delete();
                } elseif ($prev->ai_generated && ! $message->ai_generated) {
                    $prev->delete();
                    $prev = $message;
                } elseif ($message->ai_generated && ! $prev->ai_generated) {
                    $message->delete();
                } else {
                    // Same content twice — keep the earlier row.
                    $message->delete();
                }
                continue;
            }

            $prev = $message;
        }
    }

    public function messageToInboxArray(Message $message): array
    {
        $remoteMediaUrl = $this->resolveMediaUrl($message);
        $mediaKind = $this->resolveMediaKind($message, $remoteMediaUrl);
        $text = trim((string) ($message->text ?? ''));
        if ($remoteMediaUrl && $this->isAttachmentPlaceholder($text)) {
            $text = '';
        }

        // Same-origin proxy for images/videos/files — Facebook CDN often fails in the browser.
        // Keep raw URL for share links (open externally).
        $displayUrl = null;
        if ($remoteMediaUrl) {
            // Stable same-origin proxy URL (HMAC) so polling does not reshuffle signed query strings.
            $displayUrl = $mediaKind === 'share'
                ? $remoteMediaUrl
                : '/media/messages/'.$message->id.'?token='.hash_hmac(
                    'sha256',
                    'msg-media:'.$message->id,
                    (string) config('app.key'),
                );
        }

        return [
            'id' => $message->id,
            'from' => $message->direction === 'inbound' ? 'them' : ($message->ai_generated ? 'agent' : 'me'),
            'text' => $text,
            'ai_generated' => (bool) $message->ai_generated,
            'type' => $mediaKind ?: ($message->type ?: 'text'),
            'media_url' => $displayUrl,
            'media_type' => $mediaKind,
            'created_at' => $this->effectiveMessageIso($message),
            'time' => $this->formatMessageClock($message),
        ];
    }

    private function effectiveMessageIso(Message $message): string
    {
        $meta = is_array($message->metadata) ? $message->metadata : [];
        $raw = $meta['created_at'] ?? null;
        if (is_string($raw) && $raw !== '') {
            try {
                return Carbon::parse($raw)->toIso8601String();
            } catch (\Throwable) {
                // fall through
            }
        }

        return optional($message->created_at)?->toIso8601String() ?? '';
    }

    private function formatMessageClock(Message $message): string
    {
        $meta = is_array($message->metadata) ? $message->metadata : [];
        $raw = $meta['created_at'] ?? null;
        if (is_string($raw) && $raw !== '') {
            try {
                return Carbon::parse($raw)->timezone(config('app.timezone'))->format('H:i');
            } catch (\Throwable) {
                // fall through
            }
        }

        return optional($message->created_at)?->format('H:i') ?? '';
    }

    private function previewFor(?Message $message): string
    {
        if (! $message) {
            return 'No messages yet';
        }

        $text = trim((string) ($message->text ?? ''));
        if ($text !== '' && ! $this->isAttachmentPlaceholder($text)) {
            return $text;
        }

        return match ($this->resolveMediaKind($message, $this->resolveMediaUrl($message))) {
            'image' => 'Photo',
            'video' => 'Video',
            'audio' => 'Audio',
            'share' => 'Shared link',
            'file', 'attachment' => 'Attachment',
            default => $text !== '' ? $text : 'No messages yet',
        };
    }

    private function resolveMediaUrl(Message $message): ?string
    {
        $url = $message->media_url;
        if (is_string($url) && $url !== '') {
            return $url;
        }

        $meta = is_array($message->metadata) ? $message->metadata : [];
        foreach (['attachment_url', 'media_url', 'url'] as $key) {
            $value = $meta[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $content = is_array($meta['content'] ?? null) ? $meta['content'] : [];
        foreach (['attachment_url', 'media_url', 'url'] as $key) {
            $value = $content[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function resolveMediaKind(Message $message, ?string $mediaUrl): ?string
    {
        $raw = strtolower((string) ($message->media_type ?: $message->type ?: ''));
        $meta = is_array($message->metadata) ? $message->metadata : [];
        if ($raw === '' || $raw === 'text') {
            $raw = strtolower((string) ($meta['attachment_type'] ?? $meta['type'] ?? ''));
        }

        $kind = match (true) {
            in_array($raw, ['image', 'photo', 'img', 'sticker'], true) => 'image',
            in_array($raw, ['video', 'reel'], true) => 'video',
            in_array($raw, ['audio', 'voice', 'voice_note', 'ptt'], true) => 'audio',
            in_array($raw, ['share', 'link', 'story_share'], true) => 'share',
            in_array($raw, ['file', 'document', 'attachment', 'pdf'], true) => 'file',
            default => null,
        };

        if ($kind) {
            return $kind;
        }

        if (! $mediaUrl) {
            return null;
        }

        $path = strtolower((string) (parse_url($mediaUrl, PHP_URL_PATH) ?: $mediaUrl));

        return match (true) {
            (bool) preg_match('/\.(jpe?g|png|gif|webp|bmp|heic)(\?|$)/i', $path) => 'image',
            (bool) preg_match('/\.(mp4|webm|mov|m4v)(\?|$)/i', $path) => 'video',
            (bool) preg_match('/\.(mp3|ogg|wav|m4a|aac)(\?|$)/i', $path) => 'audio',
            default => 'file',
        };
    }

    private function isAttachmentPlaceholder(string $text): bool
    {
        $normalized = strtolower(trim($text));

        return in_array($normalized, [
            '[attachment]',
            '[image]',
            '[photo]',
            '[video]',
            '[audio]',
            '[file]',
            '(attachment)',
            'attachment',
        ], true);
    }

    private function profileFor(Conversation $conversation): ?CustomerSocialProfile
    {
        $profiles = $conversation->customer?->socialProfiles;
        if (! $profiles || $profiles->isEmpty()) {
            return null;
        }

        return $profiles->firstWhere('platform', $conversation->platform) ?? $profiles->first();
    }
}
