<?php

namespace Tests\Unit\AI;

use App\AI\Agents\CaptionApprover;
use App\AI\Providers\FalLlmProvider;
use App\AI\Tools\Owner\GetBusinessContext;
use Tests\TestCase;

class CaptionApproverTest extends TestCase
{
    public function test_parse_approved_json(): void
    {
        $approver = $this->approver();
        $parsed = $approver->parse(json_encode([
            'decision' => 'approved',
            'score' => 0.91,
            'reasons' => ['tone_ok'],
            'feedback' => '',
        ]));

        $this->assertSame('approved', $parsed['decision']);
        $this->assertSame(0.91, $parsed['score']);
        $this->assertSame(['tone_ok'], $parsed['reasons']);
    }

    public function test_parse_invalid_defaults_to_rejected(): void
    {
        $approver = $this->approver();
        $parsed = $approver->parse('not-json');

        $this->assertSame('rejected', $parsed['decision']);
        $this->assertContains('invalid_approver_json', $parsed['reasons']);
    }

    public function test_post_intent_detection(): void
    {
        $approver = $this->approver();
        $this->assertTrue($approver->looksLikePostIntent('create a post for facebook'));
        $this->assertTrue($approver->looksLikePostIntent('كتبلي بوست'));
        $this->assertFalse($approver->looksLikePostIntent('list my orders'));
    }

    public function test_post_draft_skill_mentions_caption_approver(): void
    {
        $skill = file_get_contents(resource_path('ai-skills/post-draft/SKILL.md'));
        $this->assertIsString($skill);
        $this->assertStringContainsString('CaptionApprover', $skill);
        $this->assertStringContainsString('max 3 rounds', $skill);
    }

    private function approver(): CaptionApprover
    {
        return new CaptionApprover(
            $this->createMock(FalLlmProvider::class),
            $this->createMock(GetBusinessContext::class),
        );
    }
}
