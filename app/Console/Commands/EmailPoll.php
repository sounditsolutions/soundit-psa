<?php

namespace App\Console\Commands;

use App\Services\EmailService;
use Illuminate\Console\Command;

class EmailPoll extends Command
{
    protected $signature = 'email:poll {--since= : Import emails received since this date (ISO 8601)}';

    protected $description = 'Poll the shared mailbox for new emails via Microsoft Graph API';

    public function handle(EmailService $emailService): int
    {
        $since = $this->option('since');

        $this->info('Polling mailbox for new emails...');
        if ($since) {
            $this->info("Since: {$since}");
        }

        $result = $emailService->pollMailbox($since);

        $this->info("Result: {$result->summary()}");

        if ($result->errors > 0) {
            foreach ($result->errorMessages as $error) {
                $this->error("  - {$error}");
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
