<?php

namespace App\Console\Commands;

use App\Services\Qbo\QboClient;
use App\Services\Qbo\QboClientException;
use App\Services\Qbo\QboSyncService;
use Illuminate\Console\Command;

class SyncQboInvoices extends Command
{
    protected $signature = 'qbo:sync-invoices
        {--push-drafts : Push all draft invoices to QBO}
        {--pull-status : Pull payment status for synced invoices from QBO}';

    protected $description = 'Sync invoices with QuickBooks Online (push drafts and/or pull status)';

    public function handle(QboClient $qboClient, QboSyncService $syncService): int
    {
        if (!$qboClient->isConnected()) {
            $this->error('Not connected to QuickBooks Online. Go to Settings → Integrations to connect.');
            return self::FAILURE;
        }

        $pushDrafts = $this->option('push-drafts');
        $pullStatus = $this->option('pull-status');

        // Default: do both
        if (!$pushDrafts && !$pullStatus) {
            $pushDrafts = true;
            $pullStatus = true;
        }

        $hasErrors = false;

        if ($pushDrafts) {
            $this->info('Pushing draft invoices to QBO...');

            try {
                $result = $syncService->pushAllDraftInvoices();
                $this->info("  Pushed: {$result['pushed']}, Skipped: {$result['skipped']}, Errors: {$result['errors']}");
                if ($result['errors'] > 0) {
                    $hasErrors = true;
                }
            } catch (QboClientException $e) {
                $this->error('  Push failed: ' . $e->getMessage());
                $hasErrors = true;
            }
        }

        if ($pullStatus) {
            $this->info('Pulling invoice status from QBO...');

            try {
                $updated = $syncService->syncAllUnpaidInvoices();
                $this->info("  Checked {$updated} invoice(s).");
            } catch (QboClientException $e) {
                $this->error('  Pull failed: ' . $e->getMessage());
                $hasErrors = true;
            }
        }

        $this->newLine();
        $this->info('QBO invoice sync complete.');

        return $hasErrors ? self::FAILURE : self::SUCCESS;
    }
}
