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

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
