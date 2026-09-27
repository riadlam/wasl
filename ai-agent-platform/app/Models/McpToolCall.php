<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class McpToolCall extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'surface',
        'mcp_token_id',
        'agent_run_id',
        'tool',
        'arguments',
        'status',
        'error',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
        ];
    }
}
