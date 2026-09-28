<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Business extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'logo',
        'description',
        'phone',
        'email',
        'website',
        'currency',
        'timezone',
        'wilaya',
        'city',
        'status',
        'onboarding_status',
        'onboarding_meta',
        'socialapi_brand_id',
    ];

    public const ONBOARDING = 'onboarding';

    public const ONBOARDING_WAITING_AI_TRAINING = 'onboarding_waiting_ai_training';

    public const ONBOARDING_PHASEONE = 'onboarding_phaseone';

    public const ONBOARDING_AI_PROFILE = 'onboarding_ai_profile';

    public const ONBOARDING_DONE = 'onboarding_done';

    protected function casts(): array
    {
        return [
            'onboarding_meta' => 'array',
        ];
    }

    public function letter(): string
    {
        return strtoupper(mb_substr((string) $this->name, 0, 1)) ?: 'W';
    }

    public function isOnboardingGateActive(): bool
    {
        return in_array($this->onboarding_status, [
            self::ONBOARDING,
            self::ONBOARDING_WAITING_AI_TRAINING,
            self::ONBOARDING_PHASEONE,
            self::ONBOARDING_AI_PROFILE,
        ], true);
    }

    public function needsChannel(): bool
    {
        return $this->onboarding_status === self::ONBOARDING;
    }

    public function isTrainingAi(): bool
    {
        return in_array($this->onboarding_status, [
            self::ONBOARDING_WAITING_AI_TRAINING,
            self::ONBOARDING_PHASEONE,
            self::ONBOARDING_AI_PROFILE,
        ], true);
    }

    public function isBuildingAiProfile(): bool
    {
        return in_array($this->onboarding_status, [
            self::ONBOARDING_PHASEONE,
            self::ONBOARDING_AI_PROFILE,
        ], true);
    }

    public function isOnboardingComplete(): bool
    {
        return $this->onboarding_status === self::ONBOARDING_DONE;
    }

    protected static function booted(): void
    {
        static::creating(function (Business $business) {
            if (! $business->slug) {
                $business->slug = Str::slug($business->name).'-'.Str::lower(Str::random(6));
            }
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'business_users')
            ->withPivot(['role', 'permissions', 'channel_ids', 'disabled_at'])
            ->withTimestamps();
    }

    public function members(): HasMany
    {
        return $this->hasMany(BusinessUser::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function simulatorAccount(): HasOne
    {
        return $this->hasOne(SocialAccount::class)->where('platform', 'simulator');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function deliveryZones(): HasMany
    {
        return $this->hasMany(DeliveryZone::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(BusinessPaymentMethod::class);
    }

    public function agent(): HasOne
    {
        return $this->hasOne(Agent::class);
    }

    public function agentSettings(): HasOne
    {
        return $this->hasOne(AgentSetting::class);
    }

    public function customerAiSettings(): HasOne
    {
        return $this->hasOne(CustomerAiSetting::class);
    }

    public function telegramSettings(): HasOne
    {
        return $this->hasOne(BusinessTelegramSetting::class);
    }

    public function agentRules(): HasMany
    {
        return $this->hasMany(AgentRule::class);
    }

    public function behaviorRules(): HasMany
    {
        return $this->hasMany(AgentBehaviorRule::class);
    }

    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class);
    }

    public function trainingSnapshots(): HasMany
    {
        return $this->hasMany(BusinessTrainingSnapshot::class);
    }

    public function aiProfilesPerChannel(): HasMany
    {
        return $this->hasMany(AiProfilePerChannel::class);
    }
}
