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
use Illuminate\Support\Facades\Log;
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
        if ($note->isUnverifiedContactIntake() || $ticket->isUnverifiedContactIntake()) {
            return;
        }

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
     *
     * @param  ?int  $changedByUserId  null = an unattributable system/AI actor (psa-3s7a — see
     *                                 TicketService::changeStatus). SendTicketNotification already
     *                                 accepts a nullable actor.
     */
    public function notifyStatusChanged(Ticket $ticket, TicketStatus $oldStatus, TicketStatus $newStatus, ?int $changedByUserId): void
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
     * Notify opted-in staff about a self-service product order placed via the portal shop.
     */
    public function notifyProductOrder(\App\Models\Client $client, \App\Models\Invoice $invoice, \App\Models\Person $person, int $itemCount): void
    {
        $context = json_encode([
            'person' => $person->full_name,
            'client' => $client->name,
            'item_count' => $itemCount,
            'invoice_number' => $invoice->invoice_number,
            'amount' => (float) $invoice->total,
            'invoice_id' => $invoice->id,
        ]);

        $users = User::where('is_active', true)->whereNotNull('email')->get();

        foreach ($users as $user) {
            if ($user->wantsNotification(NotificationEventType::PortalProductOrder)) {
                SendTicketNotification::dispatch(
                    $user->id,
                    NotificationEventType::PortalProductOrder->value,
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
    /**
     * Email staff about a new voicemail, provided the call has end evidence.
     *
     * WHY THE GUARD IS HERE AND NOT AT THE CALL SITES. There are two entrances
     * to this email and they partition the traffic by recording length: the
     * controller sends immediately when transcription will NOT run, and
     * TranscriptionService sends from a `finally` block when it will. With the
     * transcription settings in use at the time of writing, the deferred path
     * carried the large majority of voicemails, so a guard placed on the
     * controller entrance alone would have left most of this traffic ungated
     * while reading like a fix. Guarding the method both entrances call covers
     * both of them in one place; it does not bind a future caller that reaches
     * SendTicketNotification some other way.
     *
     * WHAT THE GUARD TESTS, AND WHAT THAT IS WORTH. It tests `ended_at !== null`
     * and nothing else. It is NOT a liveness check and NOT proof the caller hung
     * up, and it does NOT mean a terminal webhook was processed — an earlier
     * draft of this docblock said it did, and that was false. Three writers can
     * put a value in that column and they do not establish the same fact:
     *  1. handleCallEnded() — a terminal webhook was processed; ended_at = now().
     *  2. finaliseCallTheHangupNeverClosed(), reached from the recording
     *     callback — a value DERIVED from started_at + duration for a recording
     *     that completed below the maxLength ceiling. That service treats a
     *     below-ceiling recording as evidence the CALL stopped and not merely
     *     the recording; this guard inherits that reading rather than
     *     second-guessing it.
     *  3. The FinaliseStuckCalls sweep — the same derivation, run later, behind
     *     an age floor, and only when a human runs it.
     *
     * SO WHAT IS ACTUALLY WITHHELD, stated because the guard is weaker than "a
     * hangup was observed". On an ordinary voicemail the recording block in
     * PlivoWebhookController runs BEFORE either entrance is reached, writer 2
     * has usually already stamped ended_at, and the guard passes in the same
     * request — that traffic is unchanged by this method. What it withholds is
     * the row carrying no end evidence of any kind: a recording at the ceiling,
     * a duration=-1 callback, a recording finalisation whose transaction failed
     * and was swallowed. Those are the rows that may still be connected.
     *
     * WHY DEFERRAL AND NOT SUPPRESSION. A withheld voicemail email is worse
     * than an early one if it never arrives, so withholding marks the row via
     * `voicemail_notify_deferred_at`, and EVERY writer listed above calls
     * `releaseDeferredVoicemailNotification()` once its own write has committed.
     * On a coalesced recording+terminal delivery that release happens in the
     * SAME request, microseconds later.
     *
     * THE BOUND, stated rather than implied: if none of the three writers above
     * ever records end evidence, this email is never sent, and nothing sweeps
     * for a marker left outstanding. That is deliberate — the alternative is
     * emailing about a call that may still be connected — but it is a real
     * cost, and rows predating this change are not retro-notified: they carry a
     * NULL marker, were already notified under the old behaviour, and nothing
     * here revisits them.
     *
     * WHY THE MARKER IS WRITTEN CONDITIONALLY AND THE ROW RE-READ. Neither
     * entrance holds the row lock handleCallEnded() takes, and the transcription
     * entrance runs in a detached process, so end evidence can commit between
     * the read at the top of this method and the write below. A marker written
     * after that commit would be released by nobody, every writer having already
     * run. The conditional UPDATE declines to mark a row that has since ended,
     * and the re-read sends the email from here when that is what happened.
     *
     * WHY THE RE-READ IS NOT ENOUGH ON ITS OWN. A row reading (ended_at set,
     * marker NULL) at that re-read is not distinguishable from a row whose
     * marker a release claimed and SENT an instant earlier: clearing a marker
     * destroys the evidence it ever existed. Two orderings reach this method in
     * exactly that state with the email already gone out. In one request:
     * PlivoWebhookController runs handleRecordingReady(), which releases after
     * its commit, and then calls this method on a refreshed row. Across
     * processes: a terminal webhook can claim the marker this method just wrote
     * before the fresh() below returns. So the SEND is claimed too, against
     * `voicemail_notified_at`, which a completed send leaves behind. Both
     * dispatch paths in this class take that claim; it does not bind a future
     * caller that reaches SendTicketNotification some other way.
     */
    public function notifyNewVoicemail(PhoneCall $call): void
    {
        if ($call->ended_at === null) {
            // Marks only a row that still carries no end evidence, that has
            // nothing outstanding on it already, and that has not already been
            // emailed about.
            PhoneCall::whereKey($call->getKey())
                ->whereNull('ended_at')
                ->whereNull('voicemail_notify_deferred_at')
                ->whereNull('voicemail_notified_at')
                ->update(['voicemail_notify_deferred_at' => now()]);

            $call = $call->fresh() ?? $call;

            if ($call->ended_at === null) {
                Log::info('[Voicemail] Notification deferred pending end evidence', [
                    'call_id' => $call->id,
                ]);

                return;
            }
        }

        // End evidence is present, so the email can go out from here - but only
        // if nobody has sent it yet. That question is decided by the claim below
        // and read off the record of the send, not off the marker, which by now
        // may have been cleared by whoever sent it.
        $this->sendVoicemailNotificationOnce($call, requireOutstandingDeferral: false);
    }

    /**
     * Send a voicemail email that was withheld for want of end evidence.
     *
     * Called by every writer of `ended_at`, once that write has COMMITTED. It
     * must not be called from inside an open transaction: the job it queues is
     * not rolled back with one, so a dispatch made before the commit can email
     * staff about end evidence the database then discards — and a queue failure
     * at that point would take call finalisation and the prepay debit down with
     * it. All three callers sit outside their transaction for that reason.
     *
     * CLAIMING, not merely clearing. One conditional UPDATE both clears the
     * marker and stamps `voicemail_notified_at`, and only the caller whose
     * UPDATE changed a row dispatches. A redelivered terminal callback, or a
     * second writer reaching the same row, loses that claim and sends nothing.
     * The stamp is the half a later notifyNewVoicemail() reads: a cleared marker
     * on its own would look to that method exactly like a call nothing was ever
     * withheld for, and it would send a second copy.
     *
     * It sends only where a deferral is outstanding, which is what keeps it from
     * emailing about every ended call: a row nothing withheld an email for is
     * left alone here.
     *
     * The cost of claiming before dispatching is the reverse failure: if the
     * dispatch throws, the claim is already taken and the email is not retried.
     * That is the chosen direction for an outbound email to a client's
     * technicians — at most once rather than at least once — and the throw is
     * logged here rather than left to fail the webhook that triggered it, which
     * would otherwise re-stamp ended_at and re-run the debit on redelivery.
     */
    public function releaseDeferredVoicemailNotification(PhoneCall $call): void
    {
        if ($call->ended_at === null) {
            return;
        }

        $this->sendVoicemailNotificationOnce($call, requireOutstandingDeferral: true);
    }

    /**
     * Queue the voicemail email, at most once for a given call.
     *
     * The claim is a single conditional UPDATE against `voicemail_notified_at`,
     * so two processes racing on one row cannot both take it, and it clears any
     * outstanding deferral marker in the same statement - a row is never left
     * claimed and still marked.
     *
     * $requireOutstandingDeferral separates the two entrances. A release sends
     * only what was withheld; notifyNewVoicemail() sends whether or not anything
     * was withheld, and leans on the claim alone.
     *
     * A throw from the dispatch is logged and swallowed for both callers,
     * because by then the claim is taken: letting it out would fail the webhook
     * that triggered it - re-stamping ended_at and re-running the debit on
     * redelivery - without making the email retryable.
     */
    private function sendVoicemailNotificationOnce(PhoneCall $call, bool $requireOutstandingDeferral): void
    {
        $query = PhoneCall::whereKey($call->getKey())
            ->whereNull('voicemail_notified_at');

        if ($requireOutstandingDeferral) {
            $query->whereNotNull('voicemail_notify_deferred_at');
        }

        $claimedAt = now();

        $claimed = $query->update([
            'voicemail_notify_deferred_at' => null,
            'voicemail_notified_at' => $claimedAt,
        ]) === 1;

        if (! $claimed) {
            return;
        }

        $call->voicemail_notify_deferred_at = null;
        $call->voicemail_notified_at = $claimedAt;

        try {
            $this->dispatchVoicemailNotification($call);
        } catch (\Throwable $e) {
            Log::error('[Voicemail] Notification could not be queued and will not be retried', [
                'call_id' => $call->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function dispatchVoicemailNotification(PhoneCall $call): void
    {
        $call->loadMissing(['person', 'client']);

        $callerDisplay = $call->person?->full_name ?? PhoneNumber::format($call->from_number);
        $clientName = $call->client?->name ?? $call->halo_client_name;
        $context = json_encode([
            'caller' => $callerDisplay,
            'client' => $clientName,
            'duration' => $call->recording_duration,
            'call_id' => $call->id,
            'transcript_unusable' => $call->transcription_status === \App\Enums\TranscriptionStatus::Unusable,
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
        if ($ticket->isUnverifiedContactIntake()) {
            return;
        }

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

    /**
     * psa-3s7a: nullable actor. Both sides resolve through the same AiActorResolver, so when the
     * configured AI actor is stale BOTH are null and an AI-driven status change stays correctly
     * suppressed (null === null) — the notification behaviour does not change just because the
     * attribution degraded. A human actor is always a real id and never matches.
     */
    private function isTriageUser(?int $userId): bool
    {
        return $userId === TriageConfig::systemUserId();
    }
}
