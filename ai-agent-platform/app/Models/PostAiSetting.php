<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PostAiSetting extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'social_account_id',
        'platform_post_id',
        'ai_comment_reply',
        'ai_private_reply',
        'comment_mode',
        'fixed_comment_text',
        'fixed_comment_image_path',
        'dm_mode',
        'fixed_dm_text',
    ];

    protected function casts(): array
    {
        return [
            'ai_comment_reply' => 'boolean',
            'ai_private_reply' => 'boolean',
        ];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public static function forPost(int $businessId, int $socialAccountId, string $platformPostId): ?self
    {
        if ($platformPostId === '') {
            return null;
        }

        return static::query()
            ->forBusiness($businessId)
            ->where('social_account_id', $socialAccountId)
            ->where('platform_post_id', $platformPostId)
            ->first();
    }

    public function isFixedCommentMode(): bool
    {
        return ($this->comment_mode ?? 'agent') === 'fixed';
    }

    public function isFixedDmMode(): bool
    {
        return ($this->dm_mode ?? 'agent') === 'fixed';
    }

    public function fixedCommentImageUrl(): ?string
    {
        $path = $this->fixed_comment_image_path;
        if (! is_string($path) || $path === '') {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * Text sent as the public comment reply (image URL appended when present).
     */
    public function resolvedFixedCommentText(): string
    {
        $text = trim((string) ($this->fixed_comment_text ?? ''));
        $imageUrl = $this->fixedCommentImageUrl();
        if ($imageUrl) {
            $text = $text === '' ? $imageUrl : $text."\n\n".$imageUrl;
        }

        return $text;
    }
}
