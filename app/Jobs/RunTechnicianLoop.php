<?php

namespace App\Jobs;

use App\Enums\ClientStage;
use App\Enums\TechnicianRunState;
use App\Models\TechnicianRun;
use App\Models\Ticket;
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

        // Phase 0: the AutoAcknowledge task appends its call here.
    }
}
