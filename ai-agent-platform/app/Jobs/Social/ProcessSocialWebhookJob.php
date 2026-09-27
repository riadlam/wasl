<?php

namespace App\Jobs\Social;

use App\Jobs\AI\ProcessIncomingMessageJob;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SocialAccount;
use App\Models\WebhookEvent;
use App\Services\CustomerService;
use App\Services\OnboardingService;
use App\Services\SocialApi\SocialApiAccountService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use App\Events\ConversationUpdated;
use App\Events\InboxMessageCreated;

class ProcessSocialWebhookJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $webhookEventId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(CustomerService $customers, SocialApiAccountService $accounts, OnboardingService $onboarding): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);
        if (! $event || $event->processed_at) {
            return;
        }

        try {
            $type = $event->event_type;
            $data = $event->payload['data'] ?? $event->payload;

            match ($type) {
                'dm.received' => $this->ingestInbound($event, $data, $customers, 'text', true),
                'dm.referral' => $this->ingestInbound($event, $data, $customers, 'text', false),
                'comment.received' => $this->ingestInbound($event, $data, $customers, 'comment', true),
                'dm.sent' => $this->ingestEcho($event, $data),
                'account.connected' => $this->handleAccountConnected($accounts, $onboarding, is_array($data) ? $data : []),
                'account.disconnected' => $accounts->markDisconnected(is_array($data) ? $data : []),
                default => null,
            };

            $event->update(['processed_at' => now(), 'failed_at' => null]);
        } catch (\Throwable $e) {
            $event->update(['failed_at' => now()]);
            throw $e;
        }
    }

    private function ingestInbound(WebhookEvent $event, array $data, CustomerService $customers, string $type, bool $runAgent): void
    {
        $account = SocialAccount::query()
            ->where('socialapi_account_id', $data['account_id'] ?? '')
            ->first();

        if (! $account) {
            return;
        }

        $event->update(['business_id' => $account->business_id]);
        $business = $account->business;
        $author = is_array($data['author'] ?? null) ? $data['author'] : [];
        $customer = $customers->findOrCreate($business, [
            'name' => $author['name'] ?? $author['username'] ?? $author['handle'] ?? 'Customer',
            'phone' => $author['phone'] ?? $author['phone_number'] ?? null,
            'platform' => $data['platform'] ?? $account->platform,
            'platform_user_id' => $author['id'] ?? $author['user_id'] ?? $author['platform_user_id'] ?? null,
            'username' => $author['username'] ?? $author['handle'] ?? null,
            'profile_url' => $author['profile_url'] ?? $author['url'] ?? null,
            'avatar_url' => $author['profile_picture_url'] ?? $author['avatar_url'] ?? $author['picture'] ?? null,
        ]);

        $threadId = $data['conversation_id'] ?? $data['thread_id'] ?? $data['id'] ?? null;

        $conversation = Conversation::query()->firstOrCreate(
            [
                'business_id' => $business->id,
                'social_account_id' => $account->id,
                'socialapi_conversation_id' => $threadId,
            ],
            [
                'platform' => $data['platform'] ?? $account->platform,
                'customer_id' => $customer->id,
                'status' => 'open',
                'ai_enabled' => true,
            ],
        );

        if (! $conversation->customer_id) {
            $conversation->update(['customer_id' => $customer->id]);
        }

        // After conversation exists so each thread keeps its own ad (not only the first).
        $customers->applyMetaAdAttribution($customer, is_array($data) ? $data : [], (int) $conversation->id);

        $remoteMessageId = $data['id'] ?? null;
        if ($remoteMessageId && Message::query()->where('socialapi_message_id', $remoteMessageId)->exists()) {
            // Merge referral onto the existing message metadata when a later dm.referral arrives.
            $existingMsg = Message::query()->where('socialapi_message_id', $remoteMessageId)->first();
            if ($existingMsg) {
                $merged = array_replace_recursive(
                    is_array($existingMsg->metadata) ? $existingMsg->metadata : [],
                    is_array($data) ? $data : [],
                );
                $existingMsg->metadata = $merged;
                $existingMsg->save();
            }
            Event::dispatch(new ConversationUpdated($conversation));

            return;
        }

        $text = $data['content']['text'] ?? $data['text'] ?? '';
        $attachmentUrl = $data['attachment_url']
            ?? $data['content']['attachment_url']
            ?? $data['media_url']
            ?? null;
        $attachmentType = $data['attachment_type']
            ?? $data['content']['attachment_type']
            ?? $data['media_type']
            ?? null;
        if (is_string($attachmentUrl) && $attachmentUrl === '') {
            $attachmentUrl = null;
        }
        if (is_string($attachmentType) && $attachmentType === '') {
            $attachmentType = null;
        }

        $messageType = $type;
        if ($attachmentUrl || $attachmentType) {
            $normalized = strtolower((string) ($attachmentType ?: ''));
            $messageType = match (true) {
                in_array($normalized, ['image', 'photo', 'img', 'sticker'], true) => 'image',
                in_array($normalized, ['video', 'reel'], true) => 'video',
                in_array($normalized, ['audio', 'voice', 'voice_note', 'ptt'], true) => 'audio',
                in_array($normalized, ['share', 'link', 'story_share'], true) => 'share',
                $attachmentUrl !== null => 'file',
                default => $type,
            };
        }

        $message = Message::query()->create([
            'business_id' => $business->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'socialapi_message_id' => $remoteMessageId,
            'direction' => 'inbound',
            'type' => $messageType,
            'sender_type' => 'customer',
            'sender_id' => $customer->id,
            'text' => $text,
            'media_url' => $attachmentUrl,
            'media_type' => $attachmentType ?: ($messageType !== 'text' && $messageType !== 'comment' ? $messageType : null),
            'ai_generated' => false,
            'status' => 'received',
            'metadata' => $data,
        ]);

        $conversation->forceFill(['last_message_at' => now(), 'status' => 'open'])->save();
        Event::dispatch(new InboxMessageCreated($message));
        Event::dispatch(new ConversationUpdated($conversation));

        $business->loadMissing('agent');
        $agentOn = $runAgent && $conversation->ai_enabled && (! $business->agent || $business->agent->ai_enabled);
        $postId = null;
        if ($type === 'comment') {
            $postId = $this->extractPlatformPostId($data);
        }

        $classify = $agentOn && app(\App\Services\WorkflowService::class)->hasActiveLeadWorkflows($business);
        $dmRun = $type === 'text' && $agentOn;

        if ($type === 'comment' && $postId) {
            app(\App\Services\PostCommentWorkflowRunner::class)->dispatchForInbound(
                $message,
                $business,
                (int) $account->id,
                $postId,
                $runAgent,
                (bool) $conversation->ai_enabled,
                $agentOn,
                $classify,
                $dmRun,
            );

            return;
        }

        if ($type === 'text' && $runAgent && $conversation->ai_enabled) {
            $handled = app(\App\Services\DmKeywordWorkflowRunner::class)->dispatchForInbound(
                $message,
                $business,
                (string) ($account->platform ?? $conversation->platform ?? ''),
                (string) ($text ?? ''),
                $agentOn,
                $classify,
            );
            if ($handled) {
                return;
            }
        }

        if ($dmRun || ($type === 'comment' && $classify)) {
            ProcessIncomingMessageJob::dispatchFor($message);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractPlatformPostId(array $data): ?string
    {
        foreach ([
            $data['post_id'] ?? null,
            $data['platform_post_id'] ?? null,
            $data['metadata']['post_id'] ?? null,
            $data['post']['id'] ?? null,
            $data['post']['platform_id'] ?? null,
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

    private function ingestEcho(WebhookEvent $event, array $data): void
    {
        $remoteId = $data['id'] ?? null;
        if ($remoteId && Message::query()->where('socialapi_message_id', $remoteId)->exists()) {
            return;
        }

        $account = SocialAccount::query()->where('socialapi_account_id', $data['account_id'] ?? '')->first();
        if (! $account) {
            return;
        }

        $event->update(['business_id' => $account->business_id]);
        $threadId = $data['conversation_id'] ?? $data['thread_id'] ?? null;
        $conversation = $threadId
            ? Conversation::query()->where('socialapi_conversation_id', $threadId)->first()
            : null;

        if (! $conversation) {
            return;
        }

        $echoText = $data['content']['text'] ?? $data['text'] ?? '';
        $echoAttachmentUrl = $data['attachment_url']
            ?? $data['content']['attachment_url']
            ?? $data['media_url']
            ?? null;
        $echoAttachmentType = $data['attachment_type']
            ?? $data['content']['attachment_type']
            ?? $data['media_type']
            ?? null;
        if (is_string($echoAttachmentUrl) && $echoAttachmentUrl === '') {
            $echoAttachmentUrl = null;
        }
        if (is_string($echoAttachmentType) && $echoAttachmentType === '') {
            $echoAttachmentType = null;
        }
        $echoType = 'text';
        if ($echoAttachmentUrl || $echoAttachmentType) {
            $normalized = strtolower((string) ($echoAttachmentType ?: ''));
            $echoType = match (true) {
                in_array($normalized, ['image', 'photo', 'img', 'sticker'], true) => 'image',
                in_array($normalized, ['video', 'reel'], true) => 'video',
                in_array($normalized, ['audio', 'voice', 'voice_note', 'ptt'], true) => 'audio',
                in_array($normalized, ['share', 'link', 'story_share'], true) => 'share',
                default => 'file',
            };
        }

        $echo = Message::query()->create([
            'business_id' => $account->business_id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'socialapi_message_id' => $remoteId,
            'direction' => 'outbound',
            'type' => $echoType,
            'sender_type' => 'user',
            'text' => $echoText,
            'media_url' => $echoAttachmentUrl,
            'media_type' => $echoAttachmentType ?: ($echoType !== 'text' ? $echoType : null),
            'ai_generated' => false,
            'status' => 'sent',
            'metadata' => $data,
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();
        Event::dispatch(new InboxMessageCreated($echo));
        Event::dispatch(new ConversationUpdated($conversation));
    }

    private function handleAccountConnected(SocialApiAccountService $accounts, OnboardingService $onboarding, array $data): void
    {
        $account = $accounts->markConnected($data);
        if ($account?->business) {
            $onboarding->onChannelConnected($account->business, $account);
        }
    }
}
