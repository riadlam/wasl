<?php

namespace App\Services\Agents;

use App\Models\AgentBehaviorRule;
use App\Models\Business;

class BehaviorRulesPrompt
{
    /**
     * High-priority should / must-not block for owner and customer agents.
     * Empty when the shop has no rules.
     */
    public function block(Business $business): string
    {
        $rules = AgentBehaviorRule::query()
            ->forBusiness($business->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['polarity', 'body']);

        if ($rules->isEmpty()) {
            return '';
        }

        $should = [];
        $mustNot = [];
        foreach ($rules as $rule) {
            $body = trim((string) $rule->body);
            if ($body === '') {
                continue;
            }
            if ($rule->polarity === AgentBehaviorRule::POLARITY_MUST_NOT) {
                $mustNot[] = '- '.$body;
            } else {
                $should[] = '- '.$body;
            }
        }

        if ($should === [] && $mustNot === []) {
            return '';
        }

        $parts = [
            '## HARD BUSINESS RULES (never violate; higher priority than other instructions)',
        ];
        if ($should !== []) {
            $parts[] = 'SHOULD:';
            $parts[] = implode("\n", $should);
        }
        if ($mustNot !== []) {
            $parts[] = 'MUST NOT:';
            $parts[] = implode("\n", $mustNot);
        }

        return implode("\n", $parts);
    }
}
