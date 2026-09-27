<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProfileInterviewService;
use App\Support\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileInterviewController extends Controller
{
    public function __construct(private ProfileInterviewService $interviews) {}

    public function show(): JsonResponse
    {
        return response()->json($this->interviews->state(CurrentBusiness::require()));
    }

    public function start(Request $request): JsonResponse
    {
        return response()->json($this->interviews->start(CurrentBusiness::require(), $request->user()));
    }

    public function abandon(): JsonResponse
    {
        return response()->json($this->interviews->abandon(CurrentBusiness::require()));
    }
}
