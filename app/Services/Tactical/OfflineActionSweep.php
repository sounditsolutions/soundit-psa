<?php

namespace App\Services\Tactical;

use App\Enums\TechnicianRunState;
use App\Models\TacticalAsset;
use App\Models\TechnicianRun;
use App\Services\Mcp\StaffTacticalActionToolExecutor;
use App\Support\TacticalConfig;

/**
 * Runs the offline-script queue (bd psa-xr84): dispatch queued actions when their
 * device returns online, and expire any that outlived their safety window. Every
 * run goes back through the executor's full gate-checked path, so a delayed run is
 * indistinguishable from a live approval. Mirrors the EmergencySweep shape — a
 * constructor-injected collaborator with a small set of entry points invoked from a
 * scheduled command, the device-sync hook, and the webhook fast-path.
 */
class OfflineActionSweep
{
    public function __construct(private readonly StaffTacticalActionToolExecutor $executor) {}

    /**
     * Run every unexpired queued action waiting on a specific agent that has just
     * come online. Returns the number that actually executed. No-op when the feature
     * is off (queued rows then sit until they expire).
     */
    public function sweepAgent(string $agentId): int
    {
        if ($agentId === '' || ! TacticalConfig::offlineQueueEnabled()) {
            return 0;
        }

        $ran = 0;
        TechnicianRun::query()
            ->where('state', TechnicianRunState::QueuedOffline->value)
            ->where('queued_agent_id', $agentId)
            ->where('expires_at', '>', now())
            ->orderBy('id')
            ->get()
            ->each(function (TechnicianRun $run) use (&$ran) {
                if ($this->executor->runQueuedOnReconnect($run)->status === 'executed') {
                    $ran++;
                }
            });

        return $ran;
    }

    /**
     * Fallback sweep (scheduled): expire stale queued actions, then run any whose
     * device currently reads online. Expiry runs regardless of the feature toggle so
     * a disabled queue still drains; dispatch is gated by the toggle via sweepAgent.
     *
     * @return array{ran: int, expired: int}
     */
    public function sweepDue(): array
    {
        $expired = $this->expireDue();
        $ran = 0;

        if (TacticalConfig::offlineQueueEnabled()) {
            $agentIds = TechnicianRun::query()
                ->where('state', TechnicianRunState::QueuedOffline->value)
                ->where('expires_at', '>', now())
                ->whereNotNull('queued_agent_id')
                ->distinct()
                ->pluck('queued_agent_id');

            $onlineAgents = TacticalAsset::query()
                ->whereIn('agent_id', $agentIds)
                ->where('status', 'online')
                ->pluck('agent_id');

            foreach ($onlineAgents as $agentId) {
                $ran += $this->sweepAgent((string) $agentId);
            }
        }

        return ['ran' => $ran, 'expired' => $expired];
    }

    /**
     * Expire queued actions past their safety window. They never auto-run stale —
     * an expired action is re-surfaced in the cockpit for explicit re-confirm.
     * Returns the number expired.
     */
    public function expireDue(): int
    {
        $expired = 0;
        TechnicianRun::query()
            ->where('state', TechnicianRunState::QueuedOffline->value)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->get()
            ->each(function (TechnicianRun $run) use (&$expired) {
                if ($run->expireQueued()) {
                    $expired++;
                }
            });

        return $expired;
    }
}
