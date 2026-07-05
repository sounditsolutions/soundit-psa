<?php

namespace App\Observers;

use App\Enums\NoteType;
use App\Enums\WhoType;
use App\Models\TicketNote;
use App\Services\PrepayService;
use App\Services\Signals\SignalHub;
use Illuminate\Support\Facades\Log;

class TicketNoteObserver
{
    public function __construct(
        private readonly PrepayService $prepayService,
    ) {}

    public function created(TicketNote $note): void
    {
        $this->emitClientReplySignal($note);
        $this->syncPrepayDebit($note);
    }

    public function updated(TicketNote $note): void
    {
        $this->syncPrepayDebit($note);
    }

    public function deleted(TicketNote $note): void
    {
        try {
            $this->prepayService->reverseDebitForTicketNote($note);
        } catch (\Throwable $e) {
            Log::warning('[TicketNoteObserver] Failed to reverse prepay debit on delete', [
                'note_id' => $note->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncPrepayDebit(TicketNote $note): void
    {
        if (! $note->time_minutes) {
            return;
        }

        try {
            $this->prepayService->debitFromTicketNote($note);
        } catch (\Throwable $e) {
            Log::warning('[TicketNoteObserver] Failed to sync prepay debit', [
                'note_id' => $note->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function emitClientReplySignal(TicketNote $note): void
    {
        if ($note->note_type !== NoteType::Reply || $note->is_private || $note->who_type !== WhoType::EndUser) {
            return;
        }

        $ticket = $note->ticket;
        if ($ticket === null) {
            return;
        }

        try {
            app(SignalHub::class)->emit('ticket.client_replied', $ticket, 'client replied', [
                'client_id' => $ticket->client_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[TicketNoteObserver] Failed to emit client-replied signal', [
                'note_id' => $note->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
