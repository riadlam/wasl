<?php

namespace App\AI\Tools\Owner;

use App\AI\ImageModels\ImageModelCatalog;
use App\AI\ImageOnImageLanguage;
use App\AI\Providers\FalImageProvider;
use App\AI\Tools\AgentTool;
use App\Exceptions\InsufficientWalletException;
use App\Models\AgentImageJob;
use App\Models\Business;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Throwable;

class GenerateImage implements AgentTool
{
    public function __construct(
        private FalImageProvider $images,
        private WalletService $wallets,
        private ImageModelCatalog $catalog,
    ) {}

    public function name(): string
    {
        return 'generate_image';
    }

    public function description(): string
    {
        return 'REQUIRED when the owner wants an image and the subject is known (or they said as-you-want): call this tool in the SAME turn. Owner may brief in Darija/French/Arabic — do NOT ask them to rewrite in English. Scene in prompt may be English, but on-image promo text MUST follow shop language (Darija Arabic script or French) when they asked for Darija/French/text or for a promo/poster. Do NOT paste the prompt into chat. Queues the image and returns job_id. Costs wallet DA only after the image is ready.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'prompt' => [
                    'type' => 'string',
                    'description' => 'Visual scene YOU write (English OK). CRITICAL: if owner wants Darija/French ON the image (بالدارجة / bl darja / en français / كتب عليها / promo poster), include exact on-image lines in that language (Darija = Arabic script, French = French) as typography to render. Never ask the owner to provide English or to approve this wording.',
                ],
                'image_size' => [
                    'type' => 'string',
                    'enum' => FalImageProvider::ALLOWED_SIZES,
                    'description' => 'square_hd for feed (default), portrait_16_9 for stories/reels covers, landscape_16_9 or landscape_4_3 for wide layouts.',
                ],
                'seed' => [
                    'type' => 'integer',
                    'description' => 'Optional seed for reproducible regenerations.',
                ],
            ],
            'required' => ['prompt'],
        ];
    }

    public function handle(Business $business, array $arguments, array $context = []): array
    {
        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        if ($prompt === '') {
            return ['error' => 'prompt is required — ask the owner for a clear image brief first.'];
        }

        $size = (string) ($arguments['image_size'] ?? 'square_hd');
        if (! in_array($size, FalImageProvider::ALLOWED_SIZES, true)) {
            $size = 'square_hd';
        }

        $seed = isset($arguments['seed']) ? (int) $arguments['seed'] : null;
        $actor = isset($context['user_id'])
            ? User::query()->find((int) $context['user_id'])
            : null;

        $business->loadMissing('agentSettings');
        $model = $this->catalog->resolve($business->agentSettings?->image_model);
        $modelKey = (string) $model['id'];
        $priceDa = (float) $model['price_da'];
        $priceUsd = (float) $model['price_usd'];
        if ($priceDa <= 0 || $priceUsd <= 0) {
            return ['error' => 'Image generation is misconfigured.'];
        }

        try {
            $this->wallets->authorizeBusinessAi($business, $priceDa);
        } catch (InsufficientWalletException $e) {
            return [
                'error' => 'Not enough wallet balance to generate an image (need '.$priceDa.' DA).',
                'code' => 'wallet.insufficient',
                'required_da' => $e->required,
                'available_da' => $e->available,
            ];
        }

        $ownerBrief = trim((string) ($context['owner_brief'] ?? ''));
        $fullPrompt = ImageOnImageLanguage::enrich($business, $prompt, $ownerBrief !== '' ? $ownerBrief : null);
        $logoUrl = SocialAccount::query()
            ->where('business_id', $business->id)
            ->where('provider', 'socialapi')
            ->where('platform', '!=', 'simulator')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'disconnected');
            })
            ->with('logoAsset')
            ->orderByDesc('id')
            ->get()
            ->map(fn (SocialAccount $a) => $a->resolvedLogoUrl())
            ->first(fn ($u) => is_string($u) && $u !== '');
        if (is_string($logoUrl) && $logoUrl !== '' && stripos($fullPrompt, 'PAGE_LOGO') === false) {
            $fullPrompt = 'PAGE_LOGO: '.$logoUrl
                .' — when a brand mark helps, discreetly include this page logo/colors; never invent a different logo. '
                .$fullPrompt;
        }

        try {
            $queued = $this->images->submit($fullPrompt, $modelKey, $size, $seed);
        } catch (Throwable $e) {
            return ['error' => $e->getMessage() ?: 'Image generation failed.'];
        }

        $job = AgentImageJob::query()->create([
            'business_id' => $business->id,
            'user_id' => $actor?->id,
            'fal_request_id' => $queued['request_id'],
            'model_key' => $modelKey,
            'endpoint' => $queued['endpoint'],
            'image_size' => $size,
            'prompt' => $fullPrompt,
            'status' => AgentImageJob::STATUS_QUEUED,
            'status_url' => $queued['status_url'],
            'response_url' => $queued['response_url'],
            'price_da' => (int) $priceDa,
        ]);

        return [
            'ok' => true,
            'pending' => true,
            'job_id' => $job->id,
            'status' => AgentImageJob::STATUS_QUEUED,
            'image_size' => $size,
            'image_model' => $modelKey,
            'price_da' => $priceDa,
        ];
    }
}
