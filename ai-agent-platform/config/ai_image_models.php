<?php

/**
 * Shop image generation catalog (Wasl UI labels — no provider brand names in copy).
 * price_usd is authorize/fallback estimate. Live charge prefers Fal billing-events cost_total → DA.
 */
return [
    'default' => 'gpt_image_2',
    'models' => [
        'gpt_image_2' => [
            'label' => 'GPT Image 2',
            'recommended' => true,
            'endpoint' => 'fal-ai/gpt-image-2',
            'price_usd' => 0.053,
            'options' => [
                'quality' => 'medium',
                'num_images' => 1,
                'output_format' => 'jpeg',
            ],
        ],
        'nano_banana_2' => [
            'label' => 'Nano Banana 2',
            'recommended' => false,
            'endpoint' => 'fal-ai/nano-banana-2',
            'price_usd' => 0.08,
            'options' => [
                'num_images' => 1,
                'output_format' => 'jpeg',
                'resolution' => '1K',
            ],
        ],
        'flux' => [
            'label' => 'Flux',
            'recommended' => false,
            'endpoint' => 'fal-ai/flux/schnell',
            'price_usd' => 0.003,
            'options' => [
                'num_images' => 1,
                'num_inference_steps' => 4,
                'output_format' => 'jpeg',
                'enable_safety_checker' => true,
            ],
        ],
    ],
];
