<?php

namespace App\Http\Controllers\Web;

use App\Enums\NoteType;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\EmailService;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TicketNoteController extends Controller
{
    public function __construct(
        private readonly TicketService $ticketService,
        private readonly EmailService $emailService,
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
        $flashMessage = ucfirst($type->label()) . ' added.';

        if ($note->note_type === NoteType::Reply && !$note->is_private) {
            $toEmail = trim($request->input('to_email', '')) ?: $ticket->contact?->email;
            $ccEmails = $this->parseCcEmails($request->input('cc_emails'));

            if (!$toEmail) {
                $flashType = 'warning';
                $flashMessage = 'Reply added, but no recipient email provided — the client was not notified.';
            } else {
                try {
                    $email = $this->emailService->sendTicketReplyNote($ticket, $note, $toEmail, $ccEmails);

                    if ($email) {
                        $note->update(['email_id' => $email->id]);
                        $recipients = $toEmail;
                        if ($ccEmails) {
                            $recipients .= ' (cc: ' . implode(', ', $ccEmails) . ')';
                        }
                        $flashMessage = 'Reply added and emailed to ' . $recipients . '.';
                    } else {
                        $flashType = 'warning';
                        $flashMessage = 'Reply added, but email is not configured — the client was not notified.';
                    }
                } catch (\Throwable $e) {
                    Log::warning('[TicketNote] Failed to send reply email', [
                        'ticket' => $ticket->id,
                        'note'   => $note->id,
                        'error'  => $e->getMessage(),
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
                try {
                    $this->ticketService->changeStatus(
                        $ticket,
                        $newStatus,
                        auth()->id(),
                        resolution: $request->input('resolution'),
                    );
                    $flashMessage .= " Status changed to {$newStatus->label()}.";
                } catch (\InvalidArgumentException $e) {
                    $flashType = 'warning';
                    $flashMessage .= ' Status change failed: ' . $e->getMessage();
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
        ]);

        $timeMinutes = $this->ticketService->parseTimeInput($request->input('time'));

        if ($timeMinutes !== null && $timeMinutes > 1440) {
            return redirect()->route('tickets.show', $ticket)
                ->with('error', 'Time logged cannot exceed 24 hours per entry.');
        }

        $isBillable = $timeMinutes ? $request->boolean('is_billable') : null;
        $contractId = $timeMinutes ? ($request->input('contract_id') ?: null) : null;

        $note->update([
            'body' => $request->input('body'),
            'body_html' => \App\Helpers\MarkdownRenderer::render($request->input('body')),
            'note_type' => $request->input('note_type'),
            'is_private' => $request->boolean('is_private'),
            'time_minutes' => $timeMinutes,
            'is_billable' => $isBillable,
            'contract_id' => $contractId,
            'edited_at' => now(),
            'edited_by' => auth()->id(),
        ]);

        // Re-link any attachments referenced in the body to this note
        app(\App\Services\AttachmentService::class)->linkAttachmentsFromBody($note, $request->input('body'));

        return redirect()->route('tickets.show', $ticket)
            ->with('success', 'Note updated.');
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

    private function parseCcEmails(?string $input): array
    {
        if (!$input) {
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
