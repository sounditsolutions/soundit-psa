<?php

namespace App\Services;

use App\Enums\EmailDirection;
use App\Enums\NoteType;
use App\Enums\NotificationEventType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Jobs\SendTicketNotification;
use App\Models\Contract;
use App\Models\Email;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Support\TriageConfig;
use Illuminate\Support\Str;

class NotificationService
{
    /**
     * Notify all opted-in users that a new ticket was created.
     */
    public function notifyTicketCreated(Ticket $ticket): void
    {
        $ticket->loadMissing('client');
        $context = ($ticket->client?->name ?? 'Unknown client').' — '.($ticket->source?->label() ?? 'Manual');

        $users = User::where('is_active', true)->whereNotNull('email')->get();

        foreach ($users as $user) {
            if ($user->id === $ticket->created_by) {
                continue;
            }

            if ($user->wantsNotification(NotificationEventType::TicketCreated)) {
                SendTicketNotification::dispatch(
                    $user->id,
                    NotificationEventType::TicketCreated->value,
                    $ticket->id,
                    $ticket->created_by,
                    $context,
                );
            }
        }
    }

    /**
     * Notify the ticket assignee that a note was added.
     * Routes NoteType::PhoneCall to TicketCallLogged event.
     */
    public function notifyNoteAdded(Ticket $ticket, TicketNote $note, int $authorUserId): void
    {
        if (! $ticket->assignee_id || $ticket->assignee_id === $authorUserId) {
            return;
        }

        if ($this->isTriageUser($authorUserId)) {
            return;
        }

        $event = $note->note_type === NoteType::PhoneCall
            ? NotificationEventType::TicketCallLogged
            : NotificationEventType::TicketNoteAdded;

        SendTicketNotification::dispatch(
            $ticket->assignee_id,
            $event->value,
            $ticket->id,
            $authorUserId,
            Str::limit($note->body, 200),
        );

        // Notify portal contact when a staff member adds a public reply
        if (! $note->is_private && $note->note_type === NoteType::Reply && $ticket->contact_id) {
            $this->notifyPortalContact($ticket, 'staff_reply', Str::limit($note->body, 500));
        }
    }

    /**
     * Notify the ticket assignee that an inbound email was linked (client reply).
     * Only fires for inbound emails — outbound replies from the PSA are skipped.
     */
    public function notifyEmailAdded(Ticket $ticket, Email $email): void
    {
        if (! $ticket->assignee_id) {
            return;
        }

        if ($email->direction !== EmailDirection::Inbound) {
            return;
        }

        SendTicketNotification::dispatch(
            $ticket->assignee_id,
            NotificationEventType::TicketEmailAdded->value,
            $ticket->id,
            null,
            Str::limit(($email->from_name ?? $email->from_address).': '.$email->body_preview, 200),
        );
    }

    /**
     * Notify a user that a ticket was assigned to them.
     */
    public function notifyTicketAssigned(Ticket $ticket, int $newAssigneeId, int $changedByUserId): void
    {
        if ($newAssigneeId === $changedByUserId) {
            return;
        }

        SendTicketNotification::dispatch(
            $newAssigneeId,
            NotificationEventType::TicketAssigned->value,
            $ticket->id,
            $changedByUserId,
            null,
        );
    }

    /**
     * Notify all opted-in users when an inbound email can't be resolved.
     */
    public function notifyUnresolvedEmail(Email $email): void
    {
        $context = "From: {$email->from_address} — {$email->subject}";

        $users = User::where('is_active', true)->whereNotNull('email')->get();

        foreach ($users as $user) {
            if ($user->wantsNotification(NotificationEventType::UnresolvedInboundEmail)) {
                SendTicketNotification::dispatch(
                    $user->id,
                    NotificationEventType::UnresolvedInboundEmail->value,
                    null,
                    null,
                    $context,
                );
            }
        }
    }

