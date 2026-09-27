<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PostCatalogService;
use App\Support\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PostAiSettingController extends Controller
{
    public function __construct(private PostCatalogService $posts) {}

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.account_id' => ['required', 'integer'],
            'items.*.platform_post_id' => ['required', 'string', 'max:191'],
            'items.*.ai_comment_reply' => ['sometimes', 'boolean'],
            'items.*.ai_private_reply' => ['sometimes', 'boolean'],
            'items.*.comment_mode' => ['sometimes', 'string', Rule::in(['agent', 'fixed'])],
            'items.*.fixed_comment_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'items.*.fixed_comment_image_path' => ['sometimes', 'nullable', 'string', 'max:500'],
            'items.*.dm_mode' => ['sometimes', 'string', Rule::in(['agent', 'fixed'])],
            'items.*.fixed_dm_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $validator = validator($data);
        $validator->after(function (Validator $validator) use ($data) {
            foreach ($data['items'] as $index => $item) {
                $commentOn = (bool) ($item['ai_comment_reply'] ?? false);
                $commentMode = $item['comment_mode'] ?? 'agent';
                if ($commentOn && $commentMode === 'fixed' && trim((string) ($item['fixed_comment_text'] ?? '')) === '') {
                    $validator->errors()->add(
                        "items.{$index}.fixed_comment_text",
                        'A fixed comment reply requires message text.',
                    );
                }

                $dmOn = (bool) ($item['ai_private_reply'] ?? false);
                $dmMode = $item['dm_mode'] ?? 'agent';
                if ($dmOn && $dmMode === 'fixed' && trim((string) ($item['fixed_dm_text'] ?? '')) === '') {
                    $validator->errors()->add(
                        "items.{$index}.fixed_dm_text",
                        'A custom Auto DM requires message text.',
                    );
                }
            }
        });
        $validator->validate();

        $saved = $this->posts->upsertSettings($data['items']);

        return response()->json(['settings' => $saved]);
    }

    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'in:selection,loaded'],
            'ai_comment_reply' => ['sometimes', 'boolean'],
            'ai_private_reply' => ['sometimes', 'boolean'],
            'posts' => ['required', 'array', 'min:1', 'max:200'],
            'posts.*.account_id' => ['required', 'integer'],
            'posts.*.platform_post_id' => ['required', 'string', 'max:191'],
        ]);

        if (! array_key_exists('ai_comment_reply', $data) && ! array_key_exists('ai_private_reply', $data)) {
            return response()->json(['message' => 'Choose at least one AI toggle to apply.'], 422);
        }

        $saved = $this->posts->bulkApply(
            $data['posts'],
            array_key_exists('ai_comment_reply', $data) ? (bool) $data['ai_comment_reply'] : null,
            array_key_exists('ai_private_reply', $data) ? (bool) $data['ai_private_reply'] : null,
        );

        return response()->json([
            'settings' => $saved,
            'updated' => count($saved),
        ]);
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $business = CurrentBusiness::require();
        $path = $data['image']->store('post-ai-replies/'.$business->id, 'public');

        return response()->json([
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ]);
    }
}
