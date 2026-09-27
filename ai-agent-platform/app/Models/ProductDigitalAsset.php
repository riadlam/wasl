<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductDigitalAsset extends Model
{
    protected $fillable = [
        'product_id',
        'delivery_note',
        'access_url',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
