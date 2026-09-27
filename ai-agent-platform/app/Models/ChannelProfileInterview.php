<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelProfileInterview extends Model
{
    use BelongsToBusiness;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ABANDONED = 'abandoned';

    protected $fillable = [
        'business_id',
        'social_account_id',
        'created_by',
        'status',
        'questions',
        'current_index',
    ];

    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'current_index' => 'integer',
        ];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
