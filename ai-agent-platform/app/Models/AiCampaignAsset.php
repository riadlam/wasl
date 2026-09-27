<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCampaignAsset extends Model
{
    protected $fillable = [
        'ai_campaign_id',
        'agent_asset_id',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AiCampaign::class, 'ai_campaign_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(AgentAsset::class, 'agent_asset_id');
    }
}
