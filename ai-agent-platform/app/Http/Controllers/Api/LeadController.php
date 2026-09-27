<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    public function __construct(private LeadService $leads) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:new,hot'],
        ]);

        return response()->json($this->leads->list(
            $data['search'] ?? null,
            $data['status'] ?? null,
        ));
    }

    public function show(int $id): JsonResponse
    {
        $lead = $this->leads->show($id);
        if (! $lead) {
            return response()->json(['message' => 'Lead not found.'], 404);
        }

        return response()->json(['lead' => $lead]);
    }
}
