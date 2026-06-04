<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphClientException;
use Illuminate\Console\Command;

class EmailTestConnection extends Command
{
    protected $signature = 'email:test-connection';

    protected $description = 'Test Microsoft Graph API connectivity and mailbox access';

    public function handle(GraphClient $graph): int
    {
        $this->info('Testing Microsoft Graph API connection...');

        try {
            if (! $graph->isHealthy()) {
                $this->error('Failed to authenticate with Graph API.');

                return self::FAILURE;
            }
            $this->info('Authentication successful.');
        } catch (GraphClientException $e) {
            $this->error('Authentication failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $mailbox = Setting::getValue('graph_mailbox');
        if (! $mailbox) {
            $this->warn('No mailbox configured (graph_mailbox setting is empty).');
            $this->warn('Set it in Settings > Integrations > Microsoft Graph.');

            return self::SUCCESS;
        }

        $this->info("Testing mailbox access: {$mailbox}");

        try {
            $messages = $graph->get("users/{$mailbox}/mailFolders/inbox/messages", [
                '$top' => 1,
                '$select' => 'id,subject,from,receivedDateTime',
            ]);

            $items = $messages['value'] ?? [];

            if (empty($items)) {
                $this->info('Connected to mailbox — inbox is empty.');
            } else {
                $msg = $items[0];
                $from = $msg['from']['emailAddress']['address'] ?? 'unknown';
                $subject = $msg['subject'] ?? '(no subject)';
                $received = $msg['receivedDateTime'] ?? '';

                $this->info('Connected to mailbox — latest message:');
                $this->line("  From:     {$from}");
                $this->line("  Subject:  {$subject}");
                $this->line("  Received: {$received}");
            }

            return self::SUCCESS;
        } catch (GraphClientException $e) {
            $this->error('Mailbox access failed: '.$e->getMessage());

            if ($e->getHttpStatus() === 403) {
                $this->warn('Ensure Mail.Read application permission is granted with admin consent.');
            }

            return self::FAILURE;
        }
    }
}
