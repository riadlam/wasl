<?php

namespace App\Services\Campaigns;

use App\Models\AgentAsset;
use App\Services\SocialApi\SocialApiMcpClient;
use App\Services\SocialApi\SocialApiPostsService;
use RuntimeException;

/**
 * Campaign calls to SocialAPI MCP. Argument names come from the live tools/list schema;
 * without a schema they follow the REST-verified shape (targets[], media[] with source_type).
 * Media bytes go through the REST upload endpoint — MCP only exposes a presigned URL flow.
 */
class CampaignMcpGateway
{
    public function __construct(
        private SocialApiMcpClient $mcp,
        private SocialApiMcpSchema $schema,
        private SocialApiPostsService $posts,
    ) {}

    public function assertEnabled(): void
    {
        if (! $this->mcp->enabled()) {
            throw new RuntimeException('SocialAPI MCP is not enabled for this shop.');
        }
    }

    /**
     * @param  list<string>  $accountIds
     */
    public function listRecentPosts(array $accountIds, int $limit = 8): string
    {
        $this->assertEnabled();
        $limit = max(1, min(20, $limit));
        $tool = $this->tool('list_posts');
        $args = ['limit' => $limit];
        $accountKey = $this->schema->firstProperty($tool, ['account_ids', 'account_id', 'accounts']) ?? 'account_ids';
        $prop = $this->schema->properties($tool)[$accountKey] ?? [];
        $type = $prop['type'] ?? null;
        if ($type === 'string' || (is_array($type) && in_array('string', $type, true) && ! in_array('array', $type, true))) {
            $args[$accountKey] = implode(',', array_values($accountIds));
        } elseif ($accountKey === 'account_id') {
            $args[$accountKey] = $accountIds[0] ?? null;
        } else {
            $args[$accountKey] = array_values($accountIds);
        }

        return $this->asText($this->mcp->callTool($tool, $args));
    }

    public function uploadAsset(AgentAsset $asset): string
    {
        $tool = null;
        try {
            $tool = $this->tool('upload_media');
        } catch (RuntimeException) {
            $tool = null;
        }

        // Live SocialAPI MCP only exposes a presigned URL flow — use REST multipart upload.
        if ($tool === null || str_contains($tool, 'upload_url') || str_contains($tool, 'get_media_upload')) {
            $bytes = $asset->rawBytes();
            if ($bytes === null || $bytes === '') {
                $url = $asset->absoluteUrl();
                if ($url === '') {
                    throw new RuntimeException('Campaign image has no file contents and no public URL.');
                }

                return 'url:'.$url;
            }

            $uploaded = $this->posts->uploadMedia(
                $bytes,
                $asset->original_name ?: ('asset-'.$asset->id.'.jpg'),
                $asset->mime ?: 'image/jpeg',
            );

            return $uploaded['media_id'];
        }

        $args = [
            $this->schema->firstProperty($tool, ['url', 'source', 'media_url', 'file_url']) ?? 'url' => $asset->absoluteUrl(),
            $this->schema->firstProperty($tool, ['filename', 'file_name', 'name']) ?? 'filename' => $asset->original_name ?: ('asset-'.$asset->id),
            $this->schema->firstProperty($tool, ['mime', 'mime_type', 'content_type']) ?? 'mime' => $asset->mime,
        ];

        $id = $this->findId($this->mcp->callTool($tool, $args), ['media_id', 'id']);
        if ($id === null || $id === '') {
            throw new RuntimeException('SocialAPI MCP '.$tool.' did not return a media id.');
        }

        return $id;
    }

    /**
     * Schedule one creative for a set of accounts on the same platform.
     *
     * @param  list<string>  $accountIds
     * @param  list<string>  $mediaIds  media library ids, or "url:https://..." markers from uploadAsset
     * @return array<string, string>  SocialAPI account id => post id
     */
    public function schedulePost(
        array $accountIds,
        string $caption,
        string $scheduledAtIso,
        array $mediaIds = [],
        bool $story = false,
        ?string $requestKey = null,
    ): array {
        $this->assertEnabled();
        $tool = $this->tool('create_post');
        $known = $this->schema->known();
        $props = $this->schema->properties($tool);

        $base = [
            ($this->schema->firstProperty($tool, ['text', 'content', 'caption', 'message']) ?? 'text') => $caption,
            ($this->schema->firstProperty($tool, ['scheduled_at', 'schedule_at', 'publish_at', 'scheduled_time']) ?? 'scheduled_at') => $scheduledAtIso,
        ];

        if ($mediaIds !== []) {
            $base = array_merge($base, $this->mediaArgs($tool, $mediaIds, $known, $props));
        }

        if ($requestKey && ($key = $this->schema->firstProperty($tool, ['idempotency_key', 'client_request_key', 'client_request_id', 'request_id']))) {
            $base[$key] = $requestKey;
        }

        $accountMode = ! $known || array_key_exists('targets', $props)
            ? 'targets'
            : (array_key_exists('account_ids', $props) ? 'account_ids' : (array_key_exists('account_id', $props) ? 'account_id' : 'targets'));

        $calls = $accountMode === 'account_id'
            ? array_map(fn (string $id) => [$id], $accountIds)
            : [array_values($accountIds)];

        $ids = [];
        foreach ($calls as $group) {
            $args = $base;
            if ($accountMode === 'targets') {
                $args['targets'] = array_map(fn (string $id) => ['account_id' => $id], $group);
            } elseif ($accountMode === 'account_ids') {
                $args['account_ids'] = $group;
            } else {
                $args['account_id'] = $group[0];
            }
            if ($story) {
                $args = $this->applyStory($tool, $args, $known);
            }

            $ids += $this->postIds($this->mcp->callTool($tool, $args), $group, $tool);
        }

        return $ids;
    }

