<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Workflow extends Model
{
    use BelongsToBusiness;

    public const KIND_LEAD = 'lead';

    public const KIND_ENGAGEMENT = 'engagement';

    public const KIND_DM = 'dm';

    protected $fillable = [
        'business_id',
        'template_key',
        'kind',
        'name',
        'status',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
        ];
    }

    public function posts(): HasMany
    {
        return $this->hasMany(WorkflowPost::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isEngagement(): bool
    {
        return $this->template_key === 'post_comment'
            || (($this->kind ?? self::KIND_LEAD) === self::KIND_ENGAGEMENT && $this->template_key !== 'dm_keyword');
    }

    public function isDmKeyword(): bool
    {
        return $this->template_key === 'dm_keyword'
            || ($this->kind ?? '') === self::KIND_DM;
    }

    /**
     * @return list<string>
     */
    public function dmKeywords(): array
    {
        $keywords = $this->config['keywords'] ?? [];
        if (! is_array($keywords)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($word) => is_string($word) ? trim($word) : '',
            $keywords,
        )));
    }

    /**
     * @return list<string>
     */
    public function dmPlatformList(): array
    {
        $platforms = $this->config['platforms'] ?? null;
        if (is_array($platforms) && $platforms !== []) {
            return array_values(array_unique(array_filter(array_map(
                fn ($id) => is_string($id) ? strtolower(trim($id)) : '',
                $platforms,
            ))));
        }

        $single = $this->config['platform'] ?? null;
        if (is_string($single) && trim($single) !== '') {
            return [strtolower(trim($single))];
        }

        return [];
    }

    public function dmPlatform(): string
    {
        $list = $this->dmPlatformList();

        return $list[0] ?? 'instagram';
    }

    public function coversDmPlatform(string $platform): bool
    {
        $platform = strtolower(trim($platform));
        if ($platform === '') {
            return false;
        }

        return in_array($platform, $this->dmPlatformList(), true);
    }

    public function dmReplyStep(): ?array
    {
        $steps = $this->config['steps'] ?? [];
        if (! is_array($steps)) {
            return null;
        }
        foreach ($steps as $step) {
            if (is_array($step) && ($step['type'] ?? null) === 'dm_reply') {
                return $step;
            }
        }

        return null;
    }

    public function matchesDmKeyword(string $text): bool
    {
        $haystack = mb_strtolower(trim($text));
        if ($haystack === '') {
            return false;
        }
        foreach ($this->dmKeywords() as $keyword) {
            $needle = mb_strtolower($keyword);
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function resolvedFixedDmText(?array $step = null): string
    {
        $step ??= $this->dmReplyStep() ?? [];
        $text = trim((string) ($step['text'] ?? ''));
        $path = $step['image_path'] ?? null;
        $imageUrl = is_string($path) && $path !== ''
            ? Storage::disk('public')->url($path)
            : null;
        if ($imageUrl) {
            $text = $text === '' ? $imageUrl : $text."\n\n".$imageUrl;
        }

        return $text;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function defaultDmSteps(): array
    {
        return [
            [
                'type' => 'dm_reply',
                'mode' => 'fixed',
                'text' => null,
                'image_path' => null,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function dmPlatforms(): array
    {
        return [
            'instagram',
            'facebook',
            'whatsapp',
            'threads',
            'twitter',
            'telegram',
            'messenger',
        ];
    }

    public function triggerField(): string
    {
        $field = $this->config['trigger_field'] ?? null;

        return in_array($field, self::triggerFields(), true) ? $field : 'phone';
    }

    public function triggerHint(): ?string
    {
        $hint = $this->config['trigger_hint'] ?? null;

        return is_string($hint) && trim($hint) !== '' ? trim($hint) : null;
    }

    public function requireClearMatch(): bool
    {
        if (! array_key_exists('require_clear_match', $this->config ?? [])) {
            return true;
        }

        return (bool) $this->config['require_clear_match'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function engagementSteps(): array
    {
        $steps = $this->config['steps'] ?? null;
        if (! is_array($steps) || $steps === []) {
            return self::defaultEngagementSteps();
        }

        return array_values($steps);
    }

    public function publicReplyStep(): ?array
    {
        foreach ($this->engagementSteps() as $step) {
            if (($step['type'] ?? null) === 'public_reply') {
                return $step;
            }
        }

        return null;
    }

    public function privateDmStep(): ?array
    {
        foreach ($this->engagementSteps() as $step) {
            if (($step['type'] ?? null) === 'private_dm') {
                return $step;
            }
        }

        return null;
    }

    public function stepEnabled(array $step): bool
    {
        return ($step['enabled'] ?? true) !== false;
    }

    public function resolvedFixedCommentText(?array $step = null): string
    {
        $step ??= $this->publicReplyStep() ?? [];
        $text = trim((string) ($step['text'] ?? ''));
        $path = $step['image_path'] ?? null;
        $imageUrl = is_string($path) && $path !== ''
            ? Storage::disk('public')->url($path)
            : null;
        if ($imageUrl) {
            $text = $text === '' ? $imageUrl : $text."\n\n".$imageUrl;
        }

        return $text;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function defaultEngagementSteps(): array
    {
        return [
            [
                'type' => 'public_reply',
                'enabled' => true,
                'mode' => 'agent',
                'text' => null,
                'image_path' => null,
            ],
            [
                'type' => 'private_dm',
                'enabled' => true,
                'mode' => 'agent',
                'text' => null,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function triggerFields(): array
    {
        return ['phone', 'wilaya', 'commune', 'email', 'name', 'custom'];
    }
}
