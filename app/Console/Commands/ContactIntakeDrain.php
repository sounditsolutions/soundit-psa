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

    public function handle(SubmissionProcessor $processor, IntakeNotifications $notifications): int
    {
        if (! ContactIntakeConfig::enabled()) {
            $this->info('Contact intake disabled.');

            return self::SUCCESS;
        }
        $failed = false;
        foreach (ContactSubmission::where('state', 'pending')->orderBy('id')->limit(100)->pluck('id') as $id) {
            try {
                $processor->process($id);
            } catch (\Throwable) {
                // Never log payloads or exception text: SQL bindings may contain PII.
                IntakeNotifications::record(ContactSubmission::findOrFail($id), 'processing_failed');
                $failed = true;
            }
        }
        $notifications->drain();
        $this->info('Pending: '.ContactSubmission::where('state', 'pending')->count());

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
