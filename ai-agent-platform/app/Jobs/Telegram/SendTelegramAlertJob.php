<?php

namespace App\Jobs\Telegram;

use App\Models\Business;
use App\Services\Telegram\TelegramAlertService;
use App\Services\Telegram\TelegramLinkService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendTelegramAlertJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 20, 60];

    /**
     * @param  array<int, array<string, mixed>>|null  $inlineKeyboard
     * @param  array{cache_key: string, filename: string, asset_id: int|null}|null  $photo
     */
    public function __construct(
        public int $businessId,
        public string $kind,
        public string $text,
        public ?string $subjectType,
        public int|string|null $subjectId,
        public ?array $inlineKeyboard,
        public string $mode = 'send',
        public ?array $photo = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(TelegramAlertService $alerts, TelegramLinkService $links): void
    {
        try {
            $photo = $alerts->hydratePhoto($this->photo);

            if ($this->mode === 'edit') {
                if (! $this->subjectType || $this->subjectId === null) {
                    return;
                }
                $alerts->editNow(
                    $this->kind,
                    $this->text,
                    $this->subjectType,
                    $this->subjectId,
                    $this->inlineKeyboard,
                    $photo,
                );

                return;
            }

            $business = Business::query()->find($this->businessId);
            if (! $business) {
                return;
            }
            $settings = $links->settingsFor($business);
            $subject = null;
            if ($this->subjectType && $this->subjectId !== null && class_exists($this->subjectType)) {
                $subject = $this->subjectType::query()->find($this->subjectId);
            }
            $alerts->sendNow($business, $settings, $this->kind, $this->text, $subject, $this->inlineKeyboard, $photo);
        } catch (Throwable $e) {
            Log::warning('telegram.job_failed', ['error' => $e->getMessage(), 'kind' => $this->kind]);
        }
    }
}
