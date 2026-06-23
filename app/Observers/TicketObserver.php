<?php

namespace App\Observers;

use App\Enums\ClientStage;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Jobs\GenerateTicketResolution;
use App\Jobs\MineTicketKnowledge;
use App\Jobs\RunTechnicianLoop;
use App\Jobs\RunTriagePipeline;
use App\Jobs\SendT2TCallback;
use App\Models\Ticket;
use App\Services\NotificationService;
use App\Support\T2TConfig;
use App\Support\TechnicianConfig;
use App\Support\TriageConfig;
use App\Support\WikiConfig;

class TicketObserver
{
    /**
     * Notify technicians and auto-dispatch triage when a new ticket is created.
     */
    public function created(Ticket $ticket): void
    {
        // Prospect gate: never notify or triage for prospect-stage clients.
        // Data must not reach the LLM or notification pipeline during the sales phase.
        if ($ticket->client?->stage === ClientStage::Prospect) {
            return;
        }

        app(NotificationService::class)->notifyTicketCreated($ticket);

        // Recursion guard: skip AI dispatches for tickets created by the system/AI-actor user.
        $isSystemCreated = $ticket->created_by && $ticket->created_by === TriageConfig::systemUserId();

        if (! $isSystemCreated) {
            if (TriageConfig::autoTriageEnabled()) {
                RunTriagePipeline::dispatch($ticket->id, 'triage');
            }

            // AI Technician Loop (spec §4.1) — same prospect gate (above) + the same
            // system-user recursion guard as triage. Gated by TechnicianConfig::enabled().
            if (TechnicianConfig::enabled()) {
                RunTechnicianLoop::dispatch($ticket->id);
            }
        }
    }

    public function updated(Ticket $ticket): void
    {
        // T2T callback — only for HelpdeskButton tickets, on a status change.
        if ($ticket->source === TicketSource::HelpdeskButton && $ticket->wasChanged('status')) {
            $callbackUrl = T2TConfig::get('callback_url');

            if ($callbackUrl) {
                SendT2TCallback::dispatch($ticket->id, $callbackUrl);
            }
        }

        // Wiki mining (spec §5.1 trigger 2; mining-coverage Decision 3): fire when the ticket
        // reaches a terminal state (Resolved or Closed) with a resolution, OR when the
        // resolution is added/edited while already terminal (resolve-first-write-later).
        // Idempotency (hash on resolution text) means a later auto-close does not re-mine,
        // and editing the resolution does re-mine (captures the correction).
        $isTerminal = in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true);
        $becameTerminalOrResolutionChanged = $ticket->wasChanged('status') || $ticket->wasChanged('resolution');

        // Prospect gate: don't mine or auto-draft resolutions for prospect-stage clients.
        $isProspect = $ticket->client?->stage === ClientStage::Prospect;

        if (
            ! $isProspect
            && $isTerminal
            && $becameTerminalOrResolutionChanged
            && filled($ticket->resolution)
            && WikiConfig::autoMineEnabled()
        ) {
            MineTicketKnowledge::dispatch($ticket->id);
        }

        // Auto-fallback: if a ticket reaches a terminal state with NO resolution, queue a job
        // to AI-draft one so the wiki mining loop always has something to mine (spec §T4).
        // Keyed on wasChanged('status') — the status *transition* — so when the job later
        // writes `resolution` (status unchanged), this branch does NOT re-fire → no loop.
        // The mining branch above uses wasChanged('resolution'), so it DOES fire on that save.
        if (
            ! $isProspect
            && $isTerminal
            && $ticket->wasChanged('status')
            && empty($ticket->resolution)
            && WikiConfig::autoMineEnabled()
        ) {
            GenerateTicketResolution::dispatch($ticket->id);
        }
    }
}
