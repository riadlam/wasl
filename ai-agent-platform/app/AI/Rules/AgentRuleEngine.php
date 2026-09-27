<?php

namespace App\AI\Rules;

use App\Models\AgentRule;
use App\Models\Business;

class AgentRuleEngine
{
    public function matchHandoff(Business $business, string $text): ?AgentRule
    {
        $haystack = mb_strtolower($text);

        foreach ($this->rules($business) as $rule) {
            if (($rule->action['type'] ?? '') !== 'handoff') {
                continue;
            }

            foreach ($rule->condition['keywords'] ?? [] as $keyword) {
                if ($keyword !== '' && str_contains($haystack, mb_strtolower((string) $keyword))) {
                    return $rule;
                }
            }
        }

        return null;
    }

    public function requiresApprovalForTotal(Business $business, float $total): bool
    {
        foreach ($this->rules($business) as $rule) {
            if (($rule->action['type'] ?? '') !== 'require_approval') {
                continue;
            }

            $max = (float) ($rule->condition['max_total'] ?? 0);
            if ($max > 0 && $total > $max) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return iterable<AgentRule>
     */
    private function rules(Business $business)
    {
        return $business->agentRules()
            ->where('enabled', true)
            ->orderBy('priority')
            ->get();
    }
}
