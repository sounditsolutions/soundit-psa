<?php

namespace App\Jobs;

use App\Enums\TechnicianRunState;
use App\Models\TechnicianRun;
use App\Services\Tactical\OfflineActionSweep;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Run the offline-script queue for one agent that just came back online (bd
 * psa-xr84). Dispatched from the device-sync offline→online hook and the webhook
 * alert-resolved fast-path, so script execution happens off the sync/request path.
 * Idempotent: sweepAgent claims each run with a single-use latch, so overlapping
 * dispatches (sync + webhook) can't double-run.
 */
class SweepQueuedActionsForAgent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public readonly string $agentId) {}

    public function handle(OfflineActionSweep $sweep): void
    {
        $sweep->sweepAgent($this->agentId);
    }

    /**
     * Dispatch a sweep for an agent that just came online — but only if it actually
     * has unexpired queued actions, so a routine reconnect / resolved alert doesn't
     * spawn a no-op job. Shared by the device-sync hook and the webhook fast-path.
     */
    public static function dispatchIfQueued(string $agentId): void
    {
        if ($agentId === '') {
            return;
        }

        $hasQueued = TechnicianRun::query()
            ->where('state', TechnicianRunState::QueuedOffline->value)
            ->where('queued_agent_id', $agentId)
            ->where('expires_at', '>', now())
            ->exists();

        if ($hasQueued) {
            self::dispatch($agentId);
        }
    }
}
