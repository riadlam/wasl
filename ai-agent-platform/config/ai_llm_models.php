<?php

/**
 * Shop chat LLM catalog. Calls go through Fal OpenRouter.
 * price_usd is a UI/authorize estimate only. Live chat bills Fal usage.cost (token usage) → DA.
 */
return [
    'default' => 'claude_sonnet',
    'models' => [
        'claude_sonnet' => [
            'label' => 'Claude Sonnet',
            'recommended' => true,
            'model' => 'anthropic/claude-sonnet-4.5',
            'price_usd' => 0.10,
        ],
        'gpt' => [
            'label' => 'GPT',
            'recommended' => false,
            'model' => 'openai/gpt-5',
            'price_usd' => 0.08,
        ],
        'gemini' => [
            'label' => 'Gemini',
            'recommended' => false,
            'model' => 'google/gemini-2.5-flash',
            'price_usd' => 0.04,
        ],
    ],
];
