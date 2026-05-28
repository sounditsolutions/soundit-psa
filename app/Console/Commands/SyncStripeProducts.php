<?php

namespace App\Console\Commands;

use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeClientException;
use App\Services\Stripe\StripeSyncService;
use App\Support\StripeConfig;
use Illuminate\Console\Command;

class SyncStripeProducts extends Command
{
    protected $signature = 'stripe:sync-products
        {--import : Import Stripe products as local SKUs}
        {--push : Push local SKUs to Stripe}';

    protected $description = 'Sync SKUs/Products between PSA and Stripe';

    public function handle(): int
    {
        if (! StripeConfig::isConfigured()) {
            $this->error('Stripe is not configured. Add API key in Settings → Integrations.');
            return self::FAILURE;
        }

        $client = new StripeClient(['secret_key' => StripeConfig::get('secret_key')]);
        $service = new StripeSyncService($client);

        $import = $this->option('import');
        $push = $this->option('push');

        if (! $import && ! $push) {
            $import = true;
            $push = true;
        }

        if ($import) {
            $this->info('Importing products from Stripe...');
            try {
                $result = $service->importStripeProducts();
                $this->info("  Created: {$result['created']}, Updated: {$result['updated']}, Skipped: {$result['skipped']}");
            } catch (StripeClientException $e) {
                $this->error('  Import failed: ' . $e->getMessage());
                return self::FAILURE;
            }
        }

        if ($push) {
            $this->info('Pushing SKUs to Stripe...');
            $skus = \App\Models\Sku::active()
                ->where(function ($q) {
                    $q->whereNull('stripe_synced_at')
                        ->orWhereColumn('updated_at', '>', 'stripe_synced_at');
                })
                ->get();

            if ($skus->isEmpty()) {
                $this->info('  No SKUs need pushing.');
            } else {
                $pushed = 0;
                $errors = 0;
                foreach ($skus as $sku) {
                    try {
                        $service->pushProductToStripe($sku);
                        $pushed++;
                    } catch (\Throwable $e) {
                        $this->error("  Failed to push {$sku->sku_code}: {$e->getMessage()}");
                        $errors++;
                    }
                }
                $this->info("  Pushed: {$pushed}, Errors: {$errors}");
            }
        }

        return self::SUCCESS;
    }
}
