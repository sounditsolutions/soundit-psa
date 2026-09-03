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
        {--pull-status : Pull payment status for synced invoices from QBO}
        {--paid-limit=250 : How many already-Paid invoices to re-check against QBO per run (0 disables the re-check)}';

    protected $description = 'Sync invoices with QuickBooks Online (push drafts and/or pull status)';

    public function handle(QboClient $qboClient, QboSyncService $syncService): int
    {
        if (! $qboClient->isConnected()) {
            $this->error('Not connected to QuickBooks Online. Go to Settings → Integrations to connect.');

            return self::FAILURE;
        }

        $pushDrafts = $this->option('push-drafts');
        $pullStatus = $this->option('pull-status');

        // Default: do both
        if (! $pushDrafts && ! $pullStatus) {
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
                $this->error('  Push failed: '.$e->getMessage());
                $hasErrors = true;
            }
        }

        if ($pullStatus) {
            $this->info('Pulling invoice status from QBO...');

            try {
                $updated = $syncService->syncAllUnpaidInvoices();
                $this->info("  Checked {$updated} invoice(s).");
            } catch (QboClientException $e) {
                $this->error('  Pull failed: '.$e->getMessage());
                $hasErrors = true;
            }

            // #1173 — the unpaid walk above cannot see a Paid invoice, so a
            // wrong Paid never self-corrected. Bounded per run; --paid-limit=0
            // switches it off for an operator who needs the cheap pass only.
            $paidLimit = (int) $this->option('paid-limit');

            if ($paidLimit > 0) {
                $this->info("Re-checking up to {$paidLimit} paid invoice(s) against QBO...");

                try {
                    $result = $syncService->syncPaidInvoicesFromQbo($paidLimit);
                    $this->info("  Checked: {$result['checked']}, Reverted to open: {$result['reverted']}, Errors: {$result['errors']}");
                    $this->info("  Paid invoices QBO has never been asked about, remaining: {$result['never_checked']}");

                    if ($result['failing'] > 0) {
                        // A remaining count that stops falling is otherwise
                        // indistinguishable from a backlog not yet reached.
                        $this->warn("  {$result['failing']} of those carry a sync error from a failed attempt and are checked last.");
                    }

                    if ($result['reverted'] > 0) {
                        // Loud on purpose: a revert changes what a client is
                        // shown as owing. The per-invoice detail is in the log
                        // at warning level.
                        $this->warn("  {$result['reverted']} invoice(s) moved back to open — QBO reports a balance. See the log for each.");
                    }

                    if ($result['errors'] > 0) {
                        $hasErrors = true;
                    }
                } catch (QboClientException $e) {
                    $this->error('  Paid re-check failed: '.$e->getMessage());
                    $hasErrors = true;
                }
            }
        }

        $this->newLine();
        $this->info('QBO invoice sync complete.');

        return $hasErrors ? self::FAILURE : self::SUCCESS;
    }
}
