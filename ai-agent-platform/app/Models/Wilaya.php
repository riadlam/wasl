<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Wilaya extends Model
{
    protected $fillable = [
        'code',
        'name_fr',
        'name_ar',
    ];

    public function deliveryZones(): BelongsToMany
    {
        return $this->belongsToMany(DeliveryZone::class, 'delivery_zone_wilayas')->withTimestamps();
    }
}
