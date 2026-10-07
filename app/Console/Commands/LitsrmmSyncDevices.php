<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Litsrmm\LitsrmmAssetSyncService;
use App\Support\LitsrmmConfig;
use Illuminate\Console\Command;

/**
 * Sync LITSRMM devices into PSA assets for every mapped, operational client.
 *
 * Scheduled every 4 hours in routes/console.php, but only once an admin has
 * switched on "Sync every 4 hours" (Settings > Integrations > LITSRMM), which
 * is off by default until a supervised manual run has been read.
 */
class LitsrmmSyncDevices extends Command
{
    protected $signature = 'litsrmm:sync-devices {--client= : Sync devices for one client ID only}';

    protected $description = 'Sync devices from LITSRMM into PSA assets for mapped clients';

    public function handle(LitsrmmAssetSyncService $sync): int
    {
        if (! LitsrmmConfig::isEnabled()) {
            $this->error('The LITSRMM integration is switched off. Nothing was read.');

            return self::FAILURE;
        }

        if (! LitsrmmConfig::isConfigured()) {
            $this->error('LITSRMM has no API key or base URL. Nothing was read.');

            return self::FAILURE;
        }

        if ($clientId = $this->option('client')) {
            $client = Client::find($clientId);
            if (! $client) {
                $this->error("Client ID {$clientId} not found.");

                return self::FAILURE;
            }

            $this->info("Syncing LITSRMM devices for {$client->name}...");

            return $this->report($sync->sync($client));
        }

        $mapped = Client::whereNotNull('litsrmm_client_id')->operational()->count();

        if ($mapped === 0) {
            $this->warn('No clients are mapped to LITSRMM.');
            $this->info('Map them at: Settings > Integrations > LITSRMM');

            return self::SUCCESS;
        }

        $this->info("Syncing LITSRMM devices for {$mapped} mapped client(s)...");

        return $this->report($sync->sync());
    }

    private function report(\App\Services\SyncResult $result): int
    {
        $this->newLine();
        $this->info("Done: {$result->summary()}");

        // Counted apart from "deactivated" (released links): these assets left
        // the active, billed list this run.
        if (($result->details['retired'] ?? 0) > 0) {
            $this->warn("  Marked inactive (device retired): {$result->details['retired']}");
        }

        foreach ($result->skippedMessages as $message) {
            $this->warn("  Skipped: {$message}");
        }

        foreach ($result->errorMessages as $error) {
            $this->error("  Error: {$error}");
        }

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
