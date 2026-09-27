<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

/**
 * Settings for the customer-facing AI (comments, replies, DMs). The owner assistant uses agent_settings.
 * Null language, tone and llm_model inherit the shop defaults.
 */
class CustomerAiSetting extends Model
{
    use BelongsToBusiness;

    public const RESPONSE_LENGTHS = ['short', 'medium', 'long'];

    public const EMOJI_POLICIES = ['none', 'light', 'match'];

    protected $fillable = [
        'business_id',
        'llm_model',
        'language',
        'tone',
        'persona',
        'response_length',
        'allow_order_creation',
        'max_reply_chars',
        'emoji_policy',
        'handoff_keywords',
    ];

    protected function casts(): array
    {
        return [
            'allow_order_creation' => 'boolean',
            'max_reply_chars' => 'integer',
            'handoff_keywords' => 'array',
        ];
    }

    public static function forBusiness(Business $business): self
    {
        $existing = self::query()->where('business_id', $business->id)->first();
        if ($existing) {
            return $existing;
        }

        $business->loadMissing('agentSettings');
        $legacy = $business->agentSettings;
        $length = in_array($legacy?->response_length, self::RESPONSE_LENGTHS, true) ? $legacy->response_length : 'short';

        return self::query()->firstOrCreate(['business_id' => $business->id], [
            'response_length' => $length,
            'allow_order_creation' => (bool) ($legacy?->allow_order_creation ?? true),
            'max_reply_chars' => 600,
            'emoji_policy' => 'light',
        ]);
    }

    /**
     * @return list<string>
     */
    public function handoffKeywords(): array
    {
        $keywords = is_array($this->handoff_keywords) ? $this->handoff_keywords : [];

        return array_values(array_filter(array_map(fn ($k) => trim((string) $k), $keywords), fn ($k) => $k !== ''));
    }

    public function matchesHandoffKeyword(string $text): ?string
    {
        $haystack = mb_strtolower($text);
        foreach ($this->handoffKeywords() as $keyword) {
            if (str_contains($haystack, mb_strtolower($keyword))) {
                return $keyword;
            }
        }

        return null;
    }
}
