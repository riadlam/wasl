<?php

namespace Tests\Feature;

use App\Models\AgentChat;
use App\Models\AgentChatMessage;
use App\Models\User;
use App\Services\AgentChatTitleService;
use App\Services\ShopProvisioner;
use App\Support\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentChatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_chats_require_auth(): void
    {
        $this->getJson('/api/agent/chats')->assertUnauthorized();
        $this->postJson('/api/agent/chats')->assertUnauthorized();
        $this->deleteJson('/api/agent/chats/1')->assertUnauthorized();
    }

    public function test_list_create_delete_chats_scoped_to_business(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $otherOwner = User::factory()->create(['email' => 'other-owner@example.test']);
        $otherBusiness = app(ShopProvisioner::class)->createShop($otherOwner, 'Other Shop', [
            'wilaya' => 'Alger',
        ]);
        $foreign = AgentChat::query()->create([
            'business_id' => $otherBusiness->id,
            'user_id' => $otherOwner->id,
            'title' => 'Foreign chat',
            'last_message_at' => now(),
        ]);

        $this->asShopUser($owner, $business)
            ->getJson('/api/agent/chats')
            ->assertOk()
            ->assertJsonPath('chats', []);

        $created = $this->asShopUser($owner, $business)
            ->postJson('/api/agent/chats')
            ->assertCreated()
            ->assertJsonPath('chat.title', 'New chat')
            ->json('chat');

        $this->assertNotNull($created['id'] ?? null);
        $this->assertDatabaseHas('agent_chats', [
            'id' => $created['id'],
            'business_id' => $business->id,
            'user_id' => $owner->id,
        ]);

        $list = $this->asShopUser($owner, $business)
            ->getJson('/api/agent/chats')
            ->assertOk()
            ->json('chats');

        $this->assertCount(1, $list);
        $this->assertSame($created['id'], $list[0]['id']);
        $this->assertFalse(collect($list)->contains(fn ($c) => (int) $c['id'] === (int) $foreign->id));

        $this->asShopUser($owner, $business)
            ->deleteJson('/api/agent/chats/'.$foreign->id)
            ->assertNotFound();

        AgentChatMessage::query()->create([
            'business_id' => $business->id,
            'agent_chat_id' => $created['id'],
            'user_id' => $owner->id,
            'role' => 'user',
            'content' => 'hello',
            'meta' => [],
        ]);

        $this->asShopUser($owner, $business)
            ->deleteJson('/api/agent/chats/'.$created['id'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSoftDeleted('agent_chats', ['id' => $created['id']]);
        $this->assertDatabaseMissing('agent_chat_messages', [
            'agent_chat_id' => $created['id'],
        ]);

        $this->asShopUser($owner, $business)
            ->getJson('/api/agent/chats')
            ->assertOk()
            ->assertJsonPath('chats', []);
    }

    public function test_staff_without_manage_cannot_create_or_delete_chats(): void
    {
        ['staff' => $staff, 'business' => $business, 'owner' => $owner] = $this->makeShop();

        $staffMember = $business->members()->where('user_id', $staff->id)->first();
        $staffMember->update([
            'permissions' => array_values(array_unique(array_merge(
                $staffMember->permissions ?? [],
                [Permission::AgentsView->value],
            ))),
        ]);

        $chat = AgentChat::query()->create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'title' => 'Owner chat',
            'last_message_at' => now(),
        ]);

        $this->asShopUser($staff, $business)
            ->getJson('/api/agent/chats')
            ->assertOk();

        $this->asShopUser($staff, $business)
            ->postJson('/api/agent/chats')
            ->assertForbidden();

        $this->asShopUser($staff, $business)
            ->deleteJson('/api/agent/chats/'.$chat->id)
            ->assertForbidden();
    }

    public function test_chat_history_is_scoped_by_chat_id(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $chatA = AgentChat::query()->create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'title' => 'Chat A',
            'last_message_at' => now()->subMinute(),
        ]);
        $chatB = AgentChat::query()->create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'title' => 'Chat B',
            'last_message_at' => now(),
        ]);

        AgentChatMessage::query()->create([
            'business_id' => $business->id,
            'agent_chat_id' => $chatA->id,
            'user_id' => $owner->id,
            'role' => 'user',
            'content' => 'message in A',
            'meta' => [],
        ]);
        AgentChatMessage::query()->create([
            'business_id' => $business->id,
            'agent_chat_id' => $chatB->id,
            'user_id' => $owner->id,
            'role' => 'user',
            'content' => 'message in B',
            'meta' => [],
        ]);

        $this->asShopUser($owner, $business)
            ->getJson('/api/agent/chat?chat_id='.$chatA->id)
            ->assertOk()
            ->assertJsonPath('chat.id', $chatA->id)
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.content', 'message in A');

        $this->asShopUser($owner, $business)
            ->getJson('/api/agent/chat?chat_id='.$chatB->id)
            ->assertOk()
            ->assertJsonPath('chat.id', $chatB->id)
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.content', 'message in B');

        $this->asShopUser($owner, $business)
            ->getJson('/api/agent/chat?chat_id=999999')
            ->assertStatus(422);
    }

    public function test_first_message_without_chat_id_creates_chat_with_llm_title(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 200]);
        config(['services.fal.key' => 'fal_test_key']);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $body = $request->body();
            if (str_contains($body, 'You name shop-owner chat threads')) {
                return Http::response([
                    'choices' => [[
                        'message' => ['role' => 'assistant', 'content' => 'نشر بوست انستغرام'],
                    ]],
                    'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 6, 'cost' => 0.0002],
                ]);
            }

            return Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Salam!'],
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 0.001],
            ]);
        });

        $this->assertDatabaseCount('agent_chats', 0);

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/chat', ['text' => 'بغيت ننشر بوست على انستغرام'])
            ->assertOk()
            ->assertJsonPath('chat.title', 'نشر بوست انستغرام');

        $this->assertDatabaseCount('agent_chats', 1);
        $this->assertDatabaseHas('agent_chats', [
            'business_id' => $business->id,
            'title' => 'نشر بوست انستغرام',
        ]);
    }

    public function test_llm_title_uses_french_when_agent_language_french(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 200]);
        $business->agentSettings->update(['language' => 'French']);
        config(['services.fal.key' => 'fal_test_key']);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $body = $request->body();
            if (str_contains($body, 'You name shop-owner chat threads')) {
                $this->assertStringContainsString('Language: French', $body);

                return Http::response([
                    'choices' => [[
                        'message' => ['role' => 'assistant', 'content' => 'Publication Instagram promo'],
                    ]],
                    'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 4, 'cost' => 0.0002],
                ]);
            }

            return Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Bonjour!'],
                ]],
                'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 4, 'cost' => 0.001],
            ]);
        });

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/chat', ['text' => 'Je veux publier une promo sur Instagram'])
            ->assertOk()
            ->assertJsonPath('chat.title', 'Publication Instagram promo');
    }

    public function test_title_falls_back_to_user_snippet_when_llm_title_fails(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 200]);
        config(['services.fal.key' => 'fal_test_key']);

        $userText = 'Planifier une story pour demain matin';

        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($userText) {
            $body = $request->body();
            if (str_contains($body, 'You name shop-owner chat threads')) {
                return Http::response('upstream error', 502);
            }

            return Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'OK'],
                ]],
                'usage' => ['prompt_tokens' => 6, 'completion_tokens' => 2, 'cost' => 0.001],
            ]);
        });

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/chat', ['text' => $userText])
            ->assertOk()
            ->assertJsonPath('chat.title', mb_substr($userText, 0, 60));
    }

    public function test_title_service_sanitize_strips_quotes_and_clamps(): void
    {
        $service = app(AgentChatTitleService::class);
        $this->assertSame('Promo flash', $service->sanitizeTitle('"Promo flash"'));
        $this->assertLessThanOrEqual(40, mb_strlen($service->sanitizeTitle(str_repeat('mot ', 20))));
    }
}
