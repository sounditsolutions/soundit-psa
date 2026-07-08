<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeClientException;
use App\Services\Stripe\StripeSyncService;
use App\Support\StripeConfig;
use Illuminate\Console\Command;

class SyncStripeInvoices extends Command
{
    protected $signature = 'stripe:sync-invoices
        {--push-drafts : Push all draft invoices to Stripe}
        {--pull-status : Pull payment status for synced invoices from Stripe}
        {--import : Import historical invoices from Stripe into PSA}
        {--full : Ignore incremental timestamp (use with --import)}
        {--since= : Import invoices created since this date, YYYY-MM-DD (use with --import)}';

    protected $description = 'Sync invoices with Stripe (push drafts, pull status, or import from Stripe)';

    public function handle(): int
    {
        if (! StripeConfig::isConfigured()) {
            $this->error('Stripe is not configured. Add API key in Settings → Integrations.');

            return self::FAILURE;
        }

        $client = new StripeClient(['secret_key' => StripeConfig::get('secret_key')]);
        $service = new StripeSyncService($client);

        $pushDrafts = $this->option('push-drafts');
        $pullStatus = $this->option('pull-status');
        $import = $this->option('import');

        // Default behavior when no flags: push + pull (not import)
        if (! $pushDrafts && ! $pullStatus && ! $import) {
            $pushDrafts = true;
            $pullStatus = true;
        }

        $hasErrors = false;

        if ($import) {
            $since = null;

            if ($this->option('full')) {
                $this->info('Importing invoices from Stripe (full)...');
            } elseif ($this->option('since')) {
                $since = $this->option('since');
                $this->info("Importing invoices from Stripe (since {$since})...");
            } else {
                $since = Setting::getValue('stripe_invoice_import_last_sync');
                if ($since) {
                    $this->info("Importing invoices from Stripe (since {$since})...");
                } else {
                    $this->info('Importing invoices from Stripe (full — no previous import)...');
                }
            }

            try {
                $result = $service->importInvoicesFromStripe(function (int $processed) {
                    $this->line("  Processed {$processed} invoices...");
                }, $since);

                $skipped = $result->deactivated; // repurposed for skipped count
                $summary = $result->summary();
                if ($skipped > 0) {
                    $summary .= ", {$skipped} skipped (no client match)";
                }
                $this->info("  Import done: {$summary}");

                foreach ($result->errorMessages as $error) {
                    $this->warn("  Error: {$error}");
                }

                if ($result->errors > 0) {
                    $hasErrors = true;
                }
            } catch (StripeClientException $e) {
                $this->error('  Import failed: '.$e->getMessage());
                $hasErrors = true;
            }
        }

        if ($pushDrafts) {
            $this->info('Pushing draft invoices to Stripe...');
            try {
                $result = $service->pushAllDraftInvoices();
                $this->info("  Pushed: {$result['pushed']}, Skipped: {$result['skipped']}, Errors: {$result['errors']}");
                if ($result['errors'] > 0) {
                    $hasErrors = true;
                }
            } catch (StripeClientException $e) {
                $this->error('  Push failed: '.$e->getMessage());
                $hasErrors = true;
            }
        }

        if ($pullStatus) {
            $this->info('Pulling invoice status from Stripe...');
            try {
                $updated = $service->syncAllUnpaidInvoices();
                $this->info("  Checked {$updated} invoice(s).");
            } catch (StripeClientException $e) {
                $this->error('  Pull failed: '.$e->getMessage());
                $hasErrors = true;
            }
        }

        $this->info('Stripe invoice sync complete.');

        return $hasErrors ? self::FAILURE : self::SUCCESS;
    }
}
