<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DeliveryZone extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'name',
        'fee',
        'days',
    ];

    protected function casts(): array
    {
        return [
            'fee' => 'decimal:2',
        ];
    }

    public function wilayas(): BelongsToMany
    {
        return $this->belongsToMany(Wilaya::class, 'delivery_zone_wilayas')->withTimestamps();
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_delivery_zones')->withTimestamps();
    }
}
