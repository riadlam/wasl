<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessUser extends Model
{
    protected $fillable = [
        'business_id',
        'user_id',
        'role',
        'permissions',
        'channel_ids',
        'disabled_at',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'channel_ids' => 'array',
            'disabled_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
