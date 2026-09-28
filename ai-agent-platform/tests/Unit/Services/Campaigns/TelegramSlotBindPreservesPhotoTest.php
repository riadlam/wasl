<?php

namespace Tests\Unit\Services\Campaigns;

use App\Models\AiCampaign;
use App\Models\AiCampaignSlot;
use App\Models\Business;
use App\Models\TelegramOutboundMessage;
use App\Models\User;
use App\Services\Campaigns\CampaignSlotApprovalService;
use App\Services\ShopProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramSlotBindPreservesPhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_bind_preserves_has_photo_metadata(): void
    {
        $owner = User::factory()->create();
        $business = app(ShopProvisioner::class)->createShop($owner, 'TG Shop');

        $campaign = AiCampaign::query()->create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'name' => 'C',
            'status' => 'active',
            'content_mode' => 'ai_recent',
            'day_count' => 1,
            'starts_on' => now()->toDateString(),
            'timezone' => 'UTC',
        ]);

        $slot = AiCampaignSlot::query()->create([
            'ai_campaign_id' => $campaign->id,
            'day_index' => 1,
            'slot_kind' => 'post',
            'status' => AiCampaignSlot::STATUS_AWAITING_APPROVAL,
            'scheduled_at' => now()->addHour(),
            'caption' => 'hi',
        ]);

        TelegramOutboundMessage::query()->create([
            'business_id' => $business->id,
            'subject_type' => AiCampaignSlot::class,
            'subject_id' => $slot->id,
            'kind' => TelegramOutboundMessage::KIND_CAMPAIGN_SLOT_APPROVAL,
            'chat_id' => '111',
            'message_id' => '222',
            'metadata' => ['has_photo' => true, 'asset_id' => 9, 'keyboard' => true],
        ]);

        app(CampaignSlotApprovalService::class)->bindTelegramMessage(
            $business,
            $slot,
            '111',
            '222',
            true,
        );

        $row = TelegramOutboundMessage::query()
            ->where('subject_id', $slot->id)
            ->where('kind', TelegramOutboundMessage::KIND_CAMPAIGN_SLOT_APPROVAL)
            ->first();

        $this->assertNotNull($row);
        $this->assertTrue((bool) ($row->metadata['has_photo'] ?? false));
        $this->assertSame(9, (int) ($row->metadata['asset_id'] ?? 0));
        $this->assertTrue((bool) ($row->metadata['bound_from_callback'] ?? false));
    }
}
