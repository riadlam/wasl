<?php

namespace App\Models;

use App\Mcp\McpContext;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class McpToken extends Model
{
    use BelongsToBusiness;

    public const ALLOWED_SCOPES = [McpContext::SURFACE_EXTERNAL, McpContext::SURFACE_CAMPAIGN];

    protected $fillable = [
        'business_id',
        'user_id',
        'name',
        'token_hash',
        'prefix',
        'scopes',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * @param  list<string>  $scopes
     * @return array{0: self, 1: string}
     */
    public static function issue(Business $business, ?User $user, string $name, array $scopes = []): array
    {
        $plain = 'wasl_'.Str::random(40);
        $scopes = array_values(array_intersect(self::ALLOWED_SCOPES, $scopes)) ?: [McpContext::SURFACE_EXTERNAL];
        $token = self::query()->create([
            'business_id' => $business->id,
            'user_id' => $user?->id,
            'name' => mb_substr(trim($name) ?: 'MCP client', 0, 80),
            'token_hash' => self::hash($plain),
            'prefix' => substr($plain, 0, 12),
            'scopes' => $scopes,
        ]);

        return [$token, $plain];
    }

    public static function findActive(string $plain): ?self
    {
        if ($plain === '') {
            return null;
        }

        return self::query()
            ->where('token_hash', self::hash($plain))
            ->whereNull('revoked_at')
            ->with('business')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'prefix' => $this->prefix,
            'scopes' => $this->scopes ?: [],
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