    /**
     * Notify the ticket assignee that priority changed.
     */
    public function notifyPriorityChanged(Ticket $ticket, TicketPriority $oldPriority, TicketPriority $newPriority, int $changedByUserId): void
    {
        if (! $ticket->assignee_id || $ticket->assignee_id === $changedByUserId) {
            return;
        }

        if ($this->isTriageUser($changedByUserId)) {
            return;
        }

        SendTicketNotification::dispatch(
            $ticket->assignee_id,
            NotificationEventType::TicketPriorityChanged->value,
            $ticket->id,
            $changedByUserId,
            "{$oldPriority->label()} → {$newPriority->label()}",
        );
    }

    /**
     * Notify the ticket assignee that status changed.
     */
    public function notifyStatusChanged(Ticket $ticket, TicketStatus $oldStatus, TicketStatus $newStatus, int $changedByUserId): void
    {
        // Staff notification
        if ($ticket->assignee_id && $ticket->assignee_id !== $changedByUserId && ! $this->isTriageUser($changedByUserId)) {
            SendTicketNotification::dispatch(
                $ticket->assignee_id,
                NotificationEventType::TicketStatusChanged->value,
                $ticket->id,
                $changedByUserId,
                "{$oldStatus->label()} → {$newStatus->label()}",
            );
        }

        // Portal contact notifications for key status changes
        if ($ticket->contact_id) {
            if ($newStatus === TicketStatus::Resolved) {
                $this->notifyPortalContact($ticket, 'status_resolved');
            } elseif ($newStatus === TicketStatus::PendingClient) {
                $this->notifyPortalContact($ticket, 'status_pending_client');
            }
        }
    }

    /**
     * Notify the ticket assignee that a client replied via the portal.
     */
    public function notifyPortalReply(Ticket $ticket, TicketNote $note, \App\Models\Person $person): void
    {
        if (! $ticket->assignee_id) {
            return;
        }

        SendTicketNotification::dispatch(
            $ticket->assignee_id,
            NotificationEventType::TicketPortalReply->value,
            $ticket->id,
            null,
            \Illuminate\Support\Str::limit($person->full_name.' (portal): '.$note->body, 200),
        );
    }

    /**
     * Notify all opted-in users when a client purchases prepaid time via the portal.
     */
    public function notifyPrepayPurchase(\App\Models\Contract $contract, \App\Models\Invoice $invoice, \App\Models\Person $person, float $hours): void
    {
        $context = json_encode([
            'person' => $person->full_name,
            'hours' => $hours,
            'contract' => $contract->name,
            'invoice_number' => $invoice->invoice_number,
            'amount' => (float) $invoice->total,
            'invoice_id' => $invoice->id,
        ]);

        $users = User::where('is_active', true)->whereNotNull('email')->get();

        foreach ($users as $user) {
            if ($user->wantsNotification(NotificationEventType::PortalPrepayPurchase)) {
                SendTicketNotification::dispatch(
                    $user->id,
                    NotificationEventType::PortalPrepayPurchase->value,
                    null,
                    null,
                    $context,
                );
            }
        }
    }

    /**
     * Notify company-wide portal users and opted-in staff about low prepay balance.
     */
    public function notifyPrepayLowBalance(Contract $contract): void
    {
        $context = json_encode([
            'contract' => $contract->name,
            'balance' => (float) $contract->prepay_balance,
            'threshold' => (float) $contract->prepay_alert_threshold,
            'client' => $contract->client?->name,
            'contract_id' => $contract->id,
        ]);

        // Notify company-wide portal users
        $portalUsers = \App\Models\Person::where('client_id', $contract->client_id)
            ->where('portal_enabled', true)
            ->where('company_wide_access', true)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->get();

        foreach ($portalUsers as $person) {
            \App\Jobs\SendPrepayAlertEmail::dispatch(
                $person->email,
                $person->full_name,
                'low_balance',
                $context,
            );
        }

        // Notify opted-in staff
        $users = User::where('is_active', true)->whereNotNull('email')->get();

        foreach ($users as $user) {
            if ($user->wantsNotification(NotificationEventType::PrepayLowBalance)) {
                SendTicketNotification::dispatch(
                    $user->id,
                    NotificationEventType::PrepayLowBalance->value,
                    null,
                    null,
                    $context,
                );
            }
        }
    }

