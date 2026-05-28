<?php

namespace App\Console\Commands;

use App\Services\Qbo\QboClientException;
use App\Services\Qbo\QboSyncService;
use Illuminate\Console\Command;

class SyncQboItems extends Command
{
    protected $signature = 'qbo:sync-items
        {--import : Import QBO service items as local SKUs}
        {--push : Push local SKUs to QBO}';

    protected $description = 'Sync SKUs/Items between PSA and QuickBooks Online';

    public function handle(QboSyncService $qboSync): int
    {
        $import = $this->option('import');
        $push = $this->option('push');

        // If neither flag specified, do both
        if (! $import && ! $push) {
            $import = true;
            $push = true;
        }

        if ($import) {
            $this->info('Importing items from QuickBooks...');
            try {
                $result = $qboSync->importQboItems();
                $this->info("Import complete: {$result['created']} created, {$result['updated']} updated, {$result['skipped']} unchanged.");
            } catch (QboClientException $e) {
                $this->error("QBO import failed: {$e->getMessage()}");
                return self::FAILURE;
            }
        }

        if ($push) {
            $this->info('Pushing local SKUs to QuickBooks...');
            $skus = \App\Models\Sku::active()
                ->where(function ($q) {
                    $q->whereNull('qbo_synced_at')
                        ->orWhereColumn('updated_at', '>', 'qbo_synced_at');
                })
                ->get();

            if ($skus->isEmpty()) {
                $this->info('No SKUs need pushing.');
            } else {
                $pushed = 0;
                $errors = 0;
                foreach ($skus as $sku) {
                    try {
                        $qboSync->pushItemToQbo($sku);
                        $pushed++;
                    } catch (\Throwable $e) {
                        $this->error("Failed to push {$sku->sku_code}: {$e->getMessage()}");
                        $errors++;
                    }
                }
                $this->info("Push complete: {$pushed} pushed, {$errors} errors.");
            }
        }

        return self::SUCCESS;
    }
}
