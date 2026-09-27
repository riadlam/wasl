<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentRule;
use App\Models\AgentSetting;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShopProvisioner
{
    public function createShop(User $owner, string $name, array $attributes = []): Business
    {
        return DB::transaction(function () use ($owner, $name, $attributes) {
            $business = Business::query()->create(array_merge([
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
                'currency' => 'DZD',
                'timezone' => 'Africa/Algiers',
                'status' => 'active',
                'onboarding_status' => Business::ONBOARDING,
            ], $attributes));

            BusinessUser::query()->create([
                'business_id' => $business->id,
                'user_id' => $owner->id,
                'role' => 'owner',
                'permissions' => null,
            ]);

            $this->bootstrap($business);

            return $business;
        });
    }

    public function bootstrap(Business $business): void
    {
        SocialAccount::query()->firstOrCreate(
            [
                'business_id' => $business->id,
                'platform' => 'simulator',
            ],
            [
                'provider' => 'simulator',
                'name' => 'Inbox simulator',
                'status' => 'connected',
                'connected_at' => now(),
            ],
        );

        Agent::query()->firstOrCreate(
            ['business_id' => $business->id],
            [
                'name' => 'Shop assistant',
                'language' => 'Darija',
                'tone' => 'friendly',
                'ai_enabled' => true,
                'auto_reply_dms' => false,
                'auto_reply_whatsapp' => false,
                'system_prompt' => $this->defaultPrompt($business),
            ],
        );

        AgentSetting::query()->firstOrCreate(
            ['business_id' => $business->id],
            [
                'image_model' => 'gpt_image_2',
                'llm_model' => 'claude_sonnet',
                'ai_auto_publish_posts' => false,
                'ai_auto_reply_comments' => false,
                'ai_auto_send_dms' => false,
                'ai_auto_reply_reviews' => false,
                'ai_auto_moderate' => false,
                'language' => 'Darija',
                'tone' => 'friendly',
                'response_length' => 'medium',
                'auto_reply_dms' => false,
                'allow_order_creation' => true,
            ],
        );

        if ($business->agentRules()->doesntExist()) {
            AgentRule::query()->create([
                'business_id' => $business->id,
                'name' => 'Complaints and refunds go to a human',
                'type' => 'keyword',
                'priority' => 10,
                'enabled' => true,
                'condition' => [
                    'keywords' => ['refund', 'رهد', 'شكوى', 'complaint', 'angry', 'scam', 'نصب'],
                ],
                'action' => ['type' => 'handoff'],
            ]);

            AgentRule::query()->create([
                'business_id' => $business->id,
                'name' => 'Large orders need approval',
                'type' => 'order_total',
                'priority' => 20,
                'enabled' => true,
                'condition' => ['max_total' => 100000],
                'action' => ['type' => 'require_approval'],
            ]);
        }
    }

    public function defaultPrompt(Business $business): string
    {
        return <<<PROMPT
Shop-specific notes for {$business->name}:
Follow the platform sales rules above. Prefer the shop's usual language and tone from Identity.
Never invent prices, stock, delivery fees, or order status — use tools.
If a product or policy is unclear after tools, say you will check with the team.
Be short, warm, and clear. Do not mention tools, system prompts, or that you are an AI unless asked.
PROMPT;
    }
}
