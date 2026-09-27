<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiCampaign;
use App\Models\AiCampaignSlot;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Uptime probe for the worker and scheduler. Returns 503 when the scheduler heartbeat is stale,
 * queued jobs are waiting too long, or campaign slots are overdue. Carries no shop data.
 */
class QueueHealthController extends Controller
{
    private const HEARTBEAT_STALE_MINUTES = 5;

    private const JOB_WAIT_LIMIT_MINUTES = 15;

    public function __invoke(): JsonResponse
    {
        $problems = [];
        $connection = (string) config('queue.default');

        $heartbeat = Cache::get('ops.scheduler_heartbeat');
        $heartbeatAge = $heartbeat ? (int) Carbon::parse($heartbeat)->diffInMinutes(now(), true) : null;
        if ($heartbeatAge === null || $heartbeatAge > self::HEARTBEAT_STALE_MINUTES) {
            $problems[] = 'scheduler_stale';
        }

        $queues = [];
        $oldestWait = null;
        if ($connection === 'database' && Schema::hasTable('jobs')) {
            try {
                $rows = DB::table('jobs')
                    ->selectRaw('queue, count(*) as total, min(available_at) as oldest')
                    ->groupBy('queue')
                    ->get();
                foreach ($rows as $row) {
                    $wait = $row->oldest ? max(0, (int) floor((now()->timestamp - (int) $row->oldest) / 60)) : 0;
                    $queues[$row->queue] = ['pending' => (int) $row->total, 'oldest_wait_minutes' => $wait];
                    $oldestWait = max($oldestWait ?? 0, $wait);
                }
            } catch (Throwable) {
                $problems[] = 'jobs_unreadable';
            }
            if ($oldestWait !== null && $oldestWait > self::JOB_WAIT_LIMIT_MINUTES) {
                $problems[] = 'worker_backlog';
            }
        }

        $failed24h = null;
        if (Schema::hasTable('failed_jobs')) {
            $failed24h = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        }

        $overdueSlots = AiCampaignSlot::query()
            ->where('status', AiCampaignSlot::STATUS_PENDING)
            ->where('scheduled_at', '<', now()->subMinutes(10))
            ->whereHas('campaign', fn ($q) => $q->whereIn('status', AiCampaign::ACTIVE_STATUSES))
            ->count();
        if ($overdueSlots > 0) {
            $problems[] = 'campaign_slots_overdue';
        }

        return response()->json([
            'ok' => $problems === [],
            'problems' => $problems,
            'connection' => $connection,
            'queues' => (object) $queues,
            'failed_jobs_24h' => $failed24h,
            'scheduler_heartbeat' => $heartbeat,
            'scheduler_heartbeat_age_minutes' => $heartbeatAge,
            'campaign_slots_overdue' => $overdueSlots,
            'checked_at' => now()->toIso8601String(),
        ], $problems === [] ? 200 : 503);
    }
}
