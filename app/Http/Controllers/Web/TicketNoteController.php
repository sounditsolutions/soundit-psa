<?php

namespace App\Http\Controllers\Web;

use App\Enums\NoteType;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\ContactIntakeContainedException;
use App\Services\ContractResolution;
use App\Services\ContractResolver;
use App\Services\EmailService;
use App\Services\TicketService;
use App\Support\AppTimezone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class TicketNoteController extends Controller
{
    public function __construct(
        private readonly TicketService $ticketService,
        private readonly EmailService $emailService,
        private readonly ContractResolver $contractResolver,
    ) {}

    public function store(Request $request, Ticket $ticket)
    {
        $request->validate([
            'body' => ['required', 'string'],
            'note_type' => ['required', 'in:note,reply'],
            'is_private' => ['boolean'],
            'time' => ['nullable', 'string'],
            'to_email' => ['nullable', 'email', 'max:255'],
            'cc_emails' => ['nullable', 'string', 'max:1000'],
            'new_status' => ['nullable', 'string'],
            'resolution' => ['nullable', 'string'],
            'is_billable' => ['nullable'],
            'contract_id' => ['nullable', 'exists:contracts,id'],
        ]);

        $type = NoteType::from($request->input('note_type'));
        $isPrivate = $request->boolean('is_private', $type === NoteType::Note);
        $timeMinutes = $this->ticketService->parseTimeInput($request->input('time'));

        if ($timeMinutes !== null && $timeMinutes > 1440) {
            return redirect()->route('tickets.show', $ticket)
                ->with('error', 'Time logged cannot exceed 24 hours per entry.');
        }

        // Billable override: if time is logged, pass explicit value from checkbox (or null for auto)
        $isBillable = $timeMinutes ? $request->boolean('is_billable') : null;

        // Only pass contract_id when time is being logged
        $contractId = $timeMinutes ? ($request->input('contract_id') ?: null) : null;

        if ($timeMinutes && ($refusal = $this->contractRefusal($ticket, $contractId))) {
            return $refusal;
        }

        $note = $this->ticketService->addNote(
            $ticket,
            $request->input('body'),
            $type,
            $isPrivate,
            auth()->id(),
            $timeMinutes,
            isBillable: $isBillable,
            contractId: $contractId,
        );

        // Track flash message type and text so status change can happen regardless of email outcome
        $flashType = 'success';
        $flashMessage = ucfirst($type->label()).' added.';

        if ($note->note_type === NoteType::Reply && ! $note->is_private) {
            $toEmail = trim($request->input('to_email', '')) ?: $ticket->contact?->email;
            $ccEmails = $this->parseCcEmails($request->input('cc_emails'));

            if (! $toEmail) {
                $flashType = 'warning';
                $flashMessage = 'Reply added, but no recipient email provided — the client was not notified.';
            } else {
                try {
                    $email = $this->emailService->sendTicketReplyNote($ticket, $note, $toEmail, $ccEmails);

                    if ($email) {
                        $note->update(['email_id' => $email->id]);
                        $recipients = $toEmail;
                        if ($ccEmails) {
                            $recipients .= ' (cc: '.implode(', ', $ccEmails).')';
                        }
                        $flashMessage = 'Reply added and emailed to '.$recipients.'.';
                    } else {
                        $flashType = 'warning';
                        $flashMessage = 'Reply added, but email is not configured — the client was not notified.';
                    }
                } catch (ContactIntakeContainedException) {
                    // A containment refusal, not a delivery failure: never tell staff to mail
                    // unverified intake by hand (c1:v3:1).
                    $flashType = 'warning';
                    $flashMessage = 'Reply added, but not emailed: outbound mail is withheld from unverified web-form intake until staff verify it.';
                } catch (\Throwable $e) {
                    Log::warning('[TicketNote] Failed to send reply email', [
                        'ticket' => $ticket->id,
                        'note' => $note->id,
                        'error' => $e->getMessage(),
                    ]);
                    $flashType = 'warning';
                    $flashMessage = 'Reply added, but email delivery failed — the client was not notified. Please send manually.';
                }
            }
        }

        // Optional status change — never lose the note if this fails
        if ($newStatusValue = $request->input('new_status')) {
            $newStatus = TicketStatus::tryFrom($newStatusValue);
            if ($newStatus && in_array($newStatus, $ticket->status->allowedTransitions(), true)) {
                // Resolving must record what was done — the resolution feeds future
                // tickets and the client wiki. A whitespace-only summary counts as
                // empty. Keep the note and skip the resolve with a warning, mirroring
                // the status-change failure path below.
                $resolution = trim((string) $request->input('resolution', ''));

                if ($newStatus === TicketStatus::Resolved && $resolution === '') {
                    $flashType = 'warning';
                    $flashMessage .= ' Not resolved — add a brief resolution summary to resolve this ticket.';
                } else {
                    try {
                        $this->ticketService->changeStatus(
                            $ticket,
                            $newStatus,
                            auth()->id(),
                            resolution: $resolution !== '' ? $resolution : null,
                        );
                        $flashMessage .= " Status changed to {$newStatus->label()}.";
                    } catch (\InvalidArgumentException $e) {
                        $flashType = 'warning';
                        $flashMessage .= ' Status change failed: '.$e->getMessage();
                    }
                }
            }
        }

        return redirect()->route('tickets.show', $ticket)
            ->with($flashType, $flashMessage);
    }

    public function update(Request $request, Ticket $ticket, TicketNote $note)
    {
        if ($note->ticket_id !== $ticket->id) {
            abort(404);
        }

        if ($note->note_type->isSystemGenerated()) {
            return redirect()->route('tickets.show', $ticket)
                ->with('error', 'System-generated notes cannot be edited.');
        }

        $request->validate([
            'body' => ['required', 'string'],
            'note_type' => ['required', 'in:note,reply,phone_call,resolution'],
            'is_private' => ['boolean'],
            'time' => ['nullable', 'string'],
            'is_billable' => ['nullable'],
            'contract_id' => ['nullable', 'exists:contracts,id'],
            'noted_at' => ['nullable', 'date'],
        ]);

        $timeMinutes = $this->ticketService->parseTimeInput($request->input('time'));

        if ($timeMinutes !== null && $timeMinutes > 1440) {
            return redirect()->route('tickets.show', $ticket)
                ->with('error', 'Time logged cannot exceed 24 hours per entry.');
        }

        // Optional note date/time adjustment. The picker is wall-clock in the
        // display timezone; DB stores UTC. Reject future dates (a note can't
        // have happened later than now).
        $notedAt = null;
        if ($request->filled('noted_at')) {
            $notedAt = Carbon::parse($request->input('noted_at'), AppTimezone::get())->setTimezone('UTC');

            if ($notedAt->isFuture()) {
                return redirect()->route('tickets.show', $ticket)
                    ->with('error', 'Note date cannot be in the future.');
            }
        }

        // A negative time adjustment (#5067 r3 group A) prices the note at the part of a Halo
        // action drawn from prepay; the observer keeps its time fixed, so a change is refused here.
        if ((int) $note->time_adjustment_minutes < 0 && (int) $timeMinutes !== (int) $note->time_minutes) {
            return redirect()->route('tickets.show', $ticket)
                ->with('error', "Only part of this note's time was drawn from prepay in Halo, so its time cannot be edited. Leave the time unchanged.");
        }

        // A note carrying a time adjustment (#5067 r3) still has priced time when its own
        // time field is empty, so its billable flag and contract are kept like any timed note's.
        $hasTime = $timeMinutes || (int) $note->time_adjustment_minutes > 0;
        $isBillable = $hasTime ? $request->boolean('is_billable') : null;
        $contractId = $hasTime ? ($request->input('contract_id') ?: null) : null;

        if ($hasTime) {
            // Debited time stays on the contract it was debited from; an edit cannot
            // re-point it (card I3EvQKUV: moving time is its own explicit act). The form
            // preselects the note's own stamp, so resubmitting it unchanged is never a
            // move (r1 diff:7).
            $currentStamp = $note->contract_id === null ? null : (int) $note->contract_id;
            $submitted = $contractId === null ? null : (int) $contractId;
            $ledgerContractId = PrepayTransaction::where('ticket_note_id', $note->id)->value('contract_id');
            if ($ledgerContractId !== null) {
                // The edit keeps the note's own stamp, else the ledger's. A stamp that differs
                // from its ledger row is left alone (ruling Q6): rewriting it here would let the
                // debit path re-sync the ledger row it refuses for such a note.
                $ledgerContractId = (int) $ledgerContractId;
                $keptStamp = $currentStamp ?? $ledgerContractId;
                if ($submitted !== null && $submitted !== $keptStamp) {
                    return $this->refuseContract($ticket, $keptStamp === $ledgerContractId
                        ? 'This time is already debited from another contract, and editing the note cannot move it. Leave the contract unchanged.'
                        : "This note's contract differs from the contract its time was debited from, and editing the note cannot change either. Leave the contract unchanged.");
                }
                $contractId = $keptStamp;
            } else {
                // An unchanged stamp that no longer validates (expired, deleted, ticket moved)
                // resolves afresh, as the debit path does, rather than refusing the field. A valid
                // stamp on a ticket waiting for verification is kept (card VLyhWACq, C10).
                if ($submitted !== null && $submitted === $currentStamp && $this->contractResolver->forEntry($ticket, $submitted)->status === ContractResolution::INVALID_PICK) {
                    $contractId = null;
                }
                if ($refusal = $this->contractRefusal($ticket, $contractId)) {
                    return $refusal;
                }
            }
        }

        $attributes = [
            'body' => $request->input('body'),
            'body_html' => \App\Helpers\MarkdownRenderer::render($request->input('body')),
            'note_type' => $request->input('note_type'),
            'is_private' => $request->boolean('is_private'),
            'time_minutes' => $timeMinutes,
            'is_billable' => $isBillable,
            'contract_id' => $contractId,
            'edited_at' => now(),
            'edited_by' => auth()->id(),
        ];

        if ($notedAt !== null) {
            $attributes['noted_at'] = $notedAt;
        }

        $bill = $this->parseBillInput($request->input('billable_minutes'));
        if ($bill !== $note->billable_minutes) {
            $request->validate(['reason' => ['required', 'string', 'max:800']]);
            // The edit and the Bill are saved in one locked write and priced once: a refusal writes
            // nothing, and no interim debit at the edited full time can reach the low-balance alert.
            app(\App\Services\BillableTimeService::class)->setNote(
                $note, $bill, $request->input('reason'), 'ui', $request->user(), $request->user()->name, $attributes,
            );
        } else {
            $note->update($attributes);
        }

        // Re-link any attachments referenced in the body to this note
        app(\App\Services\AttachmentService::class)->linkAttachmentsFromBody(
            $request->input('body'),
            'App\\Models\\TicketNote',
            $note->id,
            $ticket->id,
        );

        return redirect()->route('tickets.show', $ticket)
            ->with('success', 'Note updated.');
    }

    private function parseBillInput(mixed $input): ?int
    {
        if ($input === null || (is_string($input) && trim($input) === '')) {
            return null;
        }
        if (! is_string($input) || ! preg_match('/^(?:\d+|\d+\s*h(?:\s*\d+\s*m)?|\d+\s*m)$/i', trim($input))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['billable_minutes' => 'Enter a non-negative time, or leave Bill blank for actual time.']);
        }
        if (! preg_match('/[1-9]/', $input)) {
            return 0;
        }

        return app(\App\Services\TicketService::class)->parseTimeInput($input);
    }

    public function destroy(Request $request, Ticket $ticket, TicketNote $note)
    {
        if ($note->ticket_id !== $ticket->id) {
            abort(404);
        }

        if ($note->note_type->isSystemGenerated()) {
            return redirect()->route('tickets.show', $ticket)
                ->with('error', 'System-generated notes cannot be deleted.');
        }

        // Mark as private so portal users never see deleted notes
        $note->update([
            'is_private' => true,
            'edited_at' => now(),
            'edited_by' => auth()->id(),
        ]);

        $note->delete();

        return redirect()->route('tickets.show', $ticket)
            ->with('success', 'Note deleted.');
    }

    /**
     * Refuse time whose contract cannot be settled: a picked contract that is
     * not an active contract of this client, or no pick on a client with
     * several active contracts and no default (card I3EvQKUV §2).
     */
    private function contractRefusal(Ticket $ticket, int|string|null $contractId): ?RedirectResponse
    {
        $resolution = $this->contractResolver->forEntry($ticket, $contractId === null ? null : (int) $contractId);

        if ($resolution->status === ContractResolution::INVALID_PICK) {
            return $this->refuseContract($ticket, 'Choose one of this client\'s active contracts.');
        }

        if ($resolution->isAmbiguous()) {
            return $this->refuseContract($ticket, 'This client has several active contracts and no default. Choose the contract this time belongs to.');
        }

        return null;
    }

    private function refuseContract(Ticket $ticket, string $message): RedirectResponse
    {
        return redirect()->route('tickets.show', $ticket)
            ->withErrors(['contract_id' => $message])
            ->withInput()
            ->with('error', $message);
    }

    private function parseCcEmails(?string $input): array
    {
        if (! $input) {
            return [];
        }

        return collect(explode(',', $input))
            ->map(fn ($e) => strtolower(trim($e)))
            ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }
}
