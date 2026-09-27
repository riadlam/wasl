<?php

namespace App\AI\Runtime;

use App\Models\Business;

final class AiRuntime
{
    public static function driver(?Business $business = null): string
    {
        $default = (string) config('ai_runtime.driver', 'php');
        if (! $business) {
            return $default === 'sk' ? 'sk' : 'php';
        }

        $id = (int) $business->id;
        $forceSk = config('ai_runtime.sk_business_ids', []);
        $forcePhp = config('ai_runtime.php_business_ids', []);

        if (in_array($id, $forcePhp, true)) {
            return 'php';
        }
        if (in_array($id, $forceSk, true)) {
            return 'sk';
        }

        return $default === 'sk' ? 'sk' : 'php';
    }

    public static function usesSk(?Business $business = null): bool
    {
        return self::driver($business) === 'sk';
    }
}
