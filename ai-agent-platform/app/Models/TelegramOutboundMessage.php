<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TelegramOutboundMessage extends Model
{
    use BelongsToBusiness;

    public const KIND_ORDER_CREATED = 'order_created';

    public const KIND_AI_NEEDS_HUMAN = 'ai_needs_human';

    public const KIND_PENDING_POST = 'pending_post';

    public const KIND_POST_SCHEDULED = 'post_scheduled';

    public const KIND_CAMPAIGN_SLOT_APPROVAL = 'campaign_slot_approval';

    protected $fillable = [
        'business_id',
        'subject_type',
        'subject_id',
        'kind',
        'chat_id',
        'message_id',
        'last_text',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
