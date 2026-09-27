<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'name',
        'phone',
        'email',
        'wilaya',
        'commune',
        'language',
        'lifecycle',
        'lead_status',
        'lead_marked_at',
        'lead_source',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'lead_marked_at' => 'datetime',
        ];
    }

    public function socialProfiles(): HasMany
    {
        return $this->hasMany(CustomerSocialProfile::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
