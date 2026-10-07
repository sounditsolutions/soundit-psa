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
 *
 * --accept-short-read=<PSA client id> (repeatable, #5577) is the one way
 * through the short-read bound for a client whose change an admin has checked
 * is real (a re-enrollment wave, a decommission): that run syncs the named
 * clients as if the bound had not fired. It applies to that run only, nothing
 * stores it, and neither the schedule nor the Sync buttons pass it.
 */
class LitsrmmSyncDevices extends Command
{
    protected $signature = 'litsrmm:sync-devices
        {--client= : Sync devices for one client ID only}
        {--accept-short-read=* : PSA client ID whose refused short read you have checked is real; applies to this run only}';

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

        $accept = $this->option('accept-short-read');
        foreach ($accept as $id) {
            if (! ctype_digit((string) $id)) {
                $this->error('--accept-short-read takes PSA client IDs (digits only). Nothing was read.');

                return self::FAILURE;
            }
        }
        if ($accept !== []) {
            $this->warn('Accepting a short read for this run only, for PSA client ID(s): '.implode(', ', $accept));
        }

        if ($clientId = $this->option('client')) {
            $client = Client::find($clientId);
            if (! $client) {
                $this->error("Client ID {$clientId} not found.");

                return self::FAILURE;
            }

            $this->info("Syncing LITSRMM devices for client ID {$client->id}...");

            return $this->report($sync->sync($client, $accept));
        }

        $mapped = Client::whereNotNull('litsrmm_client_id')->operational()->count();

        if ($mapped === 0) {
            $this->warn('No clients are mapped to LITSRMM.');
            $this->info('Map them at: Settings > Integrations > LITSRMM');

            return self::SUCCESS;
        }

        $this->info("Syncing LITSRMM devices for {$mapped} mapped client(s)...");

        return $this->report($sync->sync(null, $accept));
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

        if (($result->details['short_read_accepted'] ?? 0) > 0) {
            $this->warn("  Short read accepted by --accept-short-read: {$result->details['short_read_accepted']} client(s)");
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
