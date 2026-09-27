<?php

namespace App\Services\SocialApi;

use App\Models\Conversation;
use App\Models\Message;

class SocialApiInboxService
{
    public function __construct(private SocialApiClient $client) {}

    public function sendMessage(Conversation $conversation, Message $message): array
    {
        $account = $conversation->socialAccount;
        $conversationId = $conversation->socialapi_conversation_id;

        if (! $account?->socialapi_account_id || ! $conversationId) {
            return ['skipped' => true];
        }

        $result = $this->client->post('/inbox/conversations/'.$conversationId.'/messages', [
            'account_id' => $account->socialapi_account_id,
            'text' => $message->text,
        ]);

        $remoteId = $result['id'] ?? $result['message_id'] ?? null;
        if ($remoteId) {
            $message->update(['socialapi_message_id' => $remoteId, 'status' => 'sent']);
        }

        return $result;
    }

    public function replyToComment(string $postId, string $accountId, string $commentId, string $text): array
    {
        return $this->client->post('/inbox/comments/'.$postId, [
            'account_id' => $accountId,
            'comment_id' => $commentId,
            'text' => $text,
        ]);
    }

    /**
     * Send a one-time private reply (DM) to a commenter.
     *
     * @see https://docs.social-api.ai/api-reference/inbox-comments/send-a-private-reply-to-a-commenter
     */
    public function privateReply(string $postId, string $accountId, string $commentId, string $text): array
    {
        return $this->client->post('/inbox/comments/'.$postId.'/'.$commentId.'/private-reply', [
            'account_id' => $accountId,
            'text' => $text,
        ]);
    }

    public function conversation(string $conversationId): array
    {
        return $this->client->get('/inbox/conversations/'.$conversationId);
    }

    /**
     * List DM threads from SocialAPI (same source as app.social-api.ai/inbox).
     * @see https://docs.social-api.ai/api-reference/inbox/list-inbox-conversations
     */
    public function listConversations(?string $accountId = null, ?string $cursor = null, int $limit = 50, ?string $platform = null): array
    {
        return $this->client->get('/inbox/conversations', array_filter([
            'account_id' => $accountId,
            'platform' => $platform,
            'status' => 'active',
            'limit' => max(1, min(100, $limit)),
            'cursor' => $cursor,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * Paginated DM history from SocialAPI (newest first).
     * @see https://docs.social-api.ai/api-reference/inbox/list-messages-in-a-conversation
     */
    public function listMessages(string $conversationId, ?string $cursor = null, int $limit = 50): array
    {
        return $this->client->get('/inbox/conversations/'.$conversationId.'/messages', array_filter([
            'limit' => max(1, min(200, $limit)),
            'cursor' => $cursor,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * Comments on a platform post (inbox comments API).
     *
     * @see https://docs.social-api.ai/api-reference/inbox-comments/list-comments-on-a-post
     */
    public function listCommentsOnPost(string $platformPostId, string $accountId, ?string $cursor = null, int $limit = 20): array
    {
        return $this->client->get('/inbox/comments/'.rawurlencode($platformPostId), array_filter([
            'account_id' => $accountId,
            'limit' => max(1, min(100, $limit)),
            'cursor' => $cursor,
        ], fn ($value) => $value !== null && $value !== ''));
    }
}
