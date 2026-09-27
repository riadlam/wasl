<?php

namespace App\Models;

use App\Support\CurrentBusiness;
use App\Support\Permission;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'status',
        'platform_role',
        'wallet_balance_da',
        'wallet_currency',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'wallet_balance_da' => 'float',
        ];
    }

    public function walletLedger(): HasMany
    {
        return $this->hasMany(WalletLedger::class);
    }

    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'business_users')
            ->withPivot(['role', 'permissions', 'channel_ids', 'disabled_at'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(BusinessUser::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->platform_role === 'super_admin';
    }

    public function membershipFor(?int $businessId = null): ?BusinessUser
    {
        $businessId ??= CurrentBusiness::id();

        if (! $businessId) {
            return null;
        }

        $this->loadMissing('memberships');

        return $this->memberships->firstWhere('business_id', $businessId);
    }

    public function isShopOwner(?int $businessId = null): bool
    {
        $membership = $this->membershipFor($businessId);

        return $membership?->role === 'owner' && $membership->disabled_at === null;
    }

    public function hasPermission(Permission|string $permission, ?int $businessId = null): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $membership = $this->membershipFor($businessId);

        if (! $membership || $membership->disabled_at !== null) {
            return false;
        }

        if ($membership->role === 'owner') {
            return true;
        }

        $key = $permission instanceof Permission ? $permission->value : $permission;
        $granted = $membership->permissions ?? [];

        return in_array($key, $granted, true);
    }

    /**
     * @return list<string>
     */
    public function permissionList(?int $businessId = null): array
    {
        if ($this->isSuperAdmin() || $this->isShopOwner($businessId)) {
            return Permission::values();
        }

        $membership = $this->membershipFor($businessId);

        return $membership?->permissions ?? [];
    }

    /**
     * Social account ids this user may access in the shop.
     * null = unrestricted (owner, super admin, or staff with no channel limit).
     *
     * @return list<int>|null
     */
    public function scopedSocialAccountIds(?int $businessId = null): ?array
    {
        if ($this->isSuperAdmin() || $this->isShopOwner($businessId)) {
            return null;
        }

        $membership = $this->membershipFor($businessId);
        if (! $membership || $membership->disabled_at !== null) {
            return [];
        }

        $ids = $membership->channel_ids;
        if (! is_array($ids) || $ids === []) {
            return null;
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function canAccessSocialAccount(int $socialAccountId, ?int $businessId = null): bool
    {
        $scope = $this->scopedSocialAccountIds($businessId);
        if ($scope === null) {
            return true;
        }

        return in_array($socialAccountId, $scope, true);
    }
}
