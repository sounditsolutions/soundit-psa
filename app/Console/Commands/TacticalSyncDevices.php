<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalDeviceSyncService;
use App\Support\TacticalConfig;
use Illuminate\Console\Command;

class TacticalSyncDevices extends Command
{
    protected $signature = 'tactical:sync-devices
        {--client= : Sync devices for a specific client ID}';

    protected $description = 'Sync devices from Tactical RMM into tactical_assets table';

    public function handle(): int
    {
        if (! TacticalConfig::isConfigured()) {
            $this->error('Tactical RMM is not configured. Add API credentials in Settings → Integrations.');

            return self::FAILURE;
        }

        $clientId = $this->option('client');

        if ($clientId) {
            $client = Client::find($clientId);
            if (! $client) {
                $this->error("Client {$clientId} not found.");

                return self::FAILURE;
            }
            $this->info("Scoping to client: {$client->name}");
        }

        $service = new TacticalDeviceSyncService(app(TacticalClient::class));

        $this->info('Syncing Tactical RMM devices...');

        $result = $service->syncDevices($clientId ? (int) $clientId : null);

        $this->info("Done: {$result->summary()}");

        if (! empty($result->details['linked'])) {
            $this->info("Linked: {$result->details['linked']}");
        }

        if (! empty($result->details['assets_created'])) {
            $this->info("Assets created: {$result->details['assets_created']}");
        }

        // A skipped device is invisible in the Assets list for exactly the same
        // reason the NULL-asset_id rows were: no Asset, no UI. Warn rather than
        // info, and name the reasons, so "0 created" cannot read as "nothing to
        // do here".
        if (! empty($result->details['assets_skipped'])) {
            $this->warn("Assets skipped: {$result->details['assets_skipped']}");

            foreach ($result->details['assets_skipped_reasons'] ?? [] as $reason => $count) {
                $this->warn("  - {$reason}: {$count}");
            }
        }

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
