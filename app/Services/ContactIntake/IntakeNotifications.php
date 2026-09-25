<?php

namespace App\Services\ContactIntake;

use App\Enums\NotificationEventType;
use App\Jobs\SendTicketNotification;
use App\Models\ContactSubmission;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ContactIntakeConfig;
use Illuminate\Support\Facades\DB;

/** Durable internal-only outbox; no submitted text is passed to the notifier. */
final class IntakeNotifications
{
    public static function record(ContactSubmission $submission, string $event): void
    {
        DB::table('contact_intake_notifications')->updateOrInsert(
            ['contact_submission_id' => $submission->id, 'event' => $event],
            ['updated_at' => now(), 'created_at' => now()],
        );
    }

    public function drain(): int
    {
        if (! ContactIntakeConfig::enabled()) {
            return 0;
        }
        $count = 0;
        foreach (DB::table('contact_intake_notifications')->whereNull('sent_at')->orderBy('id')->limit(100)->get() as $item) {
            $row = ContactSubmission::findOrFail($item->contact_submission_id);
            $ticket = $row->ticket_id ? Ticket::find($row->ticket_id) : null;
            $recipient = $ticket?->assignee_id ?? ContactIntakeConfig::ownerId();
            if (! $recipient || ! User::whereKey($recipient)->where('is_active', true)->exists()) {
                continue; // Retain the row; owner configuration must never discard an alert.
            }
            // Called by the post-commit drain, never in the CRM transaction. A crash after
            // enqueue but before stamping may repeat an INTERNAL alert, not a CRM write.
            SendTicketNotification::dispatch($recipient, NotificationEventType::TicketNoteAdded->value,
                $ticket?->id, null, 'Contact intake '.$item->event.'. Staff review: '.route('contact-intake.show', $row->id));
            DB::table('contact_intake_notifications')->where('id', $item->id)->update(['sent_at' => now()]);
            $count++;
        }

        return $count;
    }
}
