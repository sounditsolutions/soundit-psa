<?php

namespace App\Jobs;

use App\Enums\NotificationEventType;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EmailService;
use App\Services\Graph\GraphClientException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SendTicketNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 30;

    public array $backoff = [10, 60];

    public function __construct(
        private readonly int $recipientUserId,
        private readonly string $eventType,
        private readonly ?int $ticketId,
        private readonly ?int $actorUserId,
        private readonly ?string $extraContext,
    ) {}

    public function handle(EmailService $emailService): void
    {
        $recipient = User::find($this->recipientUserId);
        if (! $recipient || ! $recipient->email) {
            return;
        }

        $event = NotificationEventType::from($this->eventType);

        if (! $recipient->wantsNotification($event)) {
            return;
        }

        $mailbox = Setting::getValue('graph_mailbox');
        if (! $mailbox) {
            Log::info('[Notification] Email not configured, skipping', [
                'event' => $this->eventType,
                'recipient' => $this->recipientUserId,
            ]);

            return;
        }

        $ticket = $this->ticketId ? Ticket::with(['client', 'assignee'])->find($this->ticketId) : null;
        $actor = $this->actorUserId ? User::find($this->actorUserId) : null;

        $subject = $this->buildSubject($event, $ticket);
        $body = $this->buildBody($event, $ticket, $actor);

        try {
            $emailService->sendNew(
                $recipient->email,
                $subject,
                $body,
                $recipient->name,
            );

            Log::info('[Notification] Sent', [
                'event' => $this->eventType,
                'ticket' => $this->ticketId,
                'recipient' => $this->recipientUserId,
            ]);
        } catch (GraphClientException $e) {
            Log::error('[Notification] Failed to send', [
                'event' => $this->eventType,
                'ticket' => $this->ticketId,
                'recipient' => $this->recipientUserId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function buildSubject(NotificationEventType $event, ?Ticket $ticket): string
    {
        if ($event === NotificationEventType::UnresolvedInboundEmail) {
            return 'Unresolved inbound email — '.Str::limit($this->extraContext ?? 'unknown sender', 80);
        }

        if ($event === NotificationEventType::PortalPrepayPurchase) {
            $ctx = json_decode($this->extraContext ?? '{}', true);
            $hours = $ctx['hours'] ?? '?';
            $invoiceNumber = $ctx['invoice_number'] ?? '?';

            return "Portal purchase: {$hours}h prepaid time — Invoice #{$invoiceNumber}";
        }

        if ($event === NotificationEventType::PortalProductOrder) {
            $ctx = json_decode($this->extraContext ?? '{}', true);
            $client = $ctx['client'] ?? 'A client';
            $invoiceNumber = $ctx['invoice_number'] ?? '?';

            return "Portal order: {$client} — Invoice #{$invoiceNumber}";
        }

        if ($event === NotificationEventType::NewVoicemail) {
            $ctx = json_decode($this->extraContext ?? '{}', true);
            $caller = $ctx['caller'] ?? 'Unknown';

            return "New voicemail from {$caller}";
        }

        if ($event === NotificationEventType::PrepayLowBalance) {
            $ctx = json_decode($this->extraContext ?? '{}', true);
            $client = $ctx['client'] ?? 'Unknown';
            $balance = $ctx['balance'] ?? '?';

            return "Low prepay balance: {$client} — {$balance}h remaining";
        }

        if ($event === NotificationEventType::PrepayAutoTopUp) {
            $ctx = json_decode($this->extraContext ?? '{}', true);
            $client = $ctx['client'] ?? 'Unknown';
            $invoiceNumber = $ctx['invoice_number'] ?? '?';

            return "Auto top-up invoice generated: {$client} — Invoice #{$invoiceNumber}";
        }

        $ticketRef = $ticket ? "[{$ticket->display_id}]" : '';
        $ticketSubject = $ticket ? Str::limit($ticket->subject, 60) : '';

        return match ($event) {
            NotificationEventType::TicketCreated => "{$ticketRef} New ticket: {$ticketSubject}",
            NotificationEventType::TicketNoteAdded => "{$ticketRef} New note on: {$ticketSubject}",
            NotificationEventType::TicketCallLogged => "{$ticketRef} Phone call logged on: {$ticketSubject}",
            NotificationEventType::TicketEmailAdded => "{$ticketRef} Client reply on: {$ticketSubject}",
            NotificationEventType::TicketAssigned => "{$ticketRef} Ticket assigned to you: {$ticketSubject}",
            NotificationEventType::TicketPriorityChanged => "{$ticketRef} Priority changed on: {$ticketSubject}",
            NotificationEventType::TicketStatusChanged => "{$ticketRef} Status changed on: {$ticketSubject}",
            default => "{$ticketRef} Update on: {$ticketSubject}",
        };
    }

    private function buildBody(NotificationEventType $event, ?Ticket $ticket, ?User $actor): string
    {
        $actorName = $actor?->name ?? 'System';
        $lines = [];

        if ($event === NotificationEventType::UnresolvedInboundEmail) {
            $lines[] = 'An inbound email could not be matched to an existing ticket or contact.';
            $lines[] = '';
            if ($this->extraContext) {
                $lines[] = $this->extraContext;
                $lines[] = '';
            }
            $lines[] = 'View emails:';
            $lines[] = route('emails.index');

            return implode("\n", $lines);
        }

        if ($event === NotificationEventType::PortalPrepayPurchase) {
            $ctx = json_decode($this->extraContext ?? '{}', true);
            $lines[] = ($ctx['person'] ?? 'A client').' purchased prepaid time via the portal.';
            $lines[] = '';
            $lines[] = 'Contract: '.($ctx['contract'] ?? 'Unknown');
            $lines[] = 'Hours: '.($ctx['hours'] ?? '?').'h';
            $lines[] = 'Amount: $'.number_format($ctx['amount'] ?? 0, 2);
            $lines[] = 'Invoice: #'.($ctx['invoice_number'] ?? '?');
            $lines[] = '';
            if ($invoiceId = $ctx['invoice_id'] ?? null) {
                $lines[] = 'View invoice:';
                $lines[] = route('invoices.show', $invoiceId);
            }

            return implode("\n", $lines);
        }

        if ($event === NotificationEventType::PortalProductOrder) {
            $ctx = json_decode($this->extraContext ?? '{}', true);
            $lines[] = ($ctx['person'] ?? 'A client').' placed a product order via the portal shop.';
            $lines[] = '';
            $lines[] = 'Client: '.($ctx['client'] ?? 'Unknown');
            $lines[] = 'Items: '.($ctx['item_count'] ?? '?');
            $lines[] = 'Amount: $'.number_format($ctx['amount'] ?? 0, 2);
            $lines[] = 'Invoice: #'.($ctx['invoice_number'] ?? '?');
            $lines[] = '';
            if ($invoiceId = $ctx['invoice_id'] ?? null) {
                $lines[] = 'View invoice:';
                $lines[] = route('invoices.show', $invoiceId);
            }

            return implode("\n", $lines);
        }

        if ($event === NotificationEventType::NewVoicemail) {
            $ctx = json_decode($this->extraContext ?? '{}', true);
            $lines[] = 'A voicemail was left by '.($ctx['caller'] ?? 'an unknown caller').'.';
            $lines[] = '';
            if ($ctx['client'] ?? null) {
                $lines[] = 'Client: '.$ctx['client'];
            }
            if ($ctx['duration'] ?? null) {
                $duration = (int) $ctx['duration'];
                $format = $duration >= 3600 ? 'H:i:s' : 'i:s';
                $lines[] = 'Duration: '.gmdate($format, $duration);
            }

            if ($callId = $ctx['call_id'] ?? null) {
                $call = \App\Models\PhoneCall::find($callId);
                if ($call?->call_summary) {
                    $lines[] = '';
                    $lines[] = 'AI Summary:';
                    $lines[] = trim($call->call_summary);
                }
                if ($call?->transcription) {
                    $lines[] = '';
                    $lines[] = 'Transcript:';
                    $lines[] = trim($call->transcription);
                }
                $lines[] = '';
                $lines[] = 'View call:';
                $lines[] = route('calls.show', $callId);
            }

            return implode("\n", $lines);
        }

        if ($event === NotificationEventType::PrepayLowBalance) {
            $ctx = json_decode($this->extraContext ?? '{}', true);
            $lines[] = ($ctx['client'] ?? 'A client').'\'s prepay balance has dropped below their alert threshold.';
            $lines[] = '';
            $lines[] = 'Contract: '.($ctx['contract'] ?? 'Unknown');
            $lines[] = 'Current Balance: '.($ctx['balance'] ?? '?').'h';
            $lines[] = 'Alert Threshold: '.($ctx['threshold'] ?? '?').'h';
            $lines[] = '';
            if ($contractId = $ctx['contract_id'] ?? null) {
                $lines[] = 'View contract:';
                $lines[] = route('contracts.show', $contractId);
            }

            return implode("\n", $lines);
        }

        if ($event === NotificationEventType::PrepayAutoTopUp) {
            $ctx = json_decode($this->extraContext ?? '{}', true);
            $lines[] = 'An auto top-up invoice was generated for '.($ctx['client'] ?? 'a client').'.';
            $lines[] = '';
            $lines[] = 'Contract: '.($ctx['contract'] ?? 'Unknown');
            $lines[] = 'Hours: '.($ctx['hours'] ?? '?').'h';
            $lines[] = 'Amount: $'.number_format($ctx['amount'] ?? 0, 2);
            $lines[] = 'Invoice: #'.($ctx['invoice_number'] ?? '?');
            $lines[] = '';
            if ($invoiceId = $ctx['invoice_id'] ?? null) {
                $lines[] = 'View invoice:';
                $lines[] = route('invoices.show', $invoiceId);
            }

            return implode("\n", $lines);
        }

        $lines[] = match ($event) {
            NotificationEventType::TicketCreated => $actor ? "{$actorName} created a new ticket." : 'A new ticket has been created.',
            NotificationEventType::TicketNoteAdded => "{$actorName} added a note.",
            NotificationEventType::TicketCallLogged => "{$actorName} logged a phone call.",
            NotificationEventType::TicketEmailAdded => 'A client replied by email.',
            NotificationEventType::TicketAssigned => "{$actorName} assigned this ticket to you.",
            NotificationEventType::TicketPriorityChanged => "{$actorName} changed the priority.".($this->extraContext ? " {$this->extraContext}" : ''),
            NotificationEventType::TicketStatusChanged => "{$actorName} changed the status.".($this->extraContext ? " {$this->extraContext}" : ''),
            default => "{$actorName} updated this ticket.",
        };

        $lines[] = '';

        if ($ticket) {
            $lines[] = "Ticket: {$ticket->display_id} — {$ticket->subject}";
            if ($ticket->client) {
                $lines[] = "Client: {$ticket->client->name}";
            }
            $lines[] = "Priority: {$ticket->priority->label()}";

            if ($this->extraContext && ! in_array($event, [NotificationEventType::TicketPriorityChanged, NotificationEventType::TicketStatusChanged])) {
                $lines[] = '';
                $lines[] = Str::limit($this->extraContext, 300);
            }

            $lines[] = '';
            $lines[] = 'View ticket:';
            $lines[] = route('tickets.show', $ticket);
        }

        return implode("\n", $lines);
    }
}
