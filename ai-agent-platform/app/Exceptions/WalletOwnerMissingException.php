<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class WalletOwnerMissingException extends Exception
{
    public function __construct(string $message = 'Shop owner wallet not found.')
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'wallet.owner_missing',
        ], 422);
    }
}
