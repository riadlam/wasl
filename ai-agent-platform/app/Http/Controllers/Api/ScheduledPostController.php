<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ScheduledPostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ScheduledPostController extends Controller
{
    public function __construct(private ScheduledPostService $posts) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:scheduled,draft,failed'],
            'cursor' => ['nullable', 'string', 'max:2000'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'search' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $result = $this->posts->list(
                $data['status'] ?? 'scheduled',
                $data['cursor'] ?? null,
                (int) ($data['limit'] ?? 25),
                $data['search'] ?? null,
            );
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Could not load scheduled posts.',
            ], 422);
        }

        return response()->json($result);
    }

    public function limits(): JsonResponse
    {
        return response()->json($this->posts->platformLimits());
    }

    public function uploadMedia(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'max:51200'],
        ]);

        try {
            $result = $this->posts->uploadMedia($request->file('image'));
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Could not upload image.',
            ], 422);
        }

        return response()->json($result, 201);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:65000'],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'account_ids' => ['required', 'array', 'min:1'],
            'account_ids.*' => ['integer'],
            'media_ids' => ['nullable', 'array', 'max:35'],
            'media_ids.*' => ['string', 'max:120'],
        ]);

        try {
            $post = $this->posts->create($data);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Could not schedule post.',
            ], 422);
        }

        return response()->json(['post' => $post], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:65000'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'media_ids' => ['nullable', 'array', 'max:35'],
            'media_ids.*' => ['string', 'max:120'],
        ]);

        try {
            $post = $this->posts->update($id, $data);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Could not update scheduled post.',
            ], 422);
        }

        return response()->json(['post' => $post]);
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $this->posts->delete($id);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Could not cancel scheduled post.',
            ], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function publish(string $id): JsonResponse
    {
        try {
            $post = $this->posts->publish($id);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Could not publish post.',
            ], 422);
        }

        return response()->json(['post' => $post]);
    }
}
