<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCampaignChannel extends Model
{
    protected $fillable = [
        'ai_campaign_id',
        'social_account_id',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AiCampaign::class, 'ai_campaign_id');
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
