<?php

namespace App\AI\Tools\Owner;

use App\AI\Tools\AgentTool;
use App\Models\AiProfilePerChannel;
use App\Models\Business;
use App\Models\SocialAccount;
use App\Services\SocialApi\SocialApiMcpClient;
use Illuminate\Support\Facades\Log;
use Throwable;

class GetBusinessContext implements AgentTool
{
    public function __construct(
        private SocialApiMcpClient $mcp,
        private \App\AI\Runtime\SkAgentClient $skClient,
    ) {}

    public function name(): string
    {
        return 'get_business_context';
    }

    public function description(): string
    {
        return 'Load shop business context only when needed. Default include=["identity"] returns channel identity STATUS plus RAG snippets from Supabase vectors (brand/tone). Add posts/comments/dms for live SocialAPI MCP data. Prefer knowledge_search for deep brand facts.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'include' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'enum' => ['identity', 'posts', 'comments', 'dms'],
                    ],
                    'description' => 'Sections to load. Default identity only.',
                ],
                'account_id' => [
                    'type' => 'integer',
                    'description' => 'Optional local Wasl social account id. Omit for up to 3 connected accounts.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max items per live list (1–10, default 5).',
                ],
            ],
            'required' => [],
        ];
    }

    public function handle(Business $business, array $arguments, array $context = []): array
    {
        $include = is_array($arguments['include'] ?? null) ? $arguments['include'] : ['identity'];
        $include = array_values(array_unique(array_filter(array_map(
            fn ($v) => is_string($v) ? strtolower(trim($v)) : '',
            $include,
        ))));
        if ($include === []) {
            $include = ['identity'];
        }

        $limit = (int) ($arguments['limit'] ?? 5);
        $limit = max(1, min(10, $limit));

        $accounts = $this->accounts($business, isset($arguments['account_id']) ? (int) $arguments['account_id'] : null);
        if ($accounts === []) {
            return ['error' => 'No connected social channels for this shop.'];
        }

        $out = [
            'ok' => true,
            'channels' => array_map(fn (SocialAccount $a) => [
                'id' => $a->id,
                'platform' => $a->platform,
                'name' => $a->name,
                'username' => $a->username,
                'socialapi_account_id' => $a->socialapi_account_id,
            ], $accounts),
        ];

        if (in_array('identity', $include, true)) {
            $out['identity'] = $this->identity($business, $accounts);
        }

        $live = array_intersect($include, ['posts', 'comments', 'dms']);
        if ($live !== []) {
            if (! $this->mcp->enabled()) {
                $out['live_error'] = 'SocialAPI MCP is not configured.';
            } else {
                foreach ($live as $section) {
                    $out[$section] = $this->fetchLive($section, $accounts, $limit);
                }
            }
        }

        return $this->trim($out);
    }

    /**
     * @return list<SocialAccount>
     */
    private function accounts(Business $business, ?int $accountId): array
    {
        $q = SocialAccount::query()
            ->where('business_id', $business->id)
            ->where('provider', 'socialapi')
            ->where('platform', '!=', 'simulator')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'disconnected');
            })
            ->orderBy('id');

        if ($accountId) {
            $q->whereKey($accountId);
        }

        return $q->limit($accountId ? 1 : 3)->get()->all();
    }

    /**
     * @param  list<SocialAccount>  $accounts
     * @return list<array<string, mixed>>
     */
    private function identity(Business $business, array $accounts): array
    {
        $ids = array_map(fn (SocialAccount $a) => $a->id, $accounts);

        $rows = AiProfilePerChannel::query()
            ->where('business_id', $business->id)
            ->whereIn('social_account_id', $ids)
            ->ready()
            ->get();

        $rag = $this->skClient->searchKnowledge(
            $business,
            'shop brand voice tone policies audience',
            null,
            8,
        );
        $hits = ($rag['ok'] ?? false) ? ($rag['hits'] ?? []) : [];

        return $rows->map(function (AiProfilePerChannel $p) use ($hits) {
            $meta = is_array($p->profile) ? $p->profile : [];
            $accountHits = array_values(array_filter(
                $hits,
                function ($hit) use ($p) {
                    if (! is_array($hit)) {
                        return false;
                    }
                    $metaHit = is_array($hit['metadata'] ?? null) ? $hit['metadata'] : [];

                    return (int) ($metaHit['social_account_id'] ?? 0) === (int) $p->social_account_id
                        || str_contains((string) ($hit['source_id'] ?? ''), (string) $p->social_account_id);
                }
            ));
            if ($accountHits === []) {
                $accountHits = array_slice($hits, 0, 4);
            }

            return [
                'social_account_id' => $p->social_account_id,
                'platform' => $p->platform,
                'storage' => 'supabase',
                'status' => $p->status,
                'summary' => (string) ($meta['summary'] ?? ''),
                'namespaces' => is_array($meta['namespaces'] ?? null) ? $meta['namespaces'] : [],
                'chunks_upserted' => (int) ($meta['chunks_upserted'] ?? 0),
                'vector_snippets' => array_map(fn ($h) => [
                    'namespace' => $h['namespace'] ?? null,
                    'content' => $h['content'] ?? null,
                    'score' => $h['score'] ?? null,
                ], array_slice($accountHits, 0, 6)),
                // Legacy key kept empty so callers do not treat JSON as identity SoR.
                'profile' => null,
            ];
        })->values()->all();
    }

    /**
     * @param  list<SocialAccount>  $accounts
     * @return array<string, mixed>
     */
    private function fetchLive(string $section, array $accounts, int $limit): array
    {
        $aliases = (array) config('socialapi_mcp.read_tools.'.$section, []);
        $toolName = $this->resolveToolName($aliases);
        if ($toolName === null) {
            return ['error' => 'No MCP read tool configured for '.$section.'.'];
        }

        $rows = [];
        foreach ($accounts as $account) {
            $remoteId = (string) ($account->socialapi_account_id ?? '');
            if ($remoteId === '') {
                $rows[] = [
                    'account_id' => $account->id,
                    'error' => 'Missing SocialAPI account id.',
                ];

                continue;
            }

            try {
                $result = $this->mcp->callTool($toolName, [
                    'account_id' => $remoteId,
                    'limit' => $limit,
                ]);
                $rows[] = [
                    'account_id' => $account->id,
                    'socialapi_account_id' => $remoteId,
                    'data' => $result,
                ];
            } catch (Throwable $e) {
                Log::warning('business_context.mcp_read_failed', [
                    'section' => $section,
                    'tool' => $toolName,
                    'message' => $e->getMessage(),
                ]);
                $rows[] = [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return ['tool' => $toolName, 'items' => $rows];
    }

    /**
     * @param  list<string>  $aliases
     */
    private function resolveToolName(array $aliases): ?string
    {
        $aliases = array_values(array_filter(array_map('strval', $aliases)));
        if ($aliases === []) {
            return null;
        }

        try {
            $listed = $this->mcp->listTools();
        } catch (Throwable) {
            return $aliases[0];
        }

        $available = [];
        foreach ($listed as $row) {
            if (is_array($row) && is_string($row['name'] ?? null)) {
                $available[strtolower($row['name'])] = $row['name'];
            }
        }

        foreach ($aliases as $alias) {
            $key = strtolower($alias);
            if (isset($available[$key])) {
                return $available[$key];
            }
        }

        return $aliases[0];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function trim(array $result): array
    {
        $json = json_encode($result, JSON_UNESCAPED_UNICODE);
        if (! is_string($json) || strlen($json) <= 6000) {
            return $result;
        }

        return ['ok' => true, 'truncated' => true, 'preview' => mb_substr($json, 0, 6000)];
    }
}
