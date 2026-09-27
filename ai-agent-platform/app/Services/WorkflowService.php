<?php

namespace App\Services;

use App\Models\Business;
use App\Models\SocialAccount;
use App\Models\Workflow;
use App\Models\WorkflowPost;
use App\Support\CurrentBusiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class WorkflowService
{
    public function __construct(private WorkflowCatalog $catalog) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function templates(): array
    {
        return array_map(function (array $template) {
            if (($template['kind'] ?? 'lead') === 'lead') {
                $template['trigger_options'] = $this->catalog->triggerOptions();
            }

            return $template;
        }, $this->catalog->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?Business $business = null): array
    {
        $business ??= CurrentBusiness::require();

        return Workflow::query()
            ->forBusiness($business->id)
            ->with('posts')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Workflow $workflow) => $this->toArray($workflow))
            ->all();
    }

    /**
     * @return list<Workflow>
     */
    public function activeFor(Business $business): array
    {
        return Workflow::query()
            ->forBusiness($business->id)
            ->where('status', 'active')
            ->get()
            ->all();
    }

    public function hasActiveLeadWorkflows(Business $business): bool
    {
        return Workflow::query()
            ->forBusiness($business->id)
            ->where('status', 'active')
            ->whereIn('template_key', ['mark_lead', 'hot_lead'])
            ->exists();
    }

    public function activeByKey(Business $business, string $templateKey): ?Workflow
    {
        return Workflow::query()
            ->forBusiness($business->id)
            ->where('template_key', $templateKey)
            ->where('status', 'active')
            ->first();
    }

    public function findActiveEngagementForPost(int $businessId, int $socialAccountId, string $platformPostId): ?Workflow
    {
        if ($platformPostId === '') {
            return null;
        }

        $link = WorkflowPost::query()
            ->where('business_id', $businessId)
            ->where('social_account_id', $socialAccountId)
            ->where('platform_post_id', $platformPostId)
            ->whereHas('workflow', function ($query) {
                $query->where('status', 'active')
                    ->where(function ($inner) {
                        $inner->where('kind', Workflow::KIND_ENGAGEMENT)
                            ->orWhere('template_key', 'post_comment');
                    });
            })
            ->with('workflow')
            ->first();

        return $link?->workflow;
    }

    /**
     * Map of "accountId:platformPostId" => workflow flags for post cards.
     *
     * @param  list<array{account_id?: int, platform_post_id?: string}>  $posts
     * @return array<string, array<string, mixed>>
     */
    public function engagementFlagsForPosts(int $businessId, array $posts): array
    {
        if ($posts === []) {
            return [];
        }

        $accountIds = collect($posts)->pluck('account_id')->unique()->filter()->all();
        $postIds = collect($posts)->pluck('platform_post_id')->unique()->filter()->all();
        if ($accountIds === [] || $postIds === []) {
            return [];
        }

        $links = WorkflowPost::query()
            ->where('business_id', $businessId)
            ->whereIn('social_account_id', $accountIds)
            ->whereIn('platform_post_id', $postIds)
            ->whereHas('workflow', function ($query) {
                $query->where('status', 'active')
                    ->where(function ($inner) {
                        $inner->where('kind', Workflow::KIND_ENGAGEMENT)
                            ->orWhere('template_key', 'post_comment');
                    });
            })
            ->with('workflow')
            ->get();

        $map = [];
        foreach ($links as $link) {
            $key = $link->social_account_id.':'.$link->platform_post_id;
            $workflow = $link->workflow;
            if (! $workflow) {
                continue;
            }
            $reply = $workflow->publicReplyStep() ?? [];
            $dm = $workflow->privateDmStep() ?? [];
            $map[$key] = [
                'workflow_id' => $workflow->id,
                'ai_comment_reply' => $workflow->stepEnabled($reply),
                'ai_private_reply' => $workflow->stepEnabled($dm),
                'comment_mode' => ($reply['mode'] ?? 'agent') === 'fixed' ? 'fixed' : 'agent',
                'dm_mode' => ($dm['mode'] ?? 'agent') === 'fixed' ? 'fixed' : 'agent',
                'fixed_comment_text' => $reply['text'] ?? null,
                'fixed_comment_image_path' => $reply['image_path'] ?? null,
                'fixed_comment_image_url' => ! empty($reply['image_path'])
                    ? Storage::disk('public')->url($reply['image_path'])
                    : null,
                'fixed_dm_text' => $dm['text'] ?? null,
            ];
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<array{account_id?: int, platform_post_id?: string}>  $posts
     * @return array<string, mixed>
     */
    public function useTemplate(
        string $templateKey,
        array $config = [],
        bool $activate = false,
        ?Business $business = null,
        array $posts = [],
        ?string $name = null,
    ): array {
        $business ??= CurrentBusiness::require();
        $template = $this->catalog->find($templateKey);
        if (! $template) {
            throw new RuntimeException('Unknown workflow template.');
        }

        $templateKind = $template['kind'] ?? 'lead';
        if ($templateKey === 'dm_keyword' || $templateKind === 'dm') {
            return $this->createDmKeywordWorkflow($template, $config, $activate, $business, $name);
        }

        $kind = $templateKind === 'engagement'
            ? Workflow::KIND_ENGAGEMENT
            : Workflow::KIND_LEAD;

        if ($kind === Workflow::KIND_ENGAGEMENT) {
            return $this->createEngagementWorkflow($template, $config, $activate, $business, $posts, $name);
        }

        $normalized = $this->normalizeLeadConfig(
            array_merge($template['default_config'] ?? [], $config),
            $template['default_config']['trigger_field'] ?? 'phone',
        );

        $workflow = Workflow::query()->updateOrCreate(
            [
                'business_id' => $business->id,
                'template_key' => $templateKey,
            ],
            [
                'kind' => Workflow::KIND_LEAD,
                'name' => $template['title'],
                'config' => $normalized,
            ],
        );

        if ($activate) {
            $workflow->status = 'active';
            $workflow->save();
        } elseif (! $workflow->status) {
            $workflow->status = 'draft';
            $workflow->save();
        }

        return $this->toArray($workflow->fresh('posts'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(Workflow $workflow, array $payload): array
    {
        if (isset($payload['name']) && is_string($payload['name']) && trim($payload['name']) !== '') {
            $workflow->name = trim($payload['name']);
        }

        if (isset($payload['status'])) {
            $workflow->status = $payload['status'];
        }

        if ($workflow->isDmKeyword()) {
            $willBeActive = ($payload['status'] ?? $workflow->status) === 'active';
            if (isset($payload['status']) && $payload['status'] === 'draft') {
                throw new RuntimeException('DM automations cannot be saved as draft. Activate or pause instead.');
            }
            if (array_key_exists('config', $payload) && is_array($payload['config'])) {
                $existing = is_array($workflow->config) ? $workflow->config : [];
                $workflow->config = $this->normalizeDmConfig(array_merge($existing, $payload['config']), $willBeActive, true);
            } elseif ($willBeActive) {
                $workflow->config = $this->normalizeDmConfig(
                    is_array($workflow->config) ? $workflow->config : [],
                    true,
                    true,
                );
            }
            $workflow->save();

            return $this->toArray($workflow->fresh('posts'));
        }

        if ($workflow->isEngagement()) {
            if (isset($payload['status']) && $payload['status'] === 'draft') {
                throw new RuntimeException('Post automations cannot be saved as draft. Activate or pause instead.');
            }
            if (array_key_exists('config', $payload) && is_array($payload['config'])) {
                $existing = is_array($workflow->config) ? $workflow->config : [];
                $workflow->config = $this->normalizeEngagementConfig(array_merge($existing, $payload['config']));
            }
            $workflow->save();

            if (array_key_exists('posts', $payload) && is_array($payload['posts'])) {
                $this->syncPosts($workflow, $payload['posts'], $workflow->status === 'active');
            } elseif ($workflow->status === 'active') {
                $this->assertNoActivePostConflicts($workflow, $workflow->posts()->get()->all());
            }

            return $this->toArray($workflow->fresh('posts'));
        }

        if (array_key_exists('config', $payload) && is_array($payload['config'])) {
            $config = is_array($workflow->config) ? $workflow->config : [];
            $workflow->config = $this->normalizeLeadConfig(
                array_merge($config, $payload['config']),
                $workflow->triggerField(),
            );
        }
        $workflow->save();

        return $this->toArray($workflow->fresh('posts'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Workflow $workflow): array
    {
        $template = $this->catalog->find($workflow->template_key) ?? [];

        if ($workflow->isDmKeyword()) {
            $keywords = $workflow->dmKeywords();
            $reply = $workflow->dmReplyStep() ?? [];
            $mode = ($reply['mode'] ?? 'fixed') === 'agent' ? 'agent' : 'fixed';

            return [
                'id' => $workflow->id,
                'template_key' => 'dm_keyword',
                'kind' => Workflow::KIND_DM,
                'name' => $workflow->name,
                'status' => $workflow->status,
                'config' => [
                    'platform' => $workflow->dmPlatform(),
                    'platforms' => $workflow->dmPlatformList(),
                    'match' => 'contains',
                    'keywords' => $keywords,
                    'steps' => [$this->normalizeDmReplyStep($reply)],
                ],
                'posts' => [],
                'title' => $template['title'] ?? $workflow->name,
                'body' => $template['body'] ?? '',
                'tone' => $template['tone'] ?? 'accent',
                'steps' => $this->dmDisplaySteps($workflow->dmPlatformList(), $keywords, $mode),
                'summary' => $template['summary'] ?? '',
                'benefits' => $template['benefits'] ?? [],
                'how' => $template['how'] ?? [],
            ];
        }

        if ($workflow->isEngagement()) {
            $steps = $workflow->engagementSteps();
            $posts = $workflow->relationLoaded('posts')
                ? $workflow->posts
                : $workflow->posts()->get();

            return [
                'id' => $workflow->id,
                'template_key' => $workflow->template_key,
                'kind' => Workflow::KIND_ENGAGEMENT,
                'name' => $workflow->name,
                'status' => $workflow->status,
                'config' => [
                    'steps' => $steps,
                ],
                'posts' => $posts->map(fn (WorkflowPost $post) => [
                    'account_id' => $post->social_account_id,
                    'platform_post_id' => $post->platform_post_id,
                ])->values()->all(),
                'title' => $template['title'] ?? $workflow->name,
                'body' => $template['body'] ?? '',
                'tone' => $template['tone'] ?? 'accent',
                'steps' => $this->engagementDisplaySteps($steps, $posts->count()),
                'summary' => $template['summary'] ?? '',
                'benefits' => $template['benefits'] ?? [],
                'how' => $template['how'] ?? [],
            ];
        }

        $field = $workflow->triggerField();

        return [
            'id' => $workflow->id,
            'template_key' => $workflow->template_key,
            'kind' => Workflow::KIND_LEAD,
            'name' => $workflow->name,
            'status' => $workflow->status,
            'config' => [
                'trigger_field' => $field,
                'trigger_hint' => $workflow->triggerHint(),
                'require_clear_match' => $workflow->requireClearMatch(),
            ],
            'posts' => [],
            'trigger_label' => $this->catalog->triggerLabel($field),
            'trigger_options' => $this->catalog->triggerOptions(),
            'title' => $template['title'] ?? $workflow->name,
            'body' => $template['body'] ?? '',
            'tone' => $template['tone'] ?? 'accent',
            'steps' => $template['steps'] ?? [],
            'summary' => $template['summary'] ?? '',
            'benefits' => $template['benefits'] ?? [],
            'how' => $template['how'] ?? [],
        ];
    }

    /**
     * @return list<array{key: string, title: string, trigger_field: string, trigger_hint: ?string, require_clear_match: bool}>
     */
    public function promptLines(Business $business): array
    {
        $lines = [];
        foreach ($this->activeFor($business) as $workflow) {
            if ($workflow->isEngagement() || $workflow->isDmKeyword()) {
                continue;
            }
            $lines[] = [
                'key' => $workflow->template_key,
                'title' => $workflow->name,
                'trigger_field' => $workflow->triggerField(),
                'trigger_hint' => $workflow->triggerHint(),
                'require_clear_match' => $workflow->requireClearMatch(),
            ];
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $config
     * @param  list<array{account_id?: int, platform_post_id?: string}>  $posts
     * @return array<string, mixed>
     */
    private function createEngagementWorkflow(
        array $template,
        array $config,
        bool $activate,
        Business $business,
        array $posts,
        ?string $name = null,
    ): array {
        if (! $activate) {
            throw new RuntimeException('Finish the canvas and save — incomplete drafts are not stored.');
        }

        if ($posts === []) {
            throw new RuntimeException('Pick at least one post before saving.');
        }

        $normalized = $this->normalizeEngagementConfig(array_merge($template['default_config'] ?? [], $config));

        $title = is_string($name) && trim($name) !== ''
            ? trim($name)
            : ($template['title'] ?? 'Post comment automation');

        $workflow = Workflow::query()->create([
            'business_id' => $business->id,
            'template_key' => $template['id'] ?? 'post_comment',
            'kind' => Workflow::KIND_ENGAGEMENT,
            'name' => $title,
            'status' => 'active',
            'config' => $normalized,
        ]);

        $this->syncPosts($workflow, $posts, true);

        return $this->toArray($workflow->fresh('posts'));
    }

    /**
     * @param  list<array{account_id?: int, platform_post_id?: string}>  $posts
     */
    private function syncPosts(Workflow $workflow, array $posts, bool $enforceActiveUnique): void
    {
        $businessId = (int) $workflow->business_id;
        $normalized = [];
        foreach ($posts as $post) {
            $accountId = (int) ($post['account_id'] ?? 0);
            $platformPostId = trim((string) ($post['platform_post_id'] ?? ''));
            if ($accountId < 1 || $platformPostId === '') {
                continue;
            }
            $account = SocialAccount::query()
                ->forBusiness($businessId)
                ->where('id', $accountId)
                ->where('provider', 'socialapi')
                ->first();
            if (! $account) {
                throw new RuntimeException('One of the selected posts uses an unknown account.');
            }
            $normalized[] = [
                'social_account_id' => $account->id,
                'platform_post_id' => $platformPostId,
            ];
        }

        if ($enforceActiveUnique) {
            $this->assertNoActivePostConflicts($workflow, $normalized);
        }

        DB::transaction(function () use ($workflow, $businessId, $normalized) {
            $workflow->posts()->delete();
            foreach ($normalized as $row) {
                $workflow->posts()->create([
                    'business_id' => $businessId,
                    'social_account_id' => $row['social_account_id'],
                    'platform_post_id' => $row['platform_post_id'],
                ]);
            }
        });
    }

    /**
     * @param  list<array{social_account_id: int, platform_post_id: string}|WorkflowPost>  $posts
     */
    private function assertNoActivePostConflicts(Workflow $workflow, array $posts): void
    {
        foreach ($posts as $post) {
            $accountId = (int) (is_array($post) ? ($post['social_account_id'] ?? 0) : $post->social_account_id);
            $platformPostId = (string) (is_array($post) ? ($post['platform_post_id'] ?? '') : $post->platform_post_id);
            if ($accountId < 1 || $platformPostId === '') {
                continue;
            }

            $conflict = WorkflowPost::query()
                ->where('business_id', $workflow->business_id)
                ->where('social_account_id', $accountId)
                ->where('platform_post_id', $platformPostId)
                ->where('workflow_id', '!=', $workflow->id)
                ->whereHas('workflow', function ($query) {
                    $query->where('status', 'active')
                        ->where(function ($inner) {
                            $inner->where('kind', Workflow::KIND_ENGAGEMENT)
                                ->orWhere('template_key', 'post_comment');
                        });
                })
                ->exists();

            if ($conflict) {
                throw new RuntimeException('Another active automation already covers one of these posts.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{steps: list<array<string, mixed>>}
     */
    private function normalizeEngagementConfig(array $config): array
    {
        $defaults = Workflow::defaultEngagementSteps();
        $incoming = is_array($config['steps'] ?? null) ? $config['steps'] : $defaults;
        $byType = [];
        foreach ($incoming as $step) {
            if (! is_array($step)) {
                continue;
            }
            $type = $step['type'] ?? null;
            if ($type === 'public_reply' || $type === 'private_dm') {
                $byType[$type] = $step;
            }
        }

        $steps = [];
        foreach ($defaults as $default) {
            $type = $default['type'];
            $step = $byType[$type] ?? $default;
            $mode = ($step['mode'] ?? 'agent') === 'fixed' ? 'fixed' : 'agent';
            $enabled = ($step['enabled'] ?? true) !== false;
            $text = is_string($step['text'] ?? null) ? trim((string) $step['text']) : '';
            $imagePath = is_string($step['image_path'] ?? null) ? trim((string) $step['image_path']) : '';

            if ($enabled && $mode === 'fixed' && $text === '' && ($type !== 'public_reply' || $imagePath === '')) {
                throw new RuntimeException(
                    $type === 'public_reply'
                        ? 'Fixed public reply needs message text.'
                        : 'Custom Auto DM needs message text.',
                );
            }

            $normalized = [
                'type' => $type,
                'enabled' => $enabled,
                'mode' => $mode,
                'text' => $text !== '' ? $text : null,
            ];
            if ($type === 'public_reply') {
                $normalized['image_path'] = $imagePath !== '' ? $imagePath : null;
            }
            $steps[] = $normalized;
        }

        return ['steps' => $steps];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{trigger_field: string, trigger_hint: ?string, require_clear_match: bool}
     */
    private function normalizeLeadConfig(array $config, string $fallback = 'phone'): array
    {
        $field = $config['trigger_field'] ?? $fallback;
        if (! in_array($field, Workflow::triggerFields(), true)) {
            $field = $fallback;
        }
        $hint = is_string($config['trigger_hint'] ?? null) ? trim((string) $config['trigger_hint']) : '';
        if ($field === 'custom' && $hint === '') {
            throw new RuntimeException('Describe what the agent should look for.');
        }

        $requireClear = array_key_exists('require_clear_match', $config)
            ? (bool) $config['require_clear_match']
            : true;

        return [
            'trigger_field' => $field,
            'trigger_hint' => $hint !== '' ? $hint : null,
            'require_clear_match' => $requireClear,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private function engagementDisplaySteps(array $steps, int $postCount): array
    {
        $reply = collect($steps)->firstWhere('type', 'public_reply') ?? [];
        $dm = collect($steps)->firstWhere('type', 'private_dm') ?? [];
        $replyMode = ($reply['mode'] ?? 'agent') === 'fixed' ? 'Fixed text' : 'AI agent';
        $dmMode = ($dm['mode'] ?? 'agent') === 'fixed' ? 'Custom message' : 'Leave to agent';
        $replyOn = ($reply['enabled'] ?? true) !== false;
        $dmOn = ($dm['enabled'] ?? true) !== false;

        return [
            [
                'kind' => 'trigger',
                'key' => 'posts',
                'title' => 'Comment on posts',
                'body' => $postCount > 0 ? $postCount.' post'.($postCount === 1 ? '' : 's') : 'Pick posts',
                'color' => '#e91e63',
            ],
            [
                'kind' => 'action',
                'key' => 'public_reply',
                'title' => 'Public reply',
                'body' => $replyOn ? $replyMode : 'Off',
                'color' => '#1b70ff',
            ],
            [
                'kind' => 'action',
                'key' => 'private_dm',
                'title' => 'Auto DM',
                'body' => $dmOn ? $dmMode : 'Off',
                'color' => '#4caf50',
            ],
            ['kind' => 'chip', 'label' => 'Done'],
        ];
    }

    public function findActiveDmKeywordWorkflow(Business $business, string $platform, string $text): ?Workflow
    {
        $platform = strtolower(trim($platform));
        if ($platform === '' || trim($text) === '') {
            return null;
        }

        $workflows = Workflow::query()
            ->forBusiness($business->id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->where('template_key', 'dm_keyword')
                    ->orWhere('kind', Workflow::KIND_DM);
            })
            ->orderBy('id')
            ->get();

        foreach ($workflows as $workflow) {
            if (! $workflow->coversDmPlatform($platform)) {
                continue;
            }
            if ($workflow->matchesDmKeyword($text)) {
                return $workflow;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function createDmKeywordWorkflow(
        array $template,
        array $config,
        bool $activate,
        Business $business,
        ?string $name = null,
    ): array {
        if (! $activate) {
            throw new RuntimeException('Finish the canvas and save — incomplete drafts are not stored.');
        }

        $normalized = $this->normalizeDmConfig(
            array_merge($template['default_config'] ?? [], $config),
            true,
            true,
        );

        $title = is_string($name) && trim($name) !== ''
            ? trim($name)
            : ($template['title'] ?? 'DM keyword automation');

        $workflow = Workflow::query()->create([
            'business_id' => $business->id,
            'template_key' => 'dm_keyword',
            'kind' => Workflow::KIND_DM,
            'name' => $title,
            'status' => 'active',
            'config' => $normalized,
        ]);

        return $this->toArray($workflow->fresh('posts'));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{platform: string, platforms: list<string>, match: string, keywords: list<string>, steps: list<array<string, mixed>>}
     */
    private function normalizeDmConfig(array $config, bool $requireKeywords = false, bool $requirePlatforms = false): array
    {
        $platforms = [];
        $rawPlatforms = $config['platforms'] ?? null;
        if (is_array($rawPlatforms)) {
            foreach ($rawPlatforms as $id) {
                if (! is_string($id)) {
                    continue;
                }
                $normalized = strtolower(trim($id));
                if ($normalized !== '' && in_array($normalized, Workflow::dmPlatforms(), true) && ! in_array($normalized, $platforms, true)) {
                    $platforms[] = $normalized;
                }
            }
        } elseif (is_string($config['platform'] ?? null)) {
            $single = strtolower(trim((string) $config['platform']));
            if ($single !== '' && in_array($single, Workflow::dmPlatforms(), true)) {
                $platforms[] = $single;
            }
        }

        if (($requirePlatforms || $requireKeywords) && $platforms === []) {
            throw new RuntimeException('Select at least one platform.');
        }

        $keywords = [];
        foreach ((array) ($config['keywords'] ?? []) as $word) {
            if (! is_string($word)) {
                continue;
            }
            $trimmed = trim($word);
            if ($trimmed !== '' && ! in_array($trimmed, $keywords, true)) {
                $keywords[] = $trimmed;
            }
        }

        if ($requireKeywords && $keywords === []) {
            throw new RuntimeException('Add at least one keyword before activating.');
        }

        $incomingSteps = is_array($config['steps'] ?? null) ? $config['steps'] : [];
        $reply = [];
        foreach ($incomingSteps as $step) {
            if (is_array($step) && ($step['type'] ?? null) === 'dm_reply') {
                $reply = $step;
                break;
            }
        }
        if ($reply === [] && isset($config['mode'])) {
            $reply = [
                'type' => 'dm_reply',
                'mode' => $config['mode'],
                'text' => $config['text'] ?? null,
                'image_path' => $config['image_path'] ?? null,
            ];
        }

        $normalizedReply = $this->normalizeDmReplyStep($reply);
        if ($requireKeywords && ($normalizedReply['mode'] ?? '') === 'fixed' && trim((string) ($normalizedReply['text'] ?? '')) === '') {
            throw new RuntimeException('Fixed DM reply needs message text.');
        }

        return [
            'platform' => $platforms[0] ?? '',
            'platforms' => $platforms,
            'match' => 'contains',
            'keywords' => $keywords,
            'steps' => [$normalizedReply],
        ];
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array{type: string, mode: string, text: ?string, image_path: ?string}
     */
    private function normalizeDmReplyStep(array $step): array
    {
        $mode = ($step['mode'] ?? 'fixed') === 'agent' ? 'agent' : 'fixed';
        $text = is_string($step['text'] ?? null) ? trim((string) $step['text']) : '';
        $imagePath = is_string($step['image_path'] ?? null) ? trim((string) $step['image_path']) : '';

        return [
            'type' => 'dm_reply',
            'mode' => $mode,
            'text' => $mode === 'fixed' && $text !== '' ? $text : ($text !== '' ? $text : null),
            'image_path' => $mode === 'fixed' && $imagePath !== '' ? $imagePath : null,
        ];
    }

    /**
     * @param  list<string>  $platforms
     * @param  list<string>  $keywords
     * @return list<array<string, mixed>>
     */
    private function dmDisplaySteps(array $platforms, array $keywords, string $mode): array
    {
        $platformLabel = $platforms === []
            ? 'Pick platforms'
            : (count($platforms) === 1 ? ucfirst($platforms[0]) : count($platforms).' platforms');
        $keywordLabel = $keywords === []
            ? 'Add keywords'
            : count($keywords).' keyword'.(count($keywords) === 1 ? '' : 's');

        return [
            [
                'kind' => 'trigger',
                'key' => 'keywords',
                'title' => 'When someone messages',
                'body' => $platformLabel.' · '.$keywordLabel,
                'color' => '#e91e63',
            ],
            [
                'kind' => 'action',
                'key' => 'dm_reply',
                'title' => 'Send reply',
                'body' => $mode === 'agent' ? 'AI agent' : 'Fixed message',
                'color' => '#1b70ff',
            ],
            ['kind' => 'chip', 'label' => 'Done'],
        ];
    }
}
