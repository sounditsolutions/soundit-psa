<?php

namespace App\Observers;

use App\Enums\TicketSource;
use App\Jobs\RunTriagePipeline;
use App\Jobs\SendT2TCallback;
use App\Models\Ticket;
use App\Services\NotificationService;
use App\Support\T2TConfig;
use App\Support\TriageConfig;

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
        // Only fire for helpdesk_button tickets with status changes
        if ($ticket->source !== TicketSource::HelpdeskButton) {
            return;
        }

        if (! $ticket->wasChanged('status')) {
            return;
        }

        $callbackUrl = T2TConfig::get('callback_url');

        if (! $callbackUrl) {
            return;
        }

        SendT2TCallback::dispatch($ticket->id, $callbackUrl);
    }
}
