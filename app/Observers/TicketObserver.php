<?php

namespace App\Observers;

use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Jobs\MineTicketKnowledge;
use App\Jobs\RunTriagePipeline;
use App\Jobs\SendT2TCallback;
use App\Models\Ticket;
use App\Services\NotificationService;
use App\Support\T2TConfig;
use App\Support\TriageConfig;
use App\Support\WikiConfig;

class TicketObserver
{
    /**
     * Notify technicians and auto-dispatch triage when a new ticket is created.
     */
    public function created(Ticket $ticket): void
    {
        app(NotificationService::class)->notifyTicketCreated($ticket);

        if (! TriageConfig::autoTriageEnabled()) {
            return;
        }

        // Recursion guard: don't triage tickets created by the triage system itself
        if ($ticket->created_by && $ticket->created_by === TriageConfig::systemUserId()) {
            return;
        }

        RunTriagePipeline::dispatch($ticket->id, 'triage');
    }

    public function updated(Ticket $ticket): void
    {
        if (! $ticket->wasChanged('status')) {
            return;
        }

        // T2T callback — only for HelpdeskButton tickets
        if ($ticket->source === TicketSource::HelpdeskButton) {
            $callbackUrl = T2TConfig::get('callback_url');

            if ($callbackUrl) {
                SendT2TCallback::dispatch($ticket->id, $callbackUrl);
            }
        }

        // Wiki mining — fires for ALL close paths (spec §5.1 trigger 2)
        if (
            $ticket->status === TicketStatus::Closed
            && filled($ticket->resolution)
            && WikiConfig::autoMineEnabled()
        ) {
            MineTicketKnowledge::dispatch($ticket->id);
        }
    }
}
