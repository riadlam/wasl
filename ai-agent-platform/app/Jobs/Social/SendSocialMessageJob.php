<?php

namespace App\Jobs\Social;

use App\Models\Message;
use App\Services\SocialApi\SocialApiInboxService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendSocialMessageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $messageId)
    {
        $this->onQueue('social');
    }

    public function handle(SocialApiInboxService $inbox): void
    {
        $message = Message::query()->with('conversation.socialAccount')->find($this->messageId);
        if (! $message || $message->direction !== 'outbound') {
            return;
        }

        $conversation = $message->conversation;
        if (! $conversation || $conversation->platform === 'simulator') {
            return;
        }

        if ($conversation->socialAccount?->provider !== 'socialapi') {
            return;
        }

        $meta = is_array($message->metadata) ? $message->metadata : [];
        $inboundMeta = is_array($meta['inbound'] ?? null) ? $meta['inbound'] : [];
        $kind = $meta['kind'] ?? null;

        if ($kind === 'private_reply' || ($conversation->type ?? null) === 'comment' || $this->isCommentReply($message, $meta, $inboundMeta)) {
            $postId = $this->firstString([
                $meta['post_id'] ?? null,
                $inboundMeta['post_id'] ?? null,
                $inboundMeta['metadata']['post_id'] ?? null,
                $inboundMeta['post']['id'] ?? null,
            ]);
            $commentId = $this->firstString([
                $meta['comment_id'] ?? null,
                $inboundMeta['id'] ?? null,
                $inboundMeta['comment_id'] ?? null,
                $inboundMeta['platform_id'] ?? null,
            ]);
            $accountId = $conversation->socialAccount?->socialapi_account_id;
            if ($postId && $commentId && $accountId) {
                if (($kind ?? '') === 'private_reply') {
                    $inbox->privateReply($postId, $accountId, $commentId, (string) $message->text);
                } else {
                    $inbox->replyToComment($postId, $accountId, $commentId, (string) $message->text);
                }

                return;
            }
        }

        if (! $conversation->socialapi_conversation_id) {
            return;
        }

        $inbox->sendMessage($conversation, $message);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $inboundMeta
     */
    private function isCommentReply(Message $message, array $meta, array $inboundMeta): bool
    {
        if (($message->type ?? '') === 'comment') {
            return true;
        }

        return isset($inboundMeta['post_id'])
            || isset($inboundMeta['metadata']['post_id'])
            || isset($meta['post_id']);
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
