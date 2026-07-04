<?php

namespace App\Jobs;

use App\Models\TacticalWebhook;
use App\Services\Tactical\TacticalAlertService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessTacticalWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $webhookId,
    ) {}

    public function handle(TacticalAlertService $alertService): void
    {
        $webhook = TacticalWebhook::find($this->webhookId);

        // Idempotent: a retry (or a duplicate dispatch) of an already-handled row no-ops.
        if (! $webhook || ! $webhook->isPending()) {
            return;
        }

        $event = $webhook->event;
        $payload = $webhook->payload ?? [];

        switch ($event) {
            case 'alert_failure':
                $alert = $alertService->handleAlertFailure($payload);
                break;

            case 'alert_resolved':
                $alert = $alertService->handleAlertResolved($payload);
                break;

            default:
                // Unknown event is a no-op we acknowledge, not a failure.
                $webhook->markSkipped("Unhandled event: {$event}");

                return;
        }

        // A null result means the service intentionally dropped it (below severity threshold,
        // transient/noise filter, or no matching open alert for a resolve) — retain the row as
        // skipped with its payload intact, rather than marking it processed or failed.
        if ($alert === null) {
            $webhook->markSkipped("Event '{$event}' produced no alert (filtered or no match)");

            return;
        }

        $webhook->markProcessed();

        // Fast-path (bd psa-xr84): a resolved alert for an agent with queued actions is
        // a low-latency signal the device may be back — trigger a targeted sweep so its
        // queue runs without waiting for the next device sync. Best-effort: Tactical
        // drops availability alerts for workstations (G4), so the device-sync hook is
        // the authoritative/complete trigger; this only accelerates the server case.
        if ($event === 'alert_resolved' && $webhook->agent_id) {
            SweepQueuedActionsForAgent::dispatchIfQueued((string) $webhook->agent_id);
        }
    }

    public function failed(\Throwable $e): void
    {
        $webhook = TacticalWebhook::find($this->webhookId);

        if ($webhook) {
            $webhook->markFailed($e->getMessage());
            // Never log the full payload — only the routing keys.
            Log::error('[Tactical Webhook] Processing failed', [
                'webhook_id' => $this->webhookId,
                'event' => $webhook->event,
                'agent_id' => $webhook->agent_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
