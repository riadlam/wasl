<?php

namespace Tests\Unit\AI;

use App\AI\ImageOnImageLanguage;
use App\Models\Business;
use Mockery;
use Tests\TestCase;

class ImageOnImageLanguageTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_strips_campaign_meta_from_hints(): void
    {
        $meta = "نديرو بوستات جداد على واش درنا مقبل\nفي البوستات الجاية نفس الكونسبت";
        $this->assertTrue(ImageOnImageLanguage::looksLikeCampaignMeta($meta));
        $this->assertSame('', ImageOnImageLanguage::sanitizeMarketingHints($meta));
    }

    public function test_keeps_marketing_darija_slogan(): void
    {
        $ok = 'اشحن الآن والعب بدون حدود';
        $this->assertFalse(ImageOnImageLanguage::looksLikeCampaignMeta($ok));
        $this->assertSame($ok, ImageOnImageLanguage::sanitizeMarketingHints($ok));
    }

    public function test_enrich_does_not_suggest_owner_brief_meta(): void
    {
        $business = Mockery::mock(Business::class);
        $business->shouldReceive('loadMissing')->andReturnSelf();
        $business->shouldReceive('getAttribute')->with('agentSettings')->andReturn(null);
        $business->shouldReceive('getAttribute')->with('agent')->andReturn(null);
        $business->shouldReceive('offsetExists')->andReturn(false);

        $enriched = ImageOnImageLanguage::enrich(
            $business,
            'Photoreal game top-up creative for Facebook, MLBB diamonds, no phones.',
            "Owner: ndiro posts jdod\nنديرو بوستات جداد على واش درنا مقبل",
        );

        $this->assertStringNotContainsString('نديرو بوستات جداد', $enriched);
        $this->assertStringNotContainsString('Suggested Darija/brand lines to render: "نديرو', $enriched);
        $this->assertStringContainsString('FORBIDDEN', $enriched);
    }
}
