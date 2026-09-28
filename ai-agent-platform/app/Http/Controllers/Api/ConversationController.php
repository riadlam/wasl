<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ConversationService;
use App\Support\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(private ConversationService $conversations) {}

    public function index(): JsonResponse
    {
        return response()->json(['conversations' => $this->conversations->list()]);
    }

    public function show(int $id): JsonResponse
    {
        $conversation = $this->conversations->find($id);

        return response()->json([
            'conversation' => $this->conversations->toInboxArray($conversation),
        ]);
    }

    public function simulate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'wilaya' => ['nullable', 'string', 'max:80'],
            'text' => ['required', 'string', 'max:4000'],
        ]);

        $conversation = $this->conversations->simulateInbound($data);

        return response()->json([
            'conversation' => $this->conversations->toInboxArray($conversation),
        ]);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:4000'],
        ]);
        $conversation = $this->conversations->find($id);
        $this->conversations->humanReply($conversation, $request->user(), $data['text']);

        return response()->json([
            'conversation' => $this->conversations->toInboxArray($this->conversations->find($id)),
        ]);
    }

    public function handoff(Request $request, int $id): JsonResponse
    {
        $conversation = $this->conversations->handoff($this->conversations->find($id), $request->user());

        return response()->json([
            'conversation' => $this->conversations->toInboxArray($conversation),
        ]);
    }

    public function resume(int $id): JsonResponse
    {
        $conversation = $this->conversations->resumeAi($this->conversations->find($id));

        return response()->json([
            'conversation' => $this->conversations->toInboxArray($conversation),
        ]);
    }

    public function history(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'cursor' => ['nullable', 'string', 'max:500'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $result = $this->conversations->syncHistory(
            $this->conversations->find($id),
            $data['cursor'] ?? null,
            (int) ($data['limit'] ?? 100),
            empty($data['cursor']),
        );

        return response()->json($result);
    }

    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'with_messages' => ['sometimes', 'boolean'],
        ]);

        $result = $this->conversations->syncRemoteInbox(
            null,
            array_key_exists('with_messages', $data) ? (bool) $data['with_messages'] : true,
        );

        return response()->json($result);
    }
}
