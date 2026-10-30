<?php

namespace App\Services\ContactIntake;

use App\Models\ContactSubmission;
use App\Services\Signals\SignalHub;
use Illuminate\Support\Facades\DB;

/**
 * Alerts Hub signals for the contact-intake web form (card JyBDeHPz). Metadata only: the
 * event's entity is the submission, and its context is the ticket id, the age since
 * receipt and the staff review link. The encrypted visitor payload is never read here.
 */
final class IntakeSignals
{
    public const RECEIVED = 'intake.webform_received';

    public const VERIFIED = 'intake.webform_verified';

    /** Summaries are the approved Alerts Hub labels, so a sink adds no new wording. */
    private const SUMMARIES = [
        self::RECEIVED => 'Web form inquiry received',
        self::VERIFIED => 'Web form inquiry verified',
    ];

    /** Emits once the surrounding transaction commits; a rolled-back write emits nothing. */
    public static function emitAfterCommit(int $submissionId, string $type): void
    {
        DB::afterCommit(fn () => self::emit($submissionId, $type));
    }

    private static function emit(int $submissionId, string $type): void
    {
        $row = ContactSubmission::find($submissionId);
        if ($row === null || ! isset(self::SUMMARIES[$type])) {
            return;
        }
        app(SignalHub::class)->emit($type, $row, self::SUMMARIES[$type], [
            'ticket_id' => $row->ticket_id,
            'age_seconds' => $row->created_at === null ? null : max(0, now()->getTimestamp() - $row->created_at->getTimestamp()),
            'review_url' => route('contact-intake.show', $row->id),
        ]);
    }
}
