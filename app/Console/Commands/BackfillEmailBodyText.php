<?php

namespace App\Console\Commands;

use App\Models\Email;
use App\Services\EmailService;
use Illuminate\Console\Command;

class BackfillEmailBodyText extends Command
{
    protected $signature = 'emails:backfill-text';
    protected $description = 'Backfill body_text from body_html for existing emails';

    public function handle(EmailService $emailService): int
    {
        $total = Email::whereNull('body_text')
            ->whereNotNull('body_html')
            ->count();

        if ($total === 0) {
            $this->info('No emails need backfilling.');
            return self::SUCCESS;
        }

        $this->info("Backfilling {$total} emails...");
        $updated = 0;

        Email::whereNull('body_text')
            ->whereNotNull('body_html')
            ->chunkById(100, function ($emails) use ($emailService, &$updated) {
                foreach ($emails as $email) {
                    $text = $emailService->extractPlainText($email->getRawOriginal('body_html'));
                    if ($text) {
                        $email->body_text = $text;
                        $email->save();
                        $updated++;
                    }
                }
            });

        $this->info("Done: {$updated} of {$total} emails backfilled.");

        return self::SUCCESS;
    }
}
