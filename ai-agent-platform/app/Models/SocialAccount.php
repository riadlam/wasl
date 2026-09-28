<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialAccount extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'provider',
        'socialapi_account_id',
        'socialapi_brand_id',
        'platform',
        'platform_account_id',
        'name',
        'username',
        'avatar_url',
        'logo_asset_id',
        'status',
        'metadata',
        'connected_at',
        'disconnected_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function logoAsset(): BelongsTo
    {
        return $this->belongsTo(AgentAsset::class, 'logo_asset_id');
    }

    /**
     * Prefer shop-uploaded page logo; else SocialAPI profile picture.
     */
    public function resolvedLogoUrl(): ?string
    {
        $custom = $this->logoAsset;
        if ($custom && $custom->isImage()) {
            return $custom->absoluteUrl();
        }

        $api = trim((string) ($this->avatar_url ?: ''));

        return $api !== '' ? $api : null;
    }

    public function hasCustomLogo(): bool
    {
        return $this->logo_asset_id !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toChannelApiArray(): array
    {
        $connected = $this->status !== 'disconnected'
            && filled($this->socialapi_account_id);

        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'connected' => $connected,
            'name' => $this->name,
            'username' => $this->username,
            'avatar_url' => $this->avatar_url,
            'logo_url' => $this->resolvedLogoUrl(),
            'has_custom_logo' => $this->hasCustomLogo(),
            'logo_asset_id' => $this->logo_asset_id,
            'status' => $this->status,
            'connected_at' => $this->connected_at,
        ];
    }
}
