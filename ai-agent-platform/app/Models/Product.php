<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Product extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'name',
        'slug',
        'description',
        'sku',
        'price',
        'compare_price',
        'stock',
        'status',
        'type',
        'channel_scope',
        'category',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_price' => 'decimal:2',
            'stock' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (! $product->slug) {
                $product->slug = Str::slug($product->name).'-'.Str::lower(Str::random(4));
            }
            if (! $product->type) {
                $product->type = 'physical';
            }
            if (! $product->channel_scope) {
                $product->channel_scope = 'all';
            }
        });
    }

    public function isDigital(): bool
    {
        return $this->type === 'digital';
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function galleryImages(): HasMany
    {
        return $this->hasMany(ProductImage::class)->whereNull('variant_id')->orderBy('sort_order')->orderBy('id');
    }

    public function mainImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->whereNull('variant_id')->where('is_main', true);
    }

    public function socialAccounts(): BelongsToMany
    {
        return $this->belongsToMany(SocialAccount::class, 'product_channels')->withTimestamps();
    }

    public function deliveryZones(): BelongsToMany
    {
        return $this->belongsToMany(DeliveryZone::class, 'product_delivery_zones')->withTimestamps();
    }

    public function digitalAsset(): HasOne
    {
        return $this->hasOne(ProductDigitalAsset::class);
    }
}
