<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentImageJob;
use App\Services\AgentImageJobService;
use App\Support\CurrentBusiness;
use Illuminate\Http\JsonResponse;

class AgentImageJobController extends Controller
{
    public function __construct(private AgentImageJobService $imageJobs) {}

    public function show(int $id): JsonResponse
    {
        $business = CurrentBusiness::require();
        $this->imageJobs->reconcileQueued($business);

        $job = AgentImageJob::query()
            ->where('business_id', $business->id)
            ->whereKey($id)
            ->firstOrFail();

        return response()->json(['job' => $job->toPublicArray()]);
    }
}
