<?php

namespace App\AI\Agents;

use App\AI\LlmModels\LlmModelCatalog;
use App\AI\Prompts\OwnerAgentPrompt;
use App\AI\Providers\FalLlmProvider;
use App\AI\Runtime\AiRuntime;
use App\AI\Runtime\SkAgentClient;
use App\AI\Tools\Owner\GenerateImage;
use App\AI\Tools\ToolRegistry;
use App\Models\AgentAsset;
use App\Models\AgentChatMessage;
use App\Models\AgentPendingAction;
use App\Models\Business;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\SocialPlatformLimits;
use Throwable;

class OwnerAgent
{
    public function __construct(
        private AgentLoop $loop,
        private OwnerAgentPrompt $prompt,
        private SkAgentClient $skClient,
        private CaptionApprover $captionApprover,
        private FalLlmProvider $fal,
    ) {}

    /**
     * @param  list<AgentAsset>  $attachments
     * @return array{reply: string, pending_action: ?array, tool_calls: list<array<string, mixed>>, generated_assets: list<array<string, mixed>>, error?: string}
     */
    public function run(
        Business $business,
        User $actor,
        string $userText,
        ?string $voiceLang = null,
        ToolRegistry $tools,
        array $attachments = [],
        ?int $agentChatId = null,
    ): array {
        if (AiRuntime::usesSk($business)) {
            return $this->runViaSk($business, $actor, $userText, $voiceLang, $attachments, $agentChatId);
        }

        return $this->runViaPhp($business, $actor, $userText, $voiceLang, $tools, $attachments, $agentChatId);
    }

    /**
     * @param  list<AgentAsset>  $attachments
     * @return array{reply: string, pending_action: ?array, tool_calls: list<array<string, mixed>>, generated_assets: list<array<string, mixed>>, error?: string}
     */
    private function runViaSk(
        Business $business,
        User $actor,
        string $userText,
        ?string $voiceLang,
        array $attachments,
        ?int $agentChatId,
    ): array {
        $business->loadMissing('agentSettings');
        $llm = app(LlmModelCatalog::class)->resolve($business->agentSettings?->llm_model);
        $result = $this->skClient->ownerChat(
            $business,
            $actor,
            $userText,
            $voiceLang,
            $attachments,
            $agentChatId,
            $this->prompt->system($business, $actor, $voiceLang),
            $llm['model'] ?? null,
        );

        $result['pending_action'] = $result['pending_action'] ?? $this->openPending($business);

        return $result;
    }

