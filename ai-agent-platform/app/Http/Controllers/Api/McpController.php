<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mcp\McpContext;
use App\Mcp\WaslMcpServer;
use App\Models\McpToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class McpController extends Controller
{
    public function __invoke(Request $request, WaslMcpServer $server): JsonResponse|Response
    {
        $token = McpToken::findActive((string) $request->bearerToken());
        if (! $token || ! $token->business || $token->business->status === 'suspended') {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $request->input('id'),
                'error' => ['code' => -32001, 'message' => 'Unauthorized'],
            ], 401);
        }

        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        $context = new McpContext(
            surface: McpContext::SURFACE_EXTERNAL,
            scopes: $token->scopes ?: [McpContext::SURFACE_EXTERNAL],
            userId: $token->user_id,
            tokenId: $token->id,
        );

        $payload = $request->json()->all();
        $isBatch = array_is_list($payload) && $payload !== [];
        $requests = $isBatch ? $payload : [$payload];

        $responses = [];
        foreach ($requests as $rpc) {
            $reply = $server->handle($token->business, is_array($rpc) ? $rpc : [], $context);
            if ($reply !== null) {
                $responses[] = $reply;
            }
        }

        if ($responses === []) {
            return response()->noContent(202);
        }

        return response()->json($isBatch ? $responses : $responses[0]);
    }
}
