<?php

/**
 * Shop wallet is DZD (DA).
 * Chat: Fal usage.cost (USD) × usd_to_da, rounded to 0.01 DA, 0 markup.
 * Images / catalog fallbacks: ceil to whole DA.
 */
return [
    'usd_to_da' => 250,
    'currency' => 'DZD',
    /**
     * Pre-flight wallet check = catalog chat estimate × this (tool loops burn more).
     * Blocks empty/near-empty wallets before we spend Fal.
     */
    'chat_authorize_usd_multiplier' => 5,
    /** Hard cap per chat turn (USD). Absurd Fal values are clamped and logged. */
    'chat_max_cost_usd' => 2.0,
    /** Hard cap per image job (USD). */
    'image_max_cost_usd' => 1.0,
    /** Minimum DA when a positive USD cost rounds to 0. */
    'min_charge_da' => 0.01,
    /** Auto-credit new owner wallets on register (DA). */
    'signup_bonus_da' => 500,
];
