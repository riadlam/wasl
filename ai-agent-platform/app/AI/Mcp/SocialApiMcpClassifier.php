<?php

namespace App\AI\Mcp;

class SocialApiMcpClassifier
{
    /**
     * @return array{mutate: bool, category: ?string, setting: ?string}
     */
    public function classify(string $name): array
    {
        $name = strtolower($name);
        $forceRead = array_map('strtolower', (array) config('socialapi_mcp.force_read', []));
        $forceMutate = array_map('strtolower', (array) config('socialapi_mcp.force_mutate', []));

        if (in_array($name, $forceRead, true)) {
            return ['mutate' => false, 'category' => null, 'setting' => null];
        }

        $mapped = config('socialapi_mcp.tool_category.'.$name);
        $category = is_string($mapped) ? $mapped : $this->guessCategory($name);
        $mutate = in_array($name, $forceMutate, true) || $this->looksMutating($name);

        $setting = null;
        if ($mutate && $category) {
            $column = config('socialapi_mcp.categories.'.$category);
            $setting = is_string($column) ? $column : null;
        }

        return [
            'mutate' => $mutate,
            'category' => $mutate ? ($category ?: 'moderate') : null,
            'setting' => $mutate ? ($setting ?: 'ai_auto_moderate') : null,
        ];
    }

    private function guessCategory(string $name): ?string
    {
        if (str_contains($name, 'comment')) {
            return 'comments';
        }
        if (str_contains($name, 'dm') || str_contains($name, 'message') || str_contains($name, 'conversation')) {
            return 'dms';
        }
        if (str_contains($name, 'review')) {
            return 'reviews';
        }
        if (str_contains($name, 'post') || str_contains($name, 'publish') || str_contains($name, 'schedule') || str_contains($name, 'media')) {
            return 'posts';
        }
        if (str_contains($name, 'delete') || str_contains($name, 'revoke') || str_contains($name, 'disconnect')) {
            return 'moderate';
        }

        return null;
    }

    private function looksMutating(string $name): bool
    {
        return (bool) preg_match(
            '/(create|update|delete|send|reply|publish|unpublish|retry|schedule|revoke|hide|like|unlike|moderate|upload|connect|disconnect|invite)/',
            $name,
        ) && ! preg_match('/(^list|get_|fetch_|search)/', $name);
    }
}
