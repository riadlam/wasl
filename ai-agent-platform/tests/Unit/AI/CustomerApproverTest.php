<?php

namespace Tests\Unit\AI;

use App\AI\Agents\CustomerApprover;
use App\AI\Providers\FalLlmProvider;
use Tests\TestCase;

class CustomerApproverTest extends TestCase
{
    public function test_parse_approved(): void
    {
        $approver = new CustomerApprover($this->createMock(FalLlmProvider::class));
        $parsed = $approver->parse(json_encode([
            'decision' => 'approved',
            'score' => 0.88,
            'reasons' => ['grounded'],
            'feedback' => '',
        ]));

        $this->assertSame('approved', $parsed['decision']);
        $this->assertSame(0.88, $parsed['score']);
    }

    public function test_parse_rejected_on_garbage(): void
    {
        $approver = new CustomerApprover($this->createMock(FalLlmProvider::class));
        $parsed = $approver->parse('nope');
        $this->assertSame('rejected', $parsed['decision']);
    }
}
