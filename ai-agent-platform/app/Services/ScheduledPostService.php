<?php

namespace App\Services;

use App\Models\Business;
use App\Models\SocialAccount;
use App\Services\SocialApi\SocialApiPostsService;
use App\Support\CurrentBusiness;
use App\Support\SocialPlatformLimits;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ScheduledPostService
{
    public function __construct(private SocialApiPostsService $posts) {}

    /**
     * @return array{posts: list<array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function list(
        ?string $status = 'scheduled',
        ?string $cursor = null,
        int $limit = 25,
        ?string $search = null,
    ): array {
        $business = CurrentBusiness::require();
        $accounts = $this->connectedAccounts($business);
        $remoteIds = $accounts->pluck('socialapi_account_id')->filter()->values()->all();

        if ($remoteIds === []) {
            return [
                'posts' => [],
                'pagination' => ['has_more' => false, 'next_cursor' => null],
            ];
        }

        $status = in_array($status, ['scheduled', 'draft', 'failed'], true) ? $status : 'scheduled';
        $remote = $this->posts->listByStatus(
            $remoteIds,
            $status,
            $search,
            $cursor,
            $limit,
            null,
            $status === 'scheduled' ? 'scheduled_asc' : 'created_desc',
        );

        $rows = is_array($remote['data'] ?? null) ? $remote['data'] : [];
        $byRemote = $accounts->keyBy('socialapi_account_id');

        return [
            'posts' => array_map(fn (array $row) => $this->toArray($row, $byRemote), $rows),
            'pagination' => [
                'has_more' => (bool) ($remote['pagination']['has_more'] ?? false),
                'next_cursor' => $remote['pagination']['next_cursor'] ?? null,
            ],
        ];
    }

    /**
     * @return array{limits: array<string, array{max_media: int, max_text: int}>}
     */
    public function platformLimits(): array
    {
        return ['limits' => SocialPlatformLimits::all()];
    }

    /**
     * @return array{media_id: string}
     */
    public function uploadMedia(UploadedFile $file): array
    {
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            throw new RuntimeException('Could not read uploaded file.');
        }

        return $this->posts->uploadMedia(
            $contents,
            $file->getClientOriginalName() ?: 'upload.jpg',
            $file->getMimeType(),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $business = CurrentBusiness::require();
        $accounts = $this->resolveOwnedAccounts($business, $data['account_ids'] ?? []);
        $platforms = $accounts->pluck('platform')->filter()->values()->all();
        $maxMedia = SocialPlatformLimits::maxMedia($platforms);
        $maxText = SocialPlatformLimits::maxText($platforms);

        $text = (string) ($data['text'] ?? '');
        if (mb_strlen($text) > $maxText) {
            throw ValidationException::withMessages([
                'text' => "Caption is too long for the selected platforms (max {$maxText} characters).",
            ]);
        }

        $mediaIds = array_values(array_filter(array_map(
            fn ($id) => is_string($id) ? trim($id) : '',
            is_array($data['media_ids'] ?? null) ? $data['media_ids'] : [],
        )));

        if (count($mediaIds) > $maxMedia) {
            throw ValidationException::withMessages([
                'media_ids' => $maxMedia === 0
                    ? 'Selected platforms do not support image attachments.'
                    : "Selected platforms allow at most {$maxMedia} image(s).",
            ]);
        }

        $payload = [
            'text' => $text,
            'scheduled_at' => Carbon::parse($data['scheduled_at'])->utc()->toIso8601String(),
            'targets' => $accounts->map(fn (SocialAccount $account) => [
                'account_id' => $account->socialapi_account_id,
            ])->values()->all(),
        ];

        if ($mediaIds !== []) {
            $payload['media'] = array_map(
                fn (string $id) => ['source_type' => 'media_id', 'source' => $id],
                $mediaIds,
            );
        }

        $remote = $this->posts->createPost($payload);
        $row = is_array($remote['data'] ?? null) ? $remote['data'] : $remote;

        return $this->toArray($row, $accounts->keyBy('socialapi_account_id'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(string $postId, array $data): array
    {
        $this->assertOwnedPost($postId);

        $payload = [];
        if (array_key_exists('text', $data) && $data['text'] !== null) {
            $payload['text'] = $data['text'];
        }
        if (! empty($data['scheduled_at'])) {
            $payload['scheduled_at'] = Carbon::parse($data['scheduled_at'])->utc()->toIso8601String();
        }
        if (array_key_exists('media_ids', $data) && is_array($data['media_ids'])) {
            $mediaIds = array_values(array_filter(array_map(
                fn ($id) => is_string($id) ? trim($id) : '',
                $data['media_ids'],
            )));
            $payload['media'] = array_map(
                fn (string $id) => ['source_type' => 'media_id', 'source' => $id],
                $mediaIds,
            );
        }
        if ($payload === []) {
            throw ValidationException::withMessages(['text' => 'Nothing to update.']);
        }

        $remote = $this->posts->updatePost($postId, $payload);
        $row = is_array($remote['data'] ?? null) ? $remote['data'] : $remote;
        $accounts = $this->connectedAccounts(CurrentBusiness::require())->keyBy('socialapi_account_id');

        return $this->toArray($row, $accounts);
    }

    public function delete(string $postId): void
    {
        $this->assertOwnedPost($postId);
        $this->posts->deletePost($postId);
    }

    /**
     * @return array<string, mixed>
     */
    public function publish(string $postId): array
    {
        $this->assertOwnedPost($postId);
        $remote = $this->posts->publishPost($postId);
        $row = is_array($remote['data'] ?? null) ? $remote['data'] : $remote;
        $accounts = $this->connectedAccounts(CurrentBusiness::require())->keyBy('socialapi_account_id');

        return $this->toArray($row, $accounts);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  \Illuminate\Support\Collection<string, SocialAccount>  $byRemote
     * @return array<string, mixed>
     */
    public function toArray(array $row, $byRemote): array
    {
        $targets = [];
        foreach (is_array($row['targets'] ?? null) ? $row['targets'] : [] as $target) {
            if (! is_array($target)) {
                continue;
            }
            $remoteId = (string) ($target['account_id'] ?? '');
            $account = $remoteId !== '' ? $byRemote->get($remoteId) : null;
            $targets[] = [
                'account_id' => $remoteId,
                'local_account_id' => $account?->id,
                'account_name' => $account?->name ?: ($account?->username ?: null),
                'platform' => $target['platform'] ?? $account?->platform,
                'status' => $target['status'] ?? null,
            ];
        }

        $media = [];
        foreach (is_array($row['media'] ?? null) ? $row['media'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $sourceType = $item['source_type'] ?? null;
            $source = isset($item['source']) ? (string) $item['source'] : null;
            $mediaId = null;
            if (($sourceType === 'media_id' || $sourceType === null) && $source) {
                $mediaId = $source;
            }
            if (! empty($item['media_id'])) {
                $mediaId = (string) $item['media_id'];
            }
            $url = $this->firstString([
                $item['url'] ?? null,
                $item['preview_url'] ?? null,
                $item['thumbnail_url'] ?? null,
                $item['thumbnail'] ?? null,
                ($sourceType === 'url' ? $source : null),
            ]);
            $media[] = [
                'media_id' => $mediaId,
                'type' => $item['type'] ?? null,
                'source' => $source,
                'source_type' => $sourceType,
                'url' => $url,
            ];
        }

        // Older responses may only include media_ids[].
        if ($media === [] && is_array($row['media_ids'] ?? null)) {
            foreach ($row['media_ids'] as $id) {
                if (! is_string($id) || $id === '') {
                    continue;
                }
                $media[] = [
                    'media_id' => $id,
                    'type' => null,
                    'source' => $id,
                    'source_type' => 'media_id',
                    'url' => null,
                ];
            }
        }

        return [
            'id' => (string) ($row['id'] ?? ''),
            'text' => $row['text'] ?? '',
            'title' => $row['title'] ?? null,
            'status' => $row['status'] ?? null,
            'scheduled_at' => $row['scheduled_at'] ?? null,
            'published_at' => $row['published_at'] ?? null,
            'targets' => $targets,
            'media' => $media,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * @param  list<mixed>  $candidates
     */
    private function firstString(array $candidates): ?string
    {
        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @return \Illuminate\Support\Collection<int, SocialAccount>
     */
    private function connectedAccounts(Business $business)
    {
        return $business->socialAccounts()
            ->where('platform', '!=', 'simulator')
            ->where(function ($query) {
                $query->whereNull('status')->orWhere('status', '!=', 'disconnected');
            })
            ->whereNotNull('socialapi_account_id')
            ->get();
    }

    /**
     * @param  list<int|string>  $accountIds
     * @return \Illuminate\Support\Collection<int, SocialAccount>
     */
    private function resolveOwnedAccounts(Business $business, array $accountIds)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        if ($ids === []) {
            throw ValidationException::withMessages(['account_ids' => 'Select at least one connected page.']);
        }

        $accounts = $this->connectedAccounts($business)->whereIn('id', $ids)->values();
        if ($accounts->count() !== count($ids)) {
            throw ValidationException::withMessages(['account_ids' => 'One or more pages are not linked to this shop.']);
        }

        foreach ($accounts as $account) {
            if (! is_string($account->socialapi_account_id) || $account->socialapi_account_id === '') {
                throw ValidationException::withMessages(['account_ids' => 'A selected page is missing its SocialAPI id.']);
            }
        }

        return $accounts;
    }

    private function assertOwnedPost(string $postId): void
    {
        $business = CurrentBusiness::require();
        $accounts = $this->connectedAccounts($business)->keyBy('socialapi_account_id');
        if ($accounts->isEmpty()) {
            throw new RuntimeException('No connected SocialAPI accounts.');
        }

        try {
            $remote = $this->posts->getPost($postId);
        } catch (\Throwable $e) {
            throw new RuntimeException('Scheduled post not found.');
        }

        $row = is_array($remote['data'] ?? null) ? $remote['data'] : $remote;
        $targets = is_array($row['targets'] ?? null) ? $row['targets'] : [];
        $owned = false;
        foreach ($targets as $target) {
            $id = is_array($target) ? (string) ($target['account_id'] ?? '') : '';
            if ($id !== '' && $accounts->has($id)) {
                $owned = true;
                break;
            }
        }

        if (! $owned) {
            throw new RuntimeException('This scheduled post does not belong to your shop.');
        }
    }
}
