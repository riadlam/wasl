<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\McpToken;
use App\Support\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class McpTokenController extends Controller
{
    public function index(): JsonResponse
    {
        $business = CurrentBusiness::require();

        return response()->json([
            'endpoint' => url('/api/mcp'),
            'tokens' => McpToken::query()
                ->forBusiness($business->id)
                ->whereNull('revoked_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (McpToken $t) => $t->toApiArray())
                ->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string', Rule::in(McpToken::ALLOWED_SCOPES)],
        ]);

        [$token, $plain] = McpToken::issue(
            CurrentBusiness::require(),
            $request->user(),
            $data['name'],
            $data['scopes'] ?? [],
        );

        return response()->json([
            'token' => $token->toApiArray(),
            'plain_token' => $plain,
            'endpoint' => url('/api/mcp'),
        ], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $token = McpToken::query()
            ->forBusiness(CurrentBusiness::require()->id)
            ->whereNull('revoked_at')
            ->findOrFail($id);
        $token->update(['revoked_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
