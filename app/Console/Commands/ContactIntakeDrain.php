<?php

namespace App\Console\Commands;

use App\Models\ContactSubmission;
use App\Services\ContactIntake\IntakeNotifications;
use App\Services\ContactIntake\SubmissionProcessor;
use App\Support\ContactIntakeConfig;
use Illuminate\Console\Command;

final class ContactIntakeDrain extends Command
{
    protected $signature = 'contact-intake:drain';

    protected $description = 'Process pending contact submissions and drain internal alerts';

    /** Failed processing attempts before a row leaves the pending queue for staff (r1 diff:4). */
    public const MAX_ATTEMPTS = 5;

    public function handle(SubmissionProcessor $processor, IntakeNotifications $notifications): int
    {
        if (! ContactIntakeConfig::enabled()) {
            $this->info('Contact intake disabled.');

            return self::SUCCESS;
        }
        $failed = false;
        foreach (ContactSubmission::where('state', 'pending')->orderBy('attempts')->orderBy('id')->limit(100)->pluck('id') as $id) {
            try {
                $processor->process($id);
            } catch (\Throwable) {
                // Never log payloads or exception text: SQL bindings may contain PII.
                // The processor's own attempts increment rolled back with its transaction,
                // so the failure is counted HERE, outside it. A row that keeps failing is
                // moved to the staff exception queue instead of being retried forever and
                // starving newer submissions.
                ContactSubmission::whereKey($id)->where('state', 'pending')->increment('attempts');
                ContactSubmission::whereKey($id)->where('state', 'pending')
                    ->where('attempts', '>=', self::MAX_ATTEMPTS)
                    ->update(['state' => 'quarantined', 'exception_reason' => 'processing_failed']);
                IntakeNotifications::record(ContactSubmission::findOrFail($id), 'processing_failed');
                $failed = true;
            }
        }
        $notifications->drain();
        $this->info('Pending: '.ContactSubmission::where('state', 'pending')->count());

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
