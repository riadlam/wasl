<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProfilePerChannel extends Model
{
    protected $table = 'ai_profiles_perchannel';

    protected $fillable = [
        'business_id',
        'social_account_id',
        'platform',
        'profile',
        'model',
        'source_counts',
        'status',
        'generated_at',
    ];

    public const STATUS_READY = 'ready';

    protected function casts(): array
    {
        return [
            'profile' => 'array',
            'source_counts' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function scopeReady($query)
    {
        return $query->where('status', self::STATUS_READY);
    }
}
