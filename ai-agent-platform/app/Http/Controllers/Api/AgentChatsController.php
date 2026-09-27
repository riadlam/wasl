<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentChat;
use App\Support\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentChatsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = CurrentBusiness::require();

        $chats = AgentChat::query()
            ->where('business_id', $business->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (AgentChat $c) => $c->toListArray())
            ->values();

        return response()->json(['chats' => $chats]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = CurrentBusiness::require();
        $user = $request->user();

        $chat = AgentChat::query()->create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'title' => 'New chat',
            'last_message_at' => now(),
        ]);

        return response()->json(['chat' => $chat->toListArray()], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $business = CurrentBusiness::require();

        $chat = AgentChat::query()
            ->where('business_id', $business->id)
            ->whereKey($id)
            ->firstOrFail();

        $chat->messages()->delete();
        $chat->delete();

        return response()->json(['ok' => true]);
    }
}
