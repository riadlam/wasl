<?php

namespace Tests\Unit\Support;

use App\Support\MerchantSafeMessage;
use PHPUnit\Framework\TestCase;

class MerchantSafeMessageTest extends TestCase
{
    public function test_strips_provider_and_agent_internals(): void
    {
        $this->assertSame(
            'Could not generate preview.',
            MerchantSafeMessage::of('SocialAPI request failed: {"error":"boom"}', 'Could not generate preview.'),
        );
        $this->assertSame(
            'Something went wrong. Try again.',
            MerchantSafeMessage::of('CaptionApprover rejected after 3 rounds', 'Something went wrong. Try again.'),
        );
    }

    public function test_public_campaign_preview_drops_agents(): void
    {
        $out = MerchantSafeMessage::publicCampaignPreview([
            'caption' => 'Hello',
            'agents' => ['brief' => 'CampaignBriefAgent'],
            'approver' => ['agent' => 'CaptionApprover', 'approved' => true, 'needs_owner_edit' => false, 'rounds' => [1]],
            'trace_id' => 'abc',
            'meta' => 'Planned by PostCrafter',
            'plan_meta' => [
                'understanding' => 'Promo',
                'analysis' => 'secret',
                'regen_seeds' => ['x'],
            ],
        ]);

        $this->assertSame('Hello', $out['caption']);
        $this->assertArrayNotHasKey('agents', $out);
        $this->assertArrayNotHasKey('approver', $out);
        $this->assertArrayNotHasKey('trace_id', $out);
        $this->assertSame(['approved' => true, 'needs_edit' => false], $out['brand_check']);
        $this->assertSame('Planned by Wasl', $out['meta']);
        $this->assertSame(['understanding' => 'Promo'], $out['plan_meta']);
    }
}
