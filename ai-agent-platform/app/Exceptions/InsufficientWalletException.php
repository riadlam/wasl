<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class InsufficientWalletException extends Exception
{
    public function __construct(
        public float $required,
        public float $available,
        string $message = 'Insufficient wallet balance (DA).',
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'wallet.insufficient',
            'required_da' => $this->required,
            'available_da' => $this->available,
            'currency' => 'DZD',
            'usd_to_da' => (int) config('billing.usd_to_da', 250),
        ], 402);
    }
}
