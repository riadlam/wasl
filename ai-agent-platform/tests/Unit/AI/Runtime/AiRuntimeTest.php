<?php

namespace Tests\Unit\AI\Runtime;

use App\AI\Runtime\AiRuntime;
use App\Models\Business;
use Tests\TestCase;

class AiRuntimeTest extends TestCase
{
    public function test_default_driver_is_php(): void
    {
        config(['ai_runtime.driver' => 'php']);
        $this->assertSame('php', AiRuntime::driver());
        $this->assertFalse(AiRuntime::usesSk());
    }

    public function test_per_business_sk_override(): void
    {
        config([
            'ai_runtime.driver' => 'php',
            'ai_runtime.sk_business_ids' => [42],
            'ai_runtime.php_business_ids' => [],
        ]);

        $business = new Business;
        $business->id = 42;

        $this->assertTrue(AiRuntime::usesSk($business));
    }

    public function test_per_business_php_override_wins(): void
    {
        config([
            'ai_runtime.driver' => 'sk',
            'ai_runtime.sk_business_ids' => [],
            'ai_runtime.php_business_ids' => [7],
        ]);

        $business = new Business;
        $business->id = 7;

        $this->assertFalse(AiRuntime::usesSk($business));
    }
}
