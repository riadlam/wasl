<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PostCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function __construct(private PostCatalogService $posts) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:200'],
            'cursor' => ['nullable', 'string', 'max:2000'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'source' => ['nullable', 'in:posts,inbox_comments'],
        ]);

        $user = $request->user();
        if ($user instanceof \App\Models\User && ! empty($data['account_id'])) {
            $business = \App\Support\CurrentBusiness::require();
            $scope = $user->scopedSocialAccountIds($business->id);
            if (is_array($scope)) {
                $account = \App\Models\SocialAccount::query()
                    ->where('business_id', $business->id)
                    ->where(function ($q) use ($data) {
                        $q->where('id', $data['account_id'])
                            ->orWhere('socialapi_account_id', $data['account_id']);
                    })
                    ->first();
                if (! $account || ! in_array((int) $account->id, $scope, true)) {
                    return response()->json(['message' => 'You do not have access to this channel.'], 403);
                }
            }
        }

        try {
            $result = $this->posts->list(
                $data['account_id'] ?? null,
                $data['platform'] ?? null,
                $data['search'] ?? null,
                $data['cursor'] ?? null,
                (int) ($data['limit'] ?? 20),
                null,
                $data['source'] ?? null,
            );
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Could not load posts.',
            ], 422);
        }

        return response()->json($result);
    }

    public function previews(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'max:20'],
            'items.*.platform_post_id' => ['required', 'string', 'max:200'],
            'items.*.permalink' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $result = $this->posts->previews($data['items']);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Could not load post previews.',
            ], 422);
        }

        return response()->json($result);
    }
}
