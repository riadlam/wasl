<?php

namespace App\Services\Onboarding;

use App\AI\Prompts\ChannelAiProfilePrompt;
use App\AI\Providers\FalLlmProvider;
use App\Models\AiProfilePerChannel;
use App\Models\Business;
use App\Models\BusinessTrainingSnapshot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ChannelAiProfileService
{
    private const MAX_POST_IMAGES = 20;

    private const MAX_DM_IMAGES = 20;

    private const REQUIRED_KEYS = [
        'version',
        'business',
        'channel',
        'catalog_signals',
        'audience',
        'voice',
        'reply_guidance',
        'operations',
        'visual_signals',
        'hard_constraints',
        'evidence',
    ];

    public function __construct(
        private FalLlmProvider $llm,
        private ChannelAiProfilePrompt $prompt,
        private \App\AI\Runtime\SkAgentClient $skClient,
    ) {}

    public function buildForBusiness(Business $business): void
    {
        $business->update([
            'onboarding_status' => Business::ONBOARDING_AI_PROFILE,
            'onboarding_meta' => array_merge($business->onboarding_meta ?? [], [
                'profile_started_at' => now()->toIso8601String(),
                'error' => null,
            ]),
        ]);
        $business->refresh();

        $accounts = $this->profileAccounts($business);
        if ($accounts->isEmpty()) {
            throw new RuntimeException('No connected channels to build AI profile for.');
        }

        $built = 0;
        foreach ($accounts as $account) {
            $this->buildForAccount($business, $account);
            $built++;
        }

        if ($built < 1) {
            throw new RuntimeException('AI profile build produced no channel profiles.');
        }

        $business->update([
            'onboarding_status' => Business::ONBOARDING_DONE,
            'onboarding_meta' => array_merge($business->fresh()->onboarding_meta ?? [], [
                'profile_completed_at' => now()->toIso8601String(),
                'profiles_built' => $built,
                'error' => null,
            ]),
        ]);
    }

    public function buildForAccount(Business $business, SocialAccount $account): AiProfilePerChannel
    {
        $corpus = $this->corpusForAccount($business, $account);
        unset($corpus['images']); // identity agent is text+RAG; images not required for vector store

        $result = $this->skClient->buildIdentity(
            $business,
            (int) $account->id,
            $corpus,
            (string) config('services.fal.model', 'google/gemini-2.5-flash'),
        );

        if (empty($result['ok'])) {
            throw new RuntimeException(
                'BusinessIdentityAgent failed: '.((string) ($result['error'] ?? 'unknown error'))
            );
        }

        $chunks = (int) ($result['chunks_upserted'] ?? 0);
        if ($chunks < 1 && empty($result['summary'])) {
            throw new RuntimeException('BusinessIdentityAgent produced no identity chunks in Supabase.');
        }

        $counts = $corpus['counts'] ?? [];
        $statusProfile = [
            'storage' => 'supabase',
            'summary' => (string) ($result['summary'] ?? ''),
            'namespaces' => is_array($result['namespaces'] ?? null) ? $result['namespaces'] : [],
            'chunks_upserted' => $chunks,
            'social_account_id' => $account->id,
            'platform' => $account->platform,
        ];

        return AiProfilePerChannel::query()->updateOrCreate(
            [
                'business_id' => $business->id,
                'social_account_id' => $account->id,
            ],
            [
                'platform' => $account->platform,
                // Status metadata only — identity content lives in Supabase vectors.
                'profile' => $statusProfile,
                'model' => 'business-identity-agent+fal-embeddings',
                'source_counts' => array_merge($counts, [
                    'chunks_upserted' => $chunks,
                    'namespaces' => $statusProfile['namespaces'],
                ]),
                'status' => AiProfilePerChannel::STATUS_READY,
                'generated_at' => now(),
            ],
        );
    }

    /**
     * LEGACY_TEXT_PROFILE_REMOVED — requestTextProfile kept below for reference until deleted.
     * @param  array<string, mixed>  $corpus
     * @param  list<array{kind: string, external_id: string, url: string}>  $images
     * @return array<string, mixed>
     */
    private function requestTextProfile(array $corpus, array $images): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->prompt->system()],
            ['role' => 'user', 'content' => $this->prompt->user($corpus)],
        ];

        $options = [
            'temperature' => 0.1,
            'max_tokens' => 8000,
            'json_object' => true,
            'timeout' => 180,
        ];

        $last = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $response = $this->llm->chat($messages, [], $options);
            $raw = $this->extractContent($response);
            try {
                return $this->parseAndValidate($raw, $corpus, $images);
            } catch (RuntimeException $e) {
                $last = $e;
                if (! str_contains($e->getMessage(), 'not valid JSON')) {
                    throw $e;
                }
                Log::warning('onboarding.profile_json_retry', [
                    'attempt' => $attempt + 1,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        throw $last ?? new RuntimeException('LLM profile output was not valid JSON.');
    }

    /**
     * @param  list<array{kind: string, external_id: string, url: string}>  $images
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function requestVisualSignals(array $images, array $profile): array
    {
        $draft = [
            'business' => $profile['business'] ?? [],
            'channel' => $profile['channel'] ?? [],
            'catalog_signals' => array_slice(is_array($profile['catalog_signals'] ?? null) ? $profile['catalog_signals'] : [], 0, 12),
        ];

        $messages = [
            ['role' => 'system', 'content' => $this->prompt->systemVisual()],
            ['role' => 'user', 'content' => $this->prompt->userVisualContent($images, $draft)],
        ];

        $response = $this->llm->chat($messages, [], [
            'temperature' => 0.1,
            'max_tokens' => 2500,
            'json_object' => true,
            'timeout' => 180,
        ]);
        $raw = $this->normalizeModelJson($this->extractContent($response));
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $start = strpos($raw, '{');
            $end = strrpos($raw, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
            }
        }
        if (! is_array($decoded)) {
            $decoded = $this->repairTruncatedJson($raw) ?? [];
        }

        return $decoded;
    }

    /**
     * @return \Illuminate\Support\Collection<int, SocialAccount>
     */
    private function profileAccounts(Business $business)
    {
        $accountIds = $business->onboarding_meta['account_ids'] ?? null;
        $query = SocialAccount::query()
            ->where('business_id', $business->id)
            ->where('provider', 'socialapi')
            ->where('platform', '!=', 'simulator')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'disconnected');
            });

        if (is_array($accountIds) && $accountIds !== []) {
            $query->whereIn('id', $accountIds);
        }

        $accounts = $query->get();
        $preferred = $accounts->filter(fn (SocialAccount $a) => in_array($a->platform, ['facebook', 'instagram'], true));

        return $preferred->isNotEmpty() ? $preferred->values() : $accounts->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function corpusForAccount(Business $business, SocialAccount $account): array
    {
        $rows = BusinessTrainingSnapshot::query()
            ->where('business_id', $business->id)
            ->where('social_account_id', $account->id)
            ->whereIn('source', ['page', 'post', 'comment', 'dm'])
            ->orderBy('id')
            ->get();

        $page = [];
        $posts = [];
        $comments = [];
        $dms = [];

        foreach ($rows as $row) {
            $item = [
                'title' => $row->title,
                'content' => (string) $row->content,
                'external_id' => $row->external_id,
                'platform' => $row->platform,
            ];

            $payload = is_array($row->payload) ? $row->payload : [];
            if ($row->source === 'post') {
                $item['metrics'] = $payload['metrics'] ?? null;
                $item['caption'] = (string) ($payload['post']['caption'] ?? $row->content ?? '');
                $item['image_urls'] = $this->postImageUrls(is_array($payload['post'] ?? null) ? $payload['post'] : []);
                $posts[] = $item;
            } elseif ($row->source === 'comment') {
                $item['content'] = (string) $row->content;
                $item['post_id'] = $payload['post_id'] ?? null;
                $item['author'] = $payload['comment']['author_name'] ?? $payload['comment']['author_username'] ?? null;
                $comments[] = $item;
            } elseif ($row->source === 'dm') {
                $item['participant'] = $payload['conversation']['participant_name'] ?? null;
                $item['messages'] = $this->dmLines($business, $account, (string) $row->external_id);
                $dms[] = $item;
            } else {
                $page[] = $item;
            }
        }

        // Keep every gathered snapshot (training caps: 20 posts / 20 chats / 20 comments per post).
        $posts = array_slice($posts, 0, OnboardingTrainingService::POST_LIMIT);
        $comments = array_slice($comments, 0, OnboardingTrainingService::POST_LIMIT * OnboardingTrainingService::COMMENTS_PER_POST);
        $dms = array_slice($dms, 0, OnboardingTrainingService::CHAT_LIMIT);
        $images = $this->collectImages($posts, $business, $account);

        $counts = [
            'page' => count($page),
            'posts' => count($posts),
            'comments' => count($comments),
            'dms' => count($dms),
            'post_images' => count(array_filter($images, fn ($img) => $img['kind'] === 'post')),
            'dm_images' => count(array_filter($images, fn ($img) => $img['kind'] === 'dm')),
        ];

        return [
            'known_facts' => [
                'shop_name' => $business->name,
                'wilaya' => $business->wilaya,
                'city' => $business->city,
                'currency' => $business->currency,
                'platform' => $account->platform,
                'page_name' => $this->accountPageName($account),
                'username' => $account->username,
            ],
            'corpus_counts' => $counts,
            'account' => [
                'id' => $account->id,
                'platform' => $account->platform,
                'name' => $this->accountPageName($account),
                'username' => $account->username,
            ],
            'counts' => $counts,
            'page' => $page,
            'posts' => $posts,
            'comments' => $comments,
            'dms' => $dms,
            'images' => $images,
        ];
    }

    private function accountPageName(SocialAccount $account): string
    {
        $meta = is_array($account->metadata) ? $account->metadata : [];
        $fromMeta = trim((string) ($meta['page_name'] ?? ''));
        if ($fromMeta !== '') {
            return $fromMeta;
        }

        return trim((string) ($account->name ?: $account->username ?: ''));
    }

    /**
     * @param  list<array<string, mixed>>  $posts
     * @return list<array{kind: string, external_id: string, url: string}>
     */
    private function collectImages(array $posts, Business $business, SocialAccount $account): array
    {
        $images = [];
        $postCount = 0;
        foreach ($posts as $post) {
            foreach ($post['image_urls'] ?? [] as $url) {
                if ($postCount >= self::MAX_POST_IMAGES) {
                    break 2;
                }
                if (! is_string($url) || ! $this->isRemoteImageUrl($url)) {
                    continue;
                }
                $images[] = [
                    'kind' => 'post',
                    'external_id' => (string) ($post['external_id'] ?? ''),
                    'url' => $url,
                ];
                $postCount++;
            }
        }

        $dmCount = 0;
        $rows = Message::query()
            ->where('business_id', $business->id)
            ->whereNotNull('media_url')
            ->where('media_url', '!=', '')
            ->whereIn('conversation_id', Conversation::query()
                ->where('business_id', $business->id)
                ->where('social_account_id', $account->id)
                ->select('id'))
            ->orderByDesc('id')
            ->limit(40)
            ->get(['id', 'conversation_id', 'socialapi_message_id', 'media_url', 'media_type', 'type']);

        foreach ($rows as $message) {
            if ($dmCount >= self::MAX_DM_IMAGES) {
                break;
            }
            $url = (string) $message->media_url;
            if (! $this->isImageMedia($url, $message->media_type, $message->type)) {
                continue;
            }
            $images[] = [
                'kind' => 'dm',
                'external_id' => (string) ($message->socialapi_message_id ?: $message->id),
                'url' => $url,
            ];
            $dmCount++;
        }

        return $images;
    }

    /**
     * @param  array<string, mixed>  $post
     * @return list<string>
     */
    private function postImageUrls(array $post): array
    {
        $urls = [];
        $thumb = $post['thumbnail'] ?? null;
        if (is_string($thumb) && $this->isImageMedia($thumb, $post['media_type'] ?? null, null)) {
            $urls[] = $thumb;
        }
        $media = $post['media'] ?? [];
        if (is_array($media)) {
            foreach ($media as $item) {
                $url = is_string($item) ? $item : (is_array($item) ? ($item['url'] ?? null) : null);
                $type = is_array($item) ? ($item['type'] ?? null) : 'image';
                if (is_string($url) && $this->isImageMedia($url, $type, null)) {
                    $urls[] = $url;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return list<array{direction: ?string, text: string, image_url: ?string}>
     */
    private function dmLines(Business $business, SocialAccount $account, string $remoteConversationId): array
    {
        if ($remoteConversationId === '') {
            return [];
        }

        $conversation = Conversation::query()
            ->where('business_id', $business->id)
            ->where('social_account_id', $account->id)
            ->where('socialapi_conversation_id', $remoteConversationId)
            ->first();

        if (! $conversation) {
            return [];
        }

        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (Message $message) => [
                'direction' => $message->direction,
                'text' => (string) $message->text,
                'image_url' => $this->isImageMedia((string) $message->media_url, $message->media_type, $message->type)
                    ? $message->media_url
                    : null,
            ])
            ->all();
    }

    private function isImageMedia(?string $url, ?string $mediaType, ?string $type): bool
    {
        if (! is_string($url) || ! $this->isRemoteImageUrl($url)) {
            return false;
        }

        $kind = strtolower(trim((string) ($mediaType ?: $type ?: '')));
        if ($kind !== '' && preg_match('/video|audio|file|pdf|document/', $kind)) {
            return false;
        }
        if ($kind !== '' && preg_match('/image|photo|jpeg|jpg|png|webp|gif/', $kind)) {
            return true;
        }

        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return (bool) preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/', $path) || $kind === '';
    }

    private function isRemoteImageUrl(string $url): bool
    {
        return str_starts_with($url, 'https://') || str_starts_with($url, 'http://');
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function extractContent(array $response): string
    {
        $content = $response['choices'][0]['message']['content'] ?? null;
        if (is_array($content)) {
            // Some providers return content parts.
            $parts = [];
            foreach ($content as $part) {
                if (is_string($part)) {
                    $parts[] = $part;
                } elseif (is_array($part) && isset($part['text'])) {
                    $parts[] = (string) $part['text'];
                }
            }
            $content = implode("\n", $parts);
        }

        if (! is_string($content) || trim($content) === '') {
            Log::warning('onboarding.profile_empty_llm', ['response_keys' => array_keys($response)]);
            throw new RuntimeException('LLM returned empty profile content.');
        }

        return trim($content);
    }

    /**
     * @param  array<string, mixed>  $corpus
     * @param  list<array{kind: string, external_id: string, url: string}>  $images
     * @return array<string, mixed>
     */
    private function parseAndValidate(string $raw, array $corpus, array $images): array
    {
        $cleaned = $this->normalizeModelJson($raw);

        $decoded = json_decode($cleaned, true);
        if (! is_array($decoded)) {
            $start = strpos($cleaned, '{');
            $end = strrpos($cleaned, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($cleaned, $start, $end - $start + 1), true);
            }
        }

        if (! is_array($decoded)) {
            $decoded = $this->repairTruncatedJson($cleaned);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('LLM profile output was not valid JSON.');
        }

        $decoded = array_replace_recursive($this->emptyProfile(), $decoded);

        $facts = is_array($corpus['known_facts'] ?? null) ? $corpus['known_facts'] : [];
        $counts = is_array($corpus['counts'] ?? null) ? $corpus['counts'] : [];

        $decoded['version'] = 2;
        // Identity name is the linked page, never the shop owner / Wasl shop label.
        $pageName = trim((string) ($facts['page_name'] ?? ''));
        $pageUsername = trim((string) ($facts['username'] ?? ''));
        $decoded['business']['display_name'] = $pageName !== ''
            ? $pageName
            : ($pageUsername !== '' ? $pageUsername : '');
        if (! empty($facts['wilaya'])) {
            $decoded['business']['location_signals'] = array_values(array_unique(array_filter(array_merge(
                [(string) $facts['wilaya'], (string) ($facts['city'] ?? '')],
                is_array($decoded['business']['location_signals'] ?? null) ? $decoded['business']['location_signals'] : [],
            ))));
        }
        if (! empty($facts['currency'])) {
            $decoded['business']['currency_signals'] = array_values(array_unique(array_filter(array_merge(
                [(string) $facts['currency']],
                is_array($decoded['business']['currency_signals'] ?? null) ? $decoded['business']['currency_signals'] : [],
            ))));
        }
        $decoded['channel']['platform'] = (string) ($facts['platform'] ?? $decoded['channel']['platform'] ?? '');
        $decoded['channel']['page_name'] = (string) ($facts['page_name'] ?? '');
        $decoded['channel']['username'] = (string) ($facts['username'] ?? '');

        $decoded['catalog_signals'] = array_values(array_filter(
            is_array($decoded['catalog_signals']) ? $decoded['catalog_signals'] : [],
            fn ($row) => is_array($row) && trim((string) ($row['name_or_category'] ?? '')) !== '',
        ));

        $postImages = (int) ($counts['post_images'] ?? 0);
        $dmImages = (int) ($counts['dm_images'] ?? 0);
        $decoded['visual_signals']['post_images_seen'] = $postImages;
        $decoded['visual_signals']['dm_images_seen'] = $dmImages;
        $decoded['evidence']['posts_used'] = (int) ($counts['posts'] ?? 0);
        $decoded['evidence']['comments_used'] = (int) ($counts['comments'] ?? 0);
        $decoded['evidence']['dms_used'] = (int) ($counts['dms'] ?? 0);
        $decoded['evidence']['post_images_used'] = $postImages;
        $decoded['evidence']['dm_images_used'] = $dmImages;
        $decoded['evidence']['image_urls'] = array_map(fn ($img) => [
            'kind' => $img['kind'],
            'external_id' => $img['external_id'],
            'url' => $img['url'],
        ], $images);

        $decoded['hard_constraints'] = [
            'never_invent_prices' => true,
            'never_invent_stock' => true,
            'never_invent_policies' => true,
            'if_unknown' => 'say_you_will_check_or_handoff',
        ];

        foreach (self::REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $decoded)) {
                throw new RuntimeException("LLM profile JSON missing required key: {$key}");
            }
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyProfile(): array
    {
        return [
            'version' => 2,
            'business' => [
                'display_name' => '',
                'industry' => '',
                'what_we_sell' => '',
                'what_we_sell_detail' => '',
                'offer_type' => '',
                'location_signals' => [],
                'delivery_regions_seen' => [],
                'languages' => [],
                'currency_signals' => [],
                'payment_signals' => [],
            ],
            'channel' => [
                'platform' => '',
                'page_name' => '',
                'username' => '',
                'positioning' => '',
                'bio_signals' => [],
                'content_themes' => [],
                'cta_patterns' => [],
                'hashtags_seen' => [],
            ],
            'catalog_signals' => [],
            'audience' => [
                'who_buys' => '',
                'common_intents' => [],
                'objections' => [],
                'buying_objections' => [],
                'languages_seen' => [],
                'faqs' => [],
            ],
            'voice' => [
                'tone' => '',
                'typical_phrases' => [],
                'sample_replies_seen' => [],
                'do' => [],
                'dont' => [],
            ],
            'reply_guidance' => [
                'dm_patterns' => [],
                'comment_patterns' => [],
                'escalation_topics' => [],
            ],
            'operations' => [
                'delivery' => '',
                'returns' => '',
                'hours' => '',
                'contact_signals' => [],
            ],
            'visual_signals' => [
                'post_images_seen' => 0,
                'dm_images_seen' => 0,
                'what_products_look_like' => '',
                'packaging_or_branding' => '',
                'colors_and_style' => '',
                'notes' => [],
                'evidence_ids' => [],
            ],
            'hard_constraints' => [
                'never_invent_prices' => true,
                'never_invent_stock' => true,
                'never_invent_policies' => true,
                'if_unknown' => 'say_you_will_check_or_handoff',
            ],
            'evidence' => [
                'posts_used' => 0,
                'comments_used' => 0,
                'dms_used' => 0,
                'post_images_used' => 0,
                'dm_images_used' => 0,
                'confidence' => 'low',
                'gaps' => [],
                'quotes' => [],
                'image_urls' => [],
            ],
        ];
    }

    /**
     * Turn near-JSON model text into something json_decode can read.
     * Does not drop corpus fields; only cleans the model reply.
     */
    private function normalizeModelJson(string $raw): string
    {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $raw = trim($raw);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $raw, $m)) {
            $raw = trim($m[1]);
        }

        $raw = str_replace(
            ["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"],
            ['"', '"', "'", "'"],
            $raw,
        );

        // Some models emit {/n instead of a newline after the opening brace.
        $raw = preg_replace('/\{\s*\/n\s*/', "{\n", $raw) ?? $raw;
        $raw = preg_replace('/\{\s*\/r\/n\s*/', "{\n", $raw) ?? $raw;

        return $raw;
    }

    /**
     * Best-effort close of truncated JSON objects from the model.
     *
     * @return array<string, mixed>|null
     */
    private function repairTruncatedJson(string $raw): ?array
    {
        $start = strpos($raw, '{');
        if ($start === false) {
            return null;
        }

        $slice = substr($raw, $start);
        // Drop a trailing incomplete string value.
        $slice = preg_replace('/,\s*"[^"]*$/', '', $slice) ?? $slice;
        $slice = preg_replace('/:\s*"[^"]*$/', ': ""', $slice) ?? $slice;

        $opens = substr_count($slice, '{') - substr_count($slice, '}');
        $brackets = substr_count($slice, '[') - substr_count($slice, ']');
        if ($opens < 0 || $brackets < 0) {
            return null;
        }

        $slice .= str_repeat(']', max(0, $brackets)).str_repeat('}', max(0, $opens));
        $decoded = json_decode($slice, true);

        return is_array($decoded) ? $decoded : null;
    }
}