    /**
     * @param  list<AgentAsset>  $attachments
     * @return array{reply: string, pending_action: ?array, tool_calls: list<array<string, mixed>>, generated_assets: list<array<string, mixed>>, error?: string}
     */
    private function runViaPhp(
        Business $business,
        User $actor,
        string $userText,
        ?string $voiceLang = null,
        ToolRegistry $tools,
        array $attachments = [],
        ?int $agentChatId = null,
    ): array {
        $business->loadMissing('agentSettings');
        $llm = app(LlmModelCatalog::class)->resolve($business->agentSettings?->llm_model);

        if ($this->captionApprover->looksLikePostIntent($userText) && $attachments === []) {
            $reviewed = $this->runCaptionReviewLoop($business, $userText, $llm['model'] ?? null);

            return [
                'reply' => $reviewed['owner_message'],
                'pending_action' => $this->openPending($business),
                'tool_calls' => [[
                    'tool' => 'caption_review_loop',
                    'arguments' => ['brief' => $userText],
                    'result' => [
                        'approved' => $reviewed['approved'],
                        'needs_owner_edit' => $reviewed['needs_owner_edit'],
                        'rounds' => $reviewed['rounds'],
                        'caption' => $reviewed['caption'],
                    ],
                ]],
                'generated_assets' => [],
                'pending_image_jobs' => [],
                'usage' => $this->usagePayload(
                    (int) ($reviewed['usage']['prompt_tokens'] ?? 0),
                    (int) ($reviewed['usage']['completion_tokens'] ?? 0),
                    0.0,
                    (int) ($reviewed['usage']['fal_calls'] ?? 0),
                    (int) ($reviewed['usage']['fal_calls'] ?? 0),
                ),
            ];
        }

        $historyQuery = AgentChatMessage::query()
            ->where('business_id', $business->id)
            ->orderByDesc('id')
            ->limit(30);
        if ($agentChatId) {
            $historyQuery->where('agent_chat_id', $agentChatId);
        }
        $history = $historyQuery->get()->reverse()->values();

        $messages = [
            ['role' => 'system', 'content' => $this->prompt->system($business, $actor, $voiceLang)],
        ];

        foreach ($history as $row) {
            if (! in_array($row->role, ['user', 'assistant'], true)) {
                continue;
            }
            $messages[] = [
                'role' => $row->role,
                'content' => $this->historyContent($row),
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $this->userContent($userText, $attachments)];

        $context = [
            'user_id' => $actor->id,
            'voice_lang' => $voiceLang,
            'owner_brief' => $userText,
            'attached_asset_ids' => array_map(fn (AgentAsset $a) => $a->id, $attachments),
        ];

        $finalText = null;
        $toolLog = [];
        $generatedAssets = [];
        $pendingImageJobs = [];
        $imageGenAttempted = false;
        $llmOptions = ['timeout' => 180, 'model' => $llm['model']];

        $afterTool = function (string $name, array $args, array $result) use (&$imageGenAttempted, &$pendingImageJobs, &$generatedAssets): array {
            if ($name === 'generate_image') {
                $imageGenAttempted = true;
                if (! empty($result['pending']) && ! empty($result['job_id'])) {
                    $pendingImageJobs[] = ['id' => (int) $result['job_id'], 'status' => 'queued'];
                } elseif (! empty($result['ok']) && ! empty($result['asset_id'])) {
                    $generatedAssets[] = [
                        'id' => (int) $result['asset_id'],
                        'url' => (string) ($result['url'] ?? ''),
                        'mime' => (string) ($result['mime'] ?? 'image/jpeg'),
                        'original_name' => (string) ($result['original_name'] ?? ''),
                    ];
                }
            }

            return ['llm_result' => $this->toolResultForLlm($name, $result)];
        };

        try {
            $loop = $this->loop->run($business, $messages, $tools, $context, $llmOptions, $afterTool);
            $finalText = $loop->text;
            $toolLog = $this->publicToolLog($loop->toolLog);
            $usage = $loop->usage;
        } catch (Throwable $e) {
            report($e);
            $failedUsage = $e instanceof AgentLoopException ? $e->usage : [];

            return [
                'reply' => 'Sorry — I hit a snag talking to the AI. Please try again in a moment.',
                'pending_action' => $this->openPending($business),
                'tool_calls' => $e instanceof AgentLoopException ? $this->publicToolLog($e->toolLog) : [],
                'generated_assets' => $generatedAssets,
                'pending_image_jobs' => $pendingImageJobs,
                'error' => $e->getMessage(),
                'usage' => $this->usagePayload(
                    (int) ($failedUsage['prompt_tokens'] ?? 0),
                    (int) ($failedUsage['completion_tokens'] ?? 0),
                    (float) ($failedUsage['cost_usd'] ?? 0),
                    (int) ($failedUsage['fal_calls'] ?? 0),
                    (int) ($failedUsage['calls_with_cost'] ?? 0),
                ),
            ];
        }

        if ($finalText === null || $finalText === '') {
            $finalText = 'Got it. Tell me what you need — for example creating a post on one of your pages.';
        }

        $finalText = $this->scrubLeakedPrompt($this->scrubAssetIdLeak($finalText));

        // Force Fal only for a real image ask. Captions, posts, and questions never queue an image.
        if (! $imageGenAttempted && $generatedAssets === [] && $pendingImageJobs === [] && $this->shouldForceImage($userText)) {
            $prompt = $this->lastGenerateImagePrompt($history);
            if ($prompt === '') {
                $prompt = $this->lastLeakedScenePrompt($history, $finalText);
            }
            if ($prompt === '') {
                $prompt = $this->lastUserImageBrief($history, $userText);
            }
            if ($prompt !== '') {
                $imageGenAttempted = true;
                $tool = $tools->find('generate_image') ?? app(GenerateImage::class);
                $forceContext = array_merge($context, [
                    'owner_brief' => $this->lastUserImageBrief($history, $userText) ?: $userText,
                ]);
                $result = $tool->handle($business, [
                    'prompt' => $prompt,
                    'image_size' => 'square_hd',
                ], $forceContext);
                $toolLog[] = [
                    'tool' => 'generate_image',
                    'arguments' => ['prompt' => $prompt, 'image_size' => 'square_hd'],
                    'result' => $result,
                    'forced' => true,
                ];
                if (! empty($result['pending']) && ! empty($result['job_id'])) {
                    $pendingImageJobs[] = [
                        'id' => (int) $result['job_id'],
                        'status' => 'queued',
                    ];
                    $finalText = 'راني نجهز الصورة.';
                } elseif (! empty($result['ok']) && ! empty($result['asset_id'])) {
                    $generatedAssets[] = [
                        'id' => (int) $result['asset_id'],
                        'url' => (string) ($result['url'] ?? ''),
                        'mime' => (string) ($result['mime'] ?? 'image/jpeg'),
                        'original_name' => (string) ($result['original_name'] ?? ''),
                    ];
                    $finalText = 'هاهي الصورة واجدة. حاب تستعملها في بوست؟ ولا حاب نجددها مرة أخرى؟';
                }
            }
        }

        if ($pendingImageJobs !== []) {
            $finalText = $this->waitingImageReply($finalText);
        } elseif ($imageGenAttempted && $generatedAssets === []) {
            $finalText = $this->imageGenFailureReply($finalText);
        } elseif ($this->shouldForceImage($userText) && $this->looksLikeFakeImageReady($finalText) && $generatedAssets === [] && $pendingImageJobs === []) {
            $finalText = $this->imageGenFailureReply($finalText);
        } else {
            $finalText = $this->scrubLeakedPrompt($this->scrubAssetIdLeak($finalText));
        }

        return [
            'reply' => $finalText,
            'pending_action' => $this->openPending($business),
            'tool_calls' => $toolLog,
            'generated_assets' => $generatedAssets,
            'pending_image_jobs' => $pendingImageJobs,
            'usage' => $this->usagePayload(
                (int) $usage['prompt_tokens'],
                (int) $usage['completion_tokens'],
                (float) $usage['cost_usd'],
                (int) $usage['fal_calls'],
                (int) $usage['calls_with_cost'],
            ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $log
     * @return list<array{tool: string, arguments: array<string, mixed>, result: array<string, mixed>}>
     */
    private function publicToolLog(array $log): array
    {
        return array_map(fn (array $row) => [
            'tool' => $row['tool'],
            'arguments' => $row['arguments'],
            'result' => $row['result'],
        ], $log);
    }

    /**
     * @return array{prompt_tokens: int, completion_tokens: int, cost_usd: float, fal_calls: int, calls_with_cost: int}
     */
    private function usagePayload(int $promptTokens, int $completionTokens, float $costUsd, int $falCalls, int $callsWithCost): array
    {
        return [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cost_usd' => round($costUsd, 8),
            'fal_calls' => $falCalls,
            'calls_with_cost' => $callsWithCost,
        ];
    }

    private function shouldForceImage(string $userText): bool
    {
        if ($this->looksLikePostCaptionOrQuestion($userText)) {
            return false;
        }

        return $this->looksLikeExplicitImageAsk($userText) || $this->looksLikeImageRegenRequest($userText);
    }

    private function looksLikePostCaptionOrQuestion(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return false;
        }

        return (bool) preg_match(
            '/(caption|hashtag|كابشن|هاشتاغ|هاشتاج|بوست|منشور|نشر|post\b|question|سؤال|واش|schedule|برمجة|نص\b|texte|légende|legende)/iu',
            $t,
        );
    }

    private function looksLikeExplicitImageAsk(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return false;
        }

        return (bool) preg_match(
            '/(generate.*(image|photo|poster)|make.*(image|photo|poster)|create.*(image|photo|poster)|(?:\b|_)(?:photo|image|poster|pic)\b|صورة|تصويرة|بوستر)/iu',
            $t,
        );
    }

    private function looksLikeImageRegenRequest(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return false;
        }

        return (bool) preg_match(
            '/(regenerate|régénér|regen|\b3?aw+d\b|\baw+d\b|3awed|عاود|جدد|جدّد|عاود لي|هاذ الصورة|هاد الصورة|مرة أخرى|مرة اخرى)/iu',
            $t,
        );
    }

    private function looksLikeImageGenRequest(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return false;
        }

        return (bool) preg_match(
            '/(generate.*(image|photo|poster)|make.*(image|photo|poster)|create.*(image|photo|poster)|(?:\b|_)(?:photo|image|poster|pic)\b|صورة|تصويرة|بوستر|akhdm|اخدم|خدم.*(صورة|تصويرة|photo|image)|photo|image)/iu',
            $t,
        );
    }

