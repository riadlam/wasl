<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletLedger extends Model
{
    protected $table = 'wallet_ledger';

    protected $fillable = [
        'user_id',
        'business_id',
        'actor_user_id',
        'direction',
        'amount_da',
        'usd_amount',
        'reason',
        'reference_type',
        'reference_id',
        'meta',
        'balance_after',
    ];

    public const DIRECTION_DEBIT = 'debit';

    public const DIRECTION_CREDIT = 'credit';

    protected function casts(): array
    {
        return [
            'amount_da' => 'float',
            'usd_amount' => 'float',
            'balance_after' => 'float',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
