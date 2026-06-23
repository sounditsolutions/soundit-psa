<?php

namespace App\Jobs;

use App\Enums\ClientStage;
use App\Enums\TechnicianRunState;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Technician\AutoAcknowledge;
use App\Services\Technician\DraftPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The Loop dispatch seam (spec §4.1). Mirrors RunTriagePipeline: dispatched from
 * TicketObserver::created, prospect-gated, on a dedicated 'technician' queue so
 * Technician load can't starve billing/email jobs. Phase 0: create/load the
 * run, then run the auto-ack (wired in the AutoAcknowledge task).
 */
class RunTechnicianLoop implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(private readonly int $ticketId)
    {
        $this->onQueue('technician');
    }

    public function handle(): void
    {
        $ticket = Ticket::find($this->ticketId);

        if (! $ticket) {
            Log::warning('[Technician] Ticket not found', ['ticket_id' => $this->ticketId]);

            return;
        }

        // Choke-point prospect gate (mirrors RunTriagePipeline) — no Technician
        // work for prospect-stage clients regardless of dispatch site.
        if ($ticket->client?->stage === ClientStage::Prospect) {
            Log::debug('[Technician] Skipping — prospect client', ['ticket_id' => $this->ticketId]);

            return;
        }

        $run = TechnicianRun::firstOrCreate(
            [
                'ticket_id' => $ticket->id,
                'action_type' => 'send_ack',
                'content_hash' => hash('sha256', 'send_ack:'.$ticket->id),
            ],
            [
                'client_id' => $ticket->client_id,
                'state' => TechnicianRunState::Gathering,
            ],
        );

        // Only run the ack while the run is still pre-send (idempotent re-runs
        // that find a Done run do nothing — the gate's content_hash + the run
        // state both guard against a duplicate send).
        if ($run->state === TechnicianRunState::Gathering) {
            app(AutoAcknowledge::class)->run($run, $ticket);
        }

        // Phase 1A: the autonomous draft pipeline — gathers, judges ownability,
        // drafts a reply + proposes a resolution, and HOLDS them for approval.
        // Idempotent + budget-guarded; nothing substantive is sent here.
        app(DraftPipeline::class)->run($ticket);
    }
}