    private function looksLikeFakeImageReady(string $text): bool
    {
        return (bool) preg_match(
            '/(هاهي|واجدة|مجددة|image is ready|here(?:\'s| is) (?:the |your )?image|régénér|prête)/iu',
            $text,
        );
    }

    /**
     * Recover an English scene the model pasted into chat when it never called the tool.
     *
     * @param  \Illuminate\Support\Collection<int, AgentChatMessage>|iterable<int, AgentChatMessage>  $history
     */
    private function lastLeakedScenePrompt(iterable $history, string $currentReply = ''): string
    {
        $candidates = [];
        if ($currentReply !== '') {
            $candidates[] = $currentReply;
        }
        foreach ($history as $row) {
            if ($row->role === 'assistant') {
                $candidates[] = (string) $row->content;
            }
        }

        foreach ($candidates as $text) {
            if (preg_match('/(?:الوصف[^\n]*\n+|prompt[:\s]+)(.+)/isu', $text, $m)) {
                $chunk = trim($m[1]);
                $chunk = preg_replace('/\n{2,}.*$/su', '', $chunk) ?? $chunk;
                $chunk = trim($chunk);
                $latin = preg_match_all('/[A-Za-z]/', $chunk);
                if ($latin >= 40) {
                    return $chunk;
                }
            }
            foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $line) {
                $line = trim($line);
                $latin = preg_match_all('/[A-Za-z]/', $line);
                $arabic = preg_match_all('/\p{Arabic}/u', $line);
                if ($latin >= 40 && $arabic < 8) {
                    return $line;
                }
            }
        }

