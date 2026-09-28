<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class AgentBehaviorRule extends Model
{
    use BelongsToBusiness;

    public const POLARITY_SHOULD = 'should';

    public const POLARITY_MUST_NOT = 'must_not';

    protected $fillable = [
        'business_id',
        'polarity',
        'body',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return array{id: int, polarity: string, body: string, sort_order: int}
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'polarity' => $this->polarity,
            'body' => $this->body,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
