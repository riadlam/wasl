<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgentImageJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FalImageWebhookController extends Controller
{
    public function __invoke(Request $request, AgentImageJobService $jobs): JsonResponse
    {
        $payload = $request->json()->all();
        if (! is_array($payload)) {
            $payload = [];
        }

        $jobs->completeFromWebhook($payload);

        return response()->json(['ok' => true]);
    }
}
