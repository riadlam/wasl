<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class AgentRule extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'name',
        'type',
        'condition',
        'action',
        'enabled',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'condition' => 'array',
            'action' => 'array',
            'enabled' => 'boolean',
            'priority' => 'integer',
        ];
    }
}
