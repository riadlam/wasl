<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class AgentAsset extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'agent_id',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function url(): string
    {
        return Storage::disk($this->disk ?: 'public')->url($this->path);
    }

    public function absoluteUrl(): string
    {
        $url = $this->url();
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }

    /**
     * Inline data URL so remote LLMs do not need to fetch ngrok/LAN storage URLs.
     */
    public function dataUrl(): ?string
    {
        if (! $this->isImage()) {
            return null;
        }

        $bytes = $this->rawBytes();
        if ($bytes === null || $bytes === '') {
            return null;
        }

        $mime = strtolower((string) ($this->mime ?: 'image/jpeg'));
        if (! str_starts_with($mime, 'image/')) {
            $mime = 'image/jpeg';
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    public function rawBytes(): ?string
    {
        try {
            $bytes = Storage::disk($this->disk ?: 'public')->get($this->path);
        } catch (\Throwable) {
            return null;
        }

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'mime' => $this->mime,
            'size' => $this->size,
            'path' => $this->path,
            'url' => $this->absoluteUrl(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }

    public function isImage(): bool
    {
        return str_starts_with(strtolower((string) $this->mime), 'image/');
    }
}
