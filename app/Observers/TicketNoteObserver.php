<?php

namespace App\Observers;

use App\Enums\NoteType;
use App\Enums\WhoType;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\ContractResolver;
use App\Services\PrepayService;
use App\Services\Signals\SignalHub;
use Illuminate\Support\Facades\Log;

class TicketNoteObserver
{
    public function __construct(
        private readonly PrepayService $prepayService,
        private readonly ContractResolver $resolver,
    ) {}

    public function saving(TicketNote $note): void
    {
        // An ordinary edit cannot erase source provenance, even after verification.
        if ($note->exists && $note->getRawOriginal('contact_intake_origin')) {
            $note->contact_intake_origin = true;
        }
        // #5067 r3 group A: a negative time adjustment prices the note at the part of a Halo
        // action drawn from prepay (the rest was charged outside it). A new time would move that
        // draw with no defined split, so an ordinary edit cannot change such a note's time.
        if ($note->exists && (int) $note->getRawOriginal('time_adjustment_minutes') < 0 && $note->isDirty('time_minutes')) {
            $note->time_minutes = $note->getOriginal('time_minutes');
        }
        // Provenance is stamped ONLY by the intake writer (SubmissionProcessor), never by
        // ticket state: a staff, system or inbound-email note on an unverified intake ticket
        // is not visitor text (r1 diff:2/7/8). Containment applies while unverified; after
        // staff verification the note behaves like any internal note (ruling 2026-09-24).
        if ($note->contact_intake_origin && $note->contact_intake_verified_at === null) {
            $note->is_private = true;
            $note->is_billable = false;
            $note->time_minutes = 0;
            $note->contract_id = null;
            $note->email_id = null;

            return;
        }

        $this->stampContract($note);
    }

    /**
     * Stamp the contract this note's time belongs to when time is logged
     * (card I3EvQKUV §3), so a later change to the ticket's contract does not
     * move it. A note that already has a ledger row keeps that row's contract;
     * otherwise ContractResolver answers, and ambiguity or unverified contact
     * intake leaves the note's contract NULL; the debit path can hold it pending
     * contract choice or verification.
     */
    private function stampContract(TicketNote $note): void
    {
        if ($note->contract_id !== null || $note->billedMinutes() <= 0) {
            return;
        }

        if ($note->exists) {
            $ledgerContractId = PrepayTransaction::where('ticket_note_id', $note->id)->value('contract_id');
            if ($ledgerContractId !== null) {
                $note->contract_id = (int) $ledgerContractId;

                return;
            }
        }

        $ticket = $note->ticket_id === null ? null : Ticket::find($note->ticket_id);
        if (! $ticket) {
            return;
        }

        $resolution = $this->resolver->forEntry($ticket);
        if ($resolution->isResolved()) {
            $note->contract_id = $resolution->contract->id;
        }
    }

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
        if ($note->isUnverifiedContactIntake() || ($note->billedMinutes() <= 0 && ! $note->wasChanged('time_minutes') && ! $note->wasChanged('billable_minutes'))) {
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
        if ($note->isUnverifiedContactIntake() || $note->note_type !== NoteType::Reply || $note->is_private || $note->who_type !== WhoType::EndUser) {
            return;
        }

        $ticket = $note->ticket;
        // A held form ticket is contained as a whole: a note on it, such as an inbound email
        // threaded by [T-id], wakes nothing until staff verify the ticket (diff:1).
        if ($ticket === null || $ticket->isUnverifiedContactIntake()) {
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