        return '';
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AgentChatMessage>|iterable<int, AgentChatMessage>  $history
     */
    private function lastGenerateImagePrompt(iterable $history): string
    {
        $rows = [];
        foreach ($history as $row) {
            $rows[] = $row;
        }
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $row = $rows[$i];
            if ($row->role !== 'assistant') {
                continue;
            }
            $meta = is_array($row->meta) ? $row->meta : [];
            $calls = is_array($meta['tool_calls'] ?? null) ? $meta['tool_calls'] : [];
            for ($j = count($calls) - 1; $j >= 0; $j--) {
                $call = $calls[$j];
                if (($call['tool'] ?? '') !== 'generate_image') {
                    continue;
                }
                $args = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];
                $prompt = trim((string) ($args['prompt'] ?? ''));
                if ($prompt !== '') {
                    return $prompt;
                }
                $result = is_array($call['result'] ?? null) ? $call['result'] : [];
                $used = trim((string) ($result['prompt_used'] ?? ''));
                if ($used !== '') {
                    return $used;
                }
            }
        }

        return '';
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AgentChatMessage>|iterable<int, AgentChatMessage>  $history
     */
    private function lastUserImageBrief(iterable $history, string $current): string
    {
        $skip = $this->looksLikeImageRegenRequest($current);
        $rows = [];
        foreach ($history as $row) {
            $rows[] = $row;
        }
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $row = $rows[$i];
            if ($row->role !== 'user') {
                continue;
            }
            $text = trim((string) $row->content);
            if ($text === '' || $text === '(attachment)') {
                continue;
            }
            if ($this->looksLikeImageRegenRequest($text)) {
                continue;
            }
            // Prefer a prior non-regen user message as the brief.
            return $text;
        }

        return $skip ? '' : trim($current);
    }

    /**
     * Slim tool payloads so the model does not echo URLs / ask_user / meta into chat.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function toolResultForLlm(string $name, array $result): array
    {
        if ($name !== 'generate_image') {
            return $result;
        }

        if (! empty($result['pending']) && ! empty($result['job_id'])) {
            return [
                'ok' => true,
                'pending' => true,
                'note' => 'Image is queued. Reply with one short shop-language line that you are preparing the image. Never paste the English prompt, job id, or description.',
            ];
        }

        if (! empty($result['ok']) && ! empty($result['asset_id'])) {
            return [
                'ok' => true,
                'asset_id' => (int) $result['asset_id'],
                'note' => 'Image saved. Reply briefly in shop language. Never paste asset_id or meta lines. Offer use-in-post or regenerate.',
            ];
        }

        return array_intersect_key($result, array_flip(['error', 'code', 'required_da', 'available_da']));
    }

    private function waitingImageReply(string $current): string
    {
        $scrubbed = $this->scrubLeakedPrompt($this->scrubAssetIdLeak($current));
        if (
            $scrubbed === ''
            || $this->looksLikePromptDump($current)
            || $this->looksLikeFakeImageReady($scrubbed)
        ) {
            return 'راني نجهز الصورة.';
        }

        return $scrubbed;
    }

    private function scrubLeakedPrompt(string $text): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                $kept[] = '';

                continue;
            }
            if ($this->looksLikePromptLine($trim)) {
                continue;
            }
            $kept[] = $line;
        }

        $cleaned = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $kept)));

        return $cleaned;
    }

    private function looksLikePromptDump(string $text): bool
    {
        return (bool) preg_match('/(الوصف|description we|prompt we|A vibrant|Square HD)/iu', $text);
    }

    private function looksLikePromptLine(string $line): bool
    {
        if (preg_match('/(الوصف اللي|الوصف لي|ها هو الوصف|نستعملو|prompt:|visual prompt|scene description)/iu', $line)) {
            return true;
        }

        $latin = preg_match_all('/[A-Za-z]/', $line);
        $arabic = preg_match_all('/\p{Arabic}/u', $line);
        if ($latin >= 40 && $arabic < 8) {
            return true;
        }

        return false;
    }

    private function scrubAssetIdLeak(string $text): string
    {
        $cleaned = preg_replace(
            '/^[ \t]*\[?(?:Previously |User )?attached agent asset ids?:?[^\]]*\]?[ \t]*$/mi',
            '',
            $text,
        );
        $cleaned = preg_replace(
            '/^[ \t]*Attached agent asset ids?:?[^\n]*$/mi',
            '',
            (string) $cleaned,
        );
        $cleaned = preg_replace(
            '/\n{3,}/',
            "\n\n",
            (string) $cleaned,
        );

        return trim((string) $cleaned);
    }

    private function imageGenFailureReply(string $current): string
    {
        $scrubbed = $this->scrubAssetIdLeak($current);
        $looksReady = $this->looksLikeFakeImageReady($scrubbed);

        $fail = 'ما قدرتش نولّد الصورة دَرْوك. جرّب مرة أخرى ولا بدّل موديل الصورة من فوق.';

        if ($scrubbed === '' || $looksReady) {
            return $fail;
        }

        return $scrubbed."\n\n".$fail;
    }

    /**
     * @param  list<AgentAsset>  $attachments
     * @return string|list<array<string, mixed>>
     */
    private function userContent(string $userText, array $attachments): string|array
    {
        $ids = array_map(fn (AgentAsset $a) => $a->id, $attachments);
        $footer = $ids === []
            ? ''
            : "\n\nAttached agent asset ids: [".implode(',', $ids).']. Pass these as asset_ids to draft_create_post when posting. Never invent SocialAPI media_ids.';

        $text = trim($userText).$footer;
        $images = array_values(array_filter($attachments, fn (AgentAsset $a) => $a->isImage()));
        if ($images === []) {
            return $text !== '' ? $text : '(attachment)';
        }

        $parts = [
            ['type' => 'text', 'text' => $text !== '' ? $text : 'See attached image(s).'],
        ];
        foreach ($images as $image) {
            $dataUrl = $image->dataUrl();
            if ($dataUrl === null) {
                continue;
            }
            $parts[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $dataUrl],
            ];
        }

        return count($parts) === 1 ? $parts[0]['text'] : $parts;
    }

    /**
     * Keep history text-only so past public image URLs (ngrok/LAN) cannot hang the LLM fetch.
     * Asset-id hints are for the model only on USER attachment turns — never on assistant
     * generated-image turns (those IDs confuse regen and get pasted into chat).
     */
    private function historyContent(AgentChatMessage $row): string
    {
        $text = (string) $row->content;
        if ($row->role !== 'user') {
            $text = $this->scrubAssetIdLeak($text !== '' ? $text : '(empty)');
            $meta = is_array($row->meta) ? $row->meta : [];
            $ids = is_array($meta['asset_ids'] ?? null) ? $meta['asset_ids'] : [];
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if ($ids !== []) {
                $text = trim($text)."\n[Generated image asset_id: ".implode(',', $ids).' (this message only). Pass this exact id only if the owner said yes to THIS image. Never paste this line or the ids into chat.]';
            }

            return $text;
        }

        $meta = is_array($row->meta) ? $row->meta : [];
        $ids = is_array($meta['asset_ids'] ?? null) ? $meta['asset_ids'] : [];
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids !== []) {
            $text = trim($text)."\n[User attached agent asset ids: ".implode(',', $ids).'. Use only for draft_create_post / draft_schedule_post. Never paste this line or the ids into chat.]';
        }

        return $text !== '' ? $text : '(attachment)';
    }

    /**
     * @return array{id: int, type: string, summary: ?string, status: string, preview?: array<string, mixed>}|null
     */
    /**
     * @return array{
     *   caption: string,
     *   approved: bool,
     *   needs_owner_edit: bool,
     *   rounds: list<array<string, mixed>>,
     *   owner_message: string,
     *   usage: array{prompt_tokens: int, completion_tokens: int, fal_calls: int}
     * }
     */
    private function runCaptionReviewLoop(Business $business, string $ownerBrief, ?string $model): array
    {
        $drafter = function (?string $brief, ?string $feedback, ?string $previous) use ($business, $model): array {
            $messages = [
                [
                    'role' => 'system',
                    'content' => 'You are CaptionWriter. Write ONE social caption only. No preamble. '
                        .'Never invent prices or claims. If feedback is provided, revise accordingly.',
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'owner_brief' => $brief,
                        'previous_caption' => $previous,
                        'reviewer_feedback' => $feedback,
                        'shop' => $business->name,
                    ], JSON_UNESCAPED_UNICODE) ?: $brief,
                ],
            ];
            $options = ['temperature' => 0.5, 'timeout' => 60];
            if ($model) {
                $options['model'] = $model;
            }
            $response = $this->fal->chat($messages, [], $options);
            $content = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
            $usage = $response['usage'] ?? [];

            return [
                'caption' => $content,
                'usage' => [
                    'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                ],
            ];
        };

        return $this->captionApprover->reviewLoop($business, $ownerBrief, $drafter, $model);
    }

    public function openPending(Business $business): ?array
    {
        $action = AgentPendingAction::query()
            ->where('business_id', $business->id)
            ->pending()
            ->latest('id')
            ->first();

        if (! $action) {
            return null;
        }

        return [
            'id' => $action->id,
            'type' => $action->type,
            'summary' => $action->summary,
            'status' => $action->status,
            'preview' => $this->pendingPreview($business, $action),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingPreview(Business $business, AgentPendingAction $action): array
    {
        $payload = is_array($action->payload) ? $action->payload : [];
        $selectedIds = array_values(array_filter(array_map(
            'intval',
            is_array($payload['account_ids'] ?? null) ? $payload['account_ids'] : [],
        )));
        $assetIds = array_values(array_filter(array_map(
            'intval',
            is_array($payload['asset_ids'] ?? null) ? $payload['asset_ids'] : [],
        )));

        $available = $this->liveChannels($business);
        $selected = array_values(array_filter(
            $available,
            fn (array $c) => in_array((int) $c['id'], $selectedIds, true),
        ));

        $assets = [];
        if ($assetIds !== []) {
            $assets = AgentAsset::query()
                ->forBusiness($business->id)
                ->whereIn('id', $assetIds)
                ->get()
                ->map(fn (AgentAsset $a) => [
                    'id' => $a->id,
                    'url' => $a->absoluteUrl(),
                    'mime' => $a->mime,
                    'original_name' => $a->original_name,
                ])
                ->values()
                ->all();
        }

        return [
            'text' => (string) ($payload['text'] ?? ''),
            'publish_now' => (bool) ($payload['publish_now'] ?? false),
            'scheduled_at' => $payload['scheduled_at'] ?? null,
            'media_count' => is_array($payload['media_ids'] ?? null) ? count($payload['media_ids']) : 0,
            'account_ids' => $selectedIds,
            'channels' => $selected,
            'available_channels' => $available,
            'assets' => $assets,
        ];
    }

    /**
     * @param  list<int>  $accountIds
     * @return array{ok?: bool, pending_action?: array<string, mixed>, error?: string}
     */
    public function updatePendingChannels(Business $business, int $actionId, array $accountIds): array
    {
        $action = AgentPendingAction::query()
            ->where('business_id', $business->id)
            ->where('id', $actionId)
            ->pending()
            ->first();

        if (! $action) {
            return ['error' => 'No pending action found.'];
        }

        if ($action->type !== AgentPendingAction::TYPE_CREATE_POST) {
            return ['error' => 'Only create-post drafts can change channels.'];
        }

        $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        if ($accountIds === []) {
            return ['error' => 'Pick at least one channel.'];
        }

        $accounts = SocialAccount::query()
            ->where('business_id', $business->id)
            ->where('provider', 'socialapi')
            ->where('platform', '!=', 'simulator')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'disconnected');
            })
            ->whereIn('id', $accountIds)
            ->get();

        if ($accounts->count() !== count($accountIds)) {
            return ['error' => 'One or more channels are invalid or disconnected.'];
        }

        $payload = is_array($action->payload) ? $action->payload : [];
        $mediaIds = is_array($payload['media_ids'] ?? null) ? $payload['media_ids'] : [];
        $platforms = $accounts->pluck('platform')->filter()->values()->all();
        $maxMedia = SocialPlatformLimits::maxMedia($platforms);
        if (count($mediaIds) > $maxMedia) {
            return [
                'error' => $maxMedia === 0
                    ? 'Selected channels do not support image attachments.'
                    : "Selected channels allow at most {$maxMedia} image(s).",
            ];
        }

        $payload['account_ids'] = $accountIds;
        $text = (string) ($payload['text'] ?? '');
        $publishNow = (bool) ($payload['publish_now'] ?? false);
        $scheduledAt = $payload['scheduled_at'] ?? null;
        $labels = $accounts->map(fn (SocialAccount $a) => trim(($a->platform ?: '').' '.($a->name ?: $a->username ?: '#'.$a->id)))->implode(', ');
        $when = $publishNow ? 'publish now' : ('schedule for '.(string) $scheduledAt);
        $mediaNote = $mediaIds === [] ? 'no media' : count($mediaIds).' media item(s)';
        $short = mb_strlen($text) > 80 ? mb_substr($text, 0, 77).'…' : $text;
        $summary = "Create post on {$labels}: \"{$short}\" — {$when}, {$mediaNote}.";

        $action->update([
            'payload' => $payload,
            'summary' => $summary,
        ]);

        return [
            'ok' => true,
            'pending_action' => $this->openPending($business),
        ];
    }

    /**
     * @return list<array{id: int, platform: ?string, name: ?string, username: ?string}>
     */
    private function liveChannels(Business $business): array
    {
        return SocialAccount::query()
            ->where('business_id', $business->id)
            ->where('provider', 'socialapi')
            ->where('platform', '!=', 'simulator')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'disconnected');
            })
            ->orderBy('id')
            ->get()
            ->map(fn (SocialAccount $a) => [
                'id' => $a->id,
                'platform' => $a->platform,
                'name' => $a->name,
                'username' => $a->username,
            ])
            ->values()
            ->all();
    }
}
