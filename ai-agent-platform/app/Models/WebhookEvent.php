<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'provider',
        'event_id',
        'event_type',
        'payload',
        'signature_valid',
        'processed_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
