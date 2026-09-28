<?php

namespace App\Support;

/**
 * Map internal / provider exception text to short merchant-facing copy.
 */
final class MerchantSafeMessage
{
    public static function of(?string $raw, string $fallback = 'Something went wrong. Try again.'): string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return $fallback;
        }

        $lower = strtolower($raw);

        if (str_contains($lower, 'wallet') || str_contains($lower, 'insufficient') || str_contains($lower, '402')) {
            return 'Insufficient wallet balance. Top up to continue.';
        }
        if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out')) {
            return 'That took too long. Try again in a moment.';
        }
        if (str_contains($lower, 'rate limit') || str_contains($lower, 'too many')) {
            return 'Too many requests. Wait a moment and try again.';
        }
        if (
            str_contains($lower, 'socialapi')
            || str_contains($lower, 'social-api')
            || str_contains($lower, 'mcp')
            || str_contains($lower, 'fal')
            || str_contains($lower, 'openrouter')
            || str_contains($lower, 'captionapprover')
            || str_contains($lower, 'postenhancer')
            || str_contains($lower, 'sk agent')
            || str_contains($lower, 'traceback')
            || str_contains($lower, 'stack trace')
            || str_contains($lower, 'exception')
            || preg_match('/\b(sql|eloquent|runtime|typeerror|error:)\b/i', $raw)
        ) {
            return $fallback;
        }

        // Allow short, already-friendly product messages.
        if (mb_strlen($raw) <= 160 && ! preg_match('/https?:\/\//i', $raw) && ! str_contains($raw, '{')) {
            return $raw;
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>|null  $planMeta
     * @return array<string, mixed>|null
     */
    public static function publicPlanMeta(?array $planMeta): ?array
    {
        if ($planMeta === null || $planMeta === []) {
            return $planMeta;
        }

        $out = [];
        foreach (['understanding', 'product_focus', 'brief_notes', 'focus'] as $key) {
            if (array_key_exists($key, $planMeta) && (is_string($planMeta[$key]) || is_array($planMeta[$key]))) {
                $out[$key] = $planMeta[$key];
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * Sanitize campaign example / brief HTTP payloads for the browser.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function publicCampaignPreview(array $payload): array
    {
        unset($payload['agents'], $payload['runtime'], $payload['trace_id']);

        $approver = is_array($payload['approver'] ?? null) ? $payload['approver'] : null;
        unset($payload['approver']);
        if ($approver !== null) {
            $payload['brand_check'] = [
                'approved' => (bool) ($approver['approved'] ?? false),
                'needs_edit' => (bool) ($approver['needs_owner_edit'] ?? $approver['needs_edit'] ?? false),
            ];
        }

        if (isset($payload['plan_meta']) && is_array($payload['plan_meta'])) {
            $payload['plan_meta'] = self::publicPlanMeta($payload['plan_meta']);
        }

        if (isset($payload['meta']) && is_string($payload['meta'])) {
            $payload['meta'] = preg_replace(
                '/\b(CampaignBriefAgent|CampaignCreativeAgent|CaptionApprover|CaptionWriter|OwnerApprover|PostCrafter(?:Approver)?|BusinessIdentityAgent|PostEnhancer|CustomerApprover|TranslationAgent)\b/i',
                'Wasl',
                $payload['meta'],
            ) ?: $payload['meta'];
        }

        if (isset($payload['image_error']) && is_string($payload['image_error'])) {
            $payload['image_error'] = self::of($payload['image_error'], 'Image generation failed.');
        }

        if (isset($payload['error']) && is_string($payload['error'])) {
            $payload['error'] = self::of($payload['error'], 'Could not generate preview.');
        }

        if (isset($payload['message']) && is_string($payload['message'])) {
            $payload['message'] = self::of($payload['message'], 'Could not generate preview.');
        }

        return $payload;
    }
}