    public function deletePost(string $postId): void
    {
        $this->assertEnabled();
        $tool = $this->tool('delete_post');
        $key = $this->schema->firstProperty($tool, ['post_id', 'id']) ?? 'post_id';
        $this->mcp->callTool($tool, [$key => $postId]);
    }

    public function tool(string $key): string
    {
        $name = $this->schema->resolveTool($key);
        if ($name === null || $name === '') {
            throw new RuntimeException('SocialAPI MCP has no tool for "'.$key.'". Check config/socialapi_mcp.php campaign_tools.');
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function asText(array $result): string
    {
        $content = $result['content'] ?? null;
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $block) {
                if (is_array($block) && isset($block['text'])) {
                    $parts[] = (string) $block['text'];
                }
            }
            if ($parts !== []) {
                return mb_substr(implode("\n", $parts), 0, 4000);
            }
        }

        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? mb_substr($encoded, 0, 4000) : '';
    }

    /**
     * @param  list<string>  $mediaIds
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private function mediaArgs(string $tool, array $mediaIds, bool $known, array $props): array
    {
        $items = array_map(function (string $id) {
            if (str_starts_with($id, 'url:')) {
                return ['source_type' => 'url', 'source' => substr($id, 4), 'type' => 'image'];
            }

            return ['source_type' => 'media_id', 'source' => $id];
        }, $mediaIds);

        $objects = fn () => ['media' => $items];

        if (! $known || array_key_exists('media', $props)) {
            $item = $known ? $this->schema->itemProperties($tool, 'media') : ['source_type' => []];
            if (! $known || array_key_exists('source_type', $item) || $item === []) {
                return $objects();
            }
            if (array_key_exists('media_id', $item)) {
                return ['media' => array_map(fn (string $id) => ['media_id' => str_starts_with($id, 'url:') ? substr($id, 4) : $id], $mediaIds)];
            }

            return ['media' => array_values(array_map(fn (string $id) => str_starts_with($id, 'url:') ? substr($id, 4) : $id, $mediaIds))];
        }
        if (array_key_exists('media_ids', $props)) {
            return ['media_ids' => array_values(array_map(fn (string $id) => str_starts_with($id, 'url:') ? substr($id, 4) : $id, $mediaIds))];
        }

        throw new RuntimeException('SocialAPI MCP '.$tool.' does not accept media.');
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function applyStory(string $tool, array $args, bool $known): array
    {
        $field = $known ? $this->schema->storyField($tool) : null;
        if ($field === null) {
            if (isset($args['targets']) && is_array($args['targets'])) {
                foreach ($args['targets'] as $i => $target) {
                    $args['targets'][$i]['platform_data'] = array_merge(
                        is_array($target['platform_data'] ?? null) ? $target['platform_data'] : [],
                        ['content_type' => 'story', 'post_type' => 'story'],
                    );
                }
            } else {
                $args['platform_data'] = array_merge(
                    is_array($args['platform_data'] ?? null) ? $args['platform_data'] : [],
                    ['content_type' => 'story', 'post_type' => 'story'],
                );
            }

            return $args;
        }

        $path = $field['path'];
        if (($path[1] ?? null) === '*' && isset($args[$path[0]]) && is_array($args[$path[0]])) {
            $rest = array_slice($path, 2);
            foreach ($args[$path[0]] as $i => $item) {
                data_set($item, implode('.', $rest), $field['value']);
                $args[$path[0]][$i] = $item;
            }

            return $args;
        }

        data_set($args, implode('.', $path), $field['value']);

        return $args;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<string>  $accountIds
     * @return array<string, string>
     */
    private function postIds(array $result, array $accountIds, string $tool): array
    {
        $data = is_array($result['structuredContent'] ?? null) ? $result['structuredContent'] : $result;
        if ($data === $result && isset($result['content'])) {
            $decoded = json_decode($this->asText($result), true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        foreach (['posts', 'targets', 'results'] as $listKey) {
            $rows = $data[$listKey] ?? ($data['data'][$listKey] ?? null);
            if (! is_array($rows)) {
                continue;
            }
            $map = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $account = (string) ($row['account_id'] ?? '');
                $id = (string) ($row['post_id'] ?? $row['id'] ?? '');
                if ($account !== '' && $id !== '' && in_array($account, $accountIds, true)) {
                    $map[$account] = $id;
                }
            }
            if ($map !== []) {
                return $map;
            }
        }

        $single = $this->findId($data, ['post_id', 'id']) ?? $this->findId($result, ['post_id', 'id']);
        if ($single === null || $single === '') {
            throw new RuntimeException('SocialAPI MCP '.$tool.' did not return a post id.');
        }

        return array_fill_keys($accountIds, $single);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<string>  $keys
     */
    private function findId(array $result, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! empty($result[$key]) && is_scalar($result[$key])) {
                return (string) $result[$key];
            }
            if (! empty($result['data'][$key]) && is_scalar($result['data'][$key])) {
                return (string) $result['data'][$key];
            }
        }
        $pattern = '/"(?:'.implode('|', array_map('preg_quote', $keys)).')"\s*:\s*"?([A-Za-z0-9_\-:.]+)"?/';
        if (preg_match($pattern, $this->asText($result), $match)) {
            return $match[1];
        }

        return null;
    }
}
