<?php

namespace App\Services\ContactIntake;

use App\Console\Commands\ContactIntakeDrain;
use App\Models\ContactSubmission;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EmailService;
use App\Support\ContactIntakeConfig;
use App\Support\PortalConfig;
use Illuminate\Support\Facades\DB;

/** Durable internal-only outbox; no submitted text is passed to the notifier. */
final class IntakeNotifications
{
    public function __construct(private readonly EmailService $email) {}

    public static function record(ContactSubmission $submission, string $event): void
    {
        // Clearing sent_at re-arms an already-delivered event, so a repeat (re-quarantine, the
        // processing_failed that quarantines, a second conflict) is alerted again (r2 diff:3).
        DB::table('contact_intake_notifications')->updateOrInsert(
            ['contact_submission_id' => $submission->id, 'event' => $event],
            ['sent_at' => null, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function drain(): int
    {
        if (! ContactIntakeConfig::enabled()) {
            return 0;
        }
        $count = 0;
        // lazyById walks EVERY unsent row in id-ordered pages. A fixed first-100 window let
        // 100 undeliverable alerts (no active recipient) hide every later one forever (r1 diff:6).
        foreach (DB::table('contact_intake_notifications')->whereNull('sent_at')->lazyById(100) as $item) {
            $row = ContactSubmission::findOrFail($item->contact_submission_id);
            $ticket = $row->ticket_id ? Ticket::find($row->ticket_id) : null;
            $recipient = $ticket?->assignee_id ?? ContactIntakeConfig::ownerId();
            $user = $recipient ? User::whereKey($recipient)->where('is_active', true)->first() : null;
            if (! $user || ! $user->email) {
                continue; // Retain the row; owner configuration must never discard an alert.
            }
            $message = $this->message($row, $item->event);
            if ($message === null) {
                continue; // An event with no approved wording is retained, never sent as raw text.
            }
            // Sent here and stamped only after delivery (context:5). The generic ticket notifier
            // drops extra context when there is no ticket and skips users who have opted out,
            // yet the outbox row was stamped at enqueue. Called by the post-commit drain, never
            // in the CRM transaction. A crash after sending but before stamping may repeat an
            // INTERNAL alert, not a CRM write.
            try {
                $this->email->sendNew($user->email, $message['subject'], $message['body'], $user->name);
            } catch (\Throwable) {
                continue; // Never log exception text here; the row is retained for the next drain.
            }
            // Stamp only the recording that was read: a repeat recorded after the read, with a later
            // updated_at, stays unsent. updated_at is second-precision, so a repeat recorded in the
            // same second as the read is still stamped with this send.
            DB::table('contact_intake_notifications')->where('id', $item->id)
                ->where('updated_at', $item->updated_at)->update(['sent_at' => now()]);
            $count++;
        }

        return $count;
    }

    /** Quarantine reason lines ("Why:"), keyed by contact_submissions.exception_reason. */
    private static function reasons(): array
    {
        return [
            'multiple_email_contacts' => 'two or more contacts share this email address.',
            'inactive_identity' => 'the matching contact or client is inactive.',
            'conflicting_phone' => 'the phone number belongs to a different contact.',
            'conflicting_ticket_client' => 'this contact has open tickets under a different client.',
            'phone_only' => 'only the phone number matched an existing contact.',
            'domain_only' => 'only the email domain matched an existing client.',
            'company_only' => 'only the company name matched an existing client.',
            'staff_hold' => 'a staff member put it on hold.',
            'processing_failed' => 'it failed to process '.ContactIntakeDrain::MAX_ATTEMPTS.' times.',
        ];
    }

    /**
     * The staff alert for one event (card JyBDeHPz, approved lines). Built only from the
     * ticket number, the stored reason code, the company setting and the review link:
     * never from the encrypted visitor payload.
     *
     * @return array{subject: string, body: string}|null
     */
    private function message(ContactSubmission $row, string $event): ?array
    {
        $company = PortalConfig::companyName();
        $review = 'Review it: '.route('contact-intake.show', $row->id);
        $close = "This email does not include the visitor's message.";
        $paragraphs = match ($event) {
            'processed' => $row->ticket_id === null ? null : $this->processedParagraphs($row, $company),
            'quarantined' => array_values(array_filter([
                "A visitor used the {$company} website contact form. Their inquiry could not be matched to a client automatically, so no ticket has been created yet.",
                isset(self::reasons()[$row->exception_reason ?? '']) ? 'Why: '.self::reasons()[$row->exception_reason] : null,
                'Next step: open the review page and resolve it to the right client, approve it as a new prospect, or leave it on hold.',
            ])),
            'conflict' => [
                "The {$company} website resent a contact form inquiry, but the new copy differs from the one already received. The first copy is kept and nothing was changed.",
                'Next step: open the review page and check the inquiry and its ticket. If they look right, no action is needed.',
            ],
            'processing_failed' => [
                "A {$company} website contact form inquiry failed to process ".ContactIntakeDrain::MAX_ATTEMPTS.' times, so it was moved to the review queue.',
                'Next step: open the review page and choose "Replay" to try again. If it fails again, tell your PSA administrator.',
            ],
            default => null,
        };
        if ($paragraphs === null) {
            return null;
        }
        $subject = match ($event) {
            'processed' => 'Web form inquiry to verify: ticket #'.$row->ticket_id,
            'quarantined' => 'Web form inquiry needs a client match',
            'conflict' => 'Web form inquiry was resent with different content',
            'processing_failed' => 'Web form inquiry could not be processed',
        };

        return ['subject' => $subject, 'body' => implode("\n\n", [...$paragraphs, $review, $close])];
    }

    /**
     * SubmissionProcessor writes ticket_watermark only when the row CREATED its ticket, so a
     * null watermark is a follow-up note added to an existing ticket (approved fix 1).
     */
    private function processedParagraphs(ContactSubmission $row, string $company): array
    {
        $created = $row->ticket_watermark !== null;

        return [
            "A visitor used the {$company} website contact form. ".($created
                ? "Their inquiry is now ticket #{$row->ticket_id}."
                : "Their inquiry was added to ticket #{$row->ticket_id} as a note."),
            ($created ? 'The ticket' : 'The note').' is hidden from AI agents, automation and the client portal until a staff member verifies it.',
            'Next step: open the review page, check that the sender is who they say they are, then choose "Mark this submission verified".',
        ];
    }
}
