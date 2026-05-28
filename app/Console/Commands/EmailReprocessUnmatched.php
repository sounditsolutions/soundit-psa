<?php

namespace App\Console\Commands;

use App\Enums\EmailDirection;
use App\Models\Email;
use App\Services\EmailService;
use Illuminate\Console\Command;

class EmailReprocessUnmatched extends Command
{
    protected $signature = 'emails:reprocess-unmatched';
    protected $description = 'Run the inbound processing pipeline on unlinked emails to match them to tickets';

    public function handle(EmailService $emailService): int
    {
        $total = Email::whereNull('ticket_id')
            ->where('direction', EmailDirection::Inbound)
            ->count();

        if ($total === 0) {
            $this->info('No unlinked inbound emails found.');
            return self::SUCCESS;
        }

        $this->info("Processing {$total} unlinked inbound emails...");

        $matched = 0;
        $skipped = 0;

        Email::whereNull('ticket_id')
            ->where('direction', EmailDirection::Inbound)
            ->chunkById(100, function ($emails) use ($emailService, &$matched, &$skipped) {
                foreach ($emails as $email) {
                    $emailService->processInbound($email);

                    if ($email->ticket_id !== null) {
                        $matched++;
                        $this->line("  Matched #{$email->id} ({$email->subject}) → ticket #{$email->ticket_id}");
                    } else {
                        $skipped++;
                    }
                }
            });

        $this->info("Done: {$matched} matched, {$skipped} unmatched.");

        return self::SUCCESS;
    }
}