    /**
     * Notify company-wide portal users and opted-in staff about auto top-up invoice.
     */
    public function notifyPrepayAutoTopUp(Contract $contract, \App\Models\Invoice $invoice, float $hours): void
    {
        $context = json_encode([
            'contract' => $contract->name,
            'balance' => (float) $contract->prepay_balance,
            'hours' => $hours,
            'client' => $contract->client?->name,
            'contract_id' => $contract->id,
            'invoice_number' => $invoice->invoice_number,
            'amount' => (float) $invoice->total,
            'invoice_id' => $invoice->id,
        ]);

        // Notify company-wide portal users
        $portalUsers = \App\Models\Person::where('client_id', $contract->client_id)
            ->where('portal_enabled', true)
            ->where('company_wide_access', true)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->get();

        foreach ($portalUsers as $person) {
            \App\Jobs\SendPrepayAlertEmail::dispatch(
                $person->email,
                $person->full_name,
                'auto_topup',
                $context,
            );
        }

        // Notify opted-in staff
        $users = User::where('is_active', true)->whereNotNull('email')->get();

        foreach ($users as $user) {
            if ($user->wantsNotification(NotificationEventType::PrepayAutoTopUp)) {
                SendTicketNotification::dispatch(
                    $user->id,
                    NotificationEventType::PrepayAutoTopUp->value,
                    null,
                    null,
                    $context,
                );
            }
        }
    }

    /**
     * Notify all opted-in users when a voicemail is left.
     */
    public function notifyNewVoicemail(PhoneCall $call): void
    {
        $call->loadMissing(['person', 'client']);

        $callerDisplay = $call->person?->full_name ?? PhoneNumber::format($call->from_number);
        $clientName = $call->client?->name ?? $call->halo_client_name;
        $context = json_encode([
            'caller' => $callerDisplay,
            'client' => $clientName,
            'duration' => $call->recording_duration,
            'call_id' => $call->id,
        ]);

        $users = User::where('is_active', true)->whereNotNull('email')->get();

        foreach ($users as $user) {
            if ($user->wantsNotification(NotificationEventType::NewVoicemail)) {
                SendTicketNotification::dispatch(
                    $user->id,
                    NotificationEventType::NewVoicemail->value,
                    null,
                    null,
                    $context,
                );
            }
        }
    }

    /**
     * Notify the ticket's portal-enabled contact about a ticket event.
     */
    private function notifyPortalContact(Ticket $ticket, string $eventType, ?string $context = null): void
    {
        if (! $ticket->contact_id) {
            return;
        }

        $contact = \App\Models\Person::find($ticket->contact_id);
        if (! $contact || ! $contact->portal_enabled || ! $contact->email) {
            return;
        }

        \App\Jobs\SendPortalNotification::dispatch(
            $contact->id,
            $ticket->id,
            $eventType,
            $context,
        );
    }

    public function notifyInvoiceGenerationFailed(\App\Models\RecurringInvoiceProfile $profile, string $error): void
    {
        $context = json_encode([
            'profile' => $profile->name,
            'contract' => $profile->contract?->name,
            'client' => $profile->contract?->client?->name,
            'error' => $error,
        ]);

        $users = User::where('is_active', true)->whereNotNull('email')->get();

        foreach ($users as $user) {
            if ($user->wantsNotification(NotificationEventType::InvoiceGenerationFailed)) {
                SendTicketNotification::dispatch(
                    $user->id,
                    NotificationEventType::InvoiceGenerationFailed->value,
                    null,
                    null,
                    $context,
                );
            }
        }
    }

    public function notifyInvoicePushFailed(\App\Models\Invoice $invoice, string $backend, string $error): void
    {
        $context = json_encode([
            'invoice_number' => $invoice->invoice_number,
            'client' => $invoice->client?->name,
            'backend' => $backend,
            'error' => $error,
        ]);

        $users = User::where('is_active', true)->whereNotNull('email')->get();

        foreach ($users as $user) {
            if ($user->wantsNotification(NotificationEventType::InvoicePushFailed)) {
                SendTicketNotification::dispatch(
                    $user->id,
                    NotificationEventType::InvoicePushFailed->value,
                    null,
                    null,
                    $context,
                );
            }
        }
    }

    private function isTriageUser(int $userId): bool
    {
        return $userId === TriageConfig::systemUserId();
    }
}
