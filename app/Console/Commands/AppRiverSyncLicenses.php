<?php

namespace App\Console\Commands;

use App\Services\AppRiver\AppRiverClient;
use App\Services\AppRiver\AppRiverLicenseSyncService;
use Illuminate\Console\Command;

class AppRiverSyncLicenses extends Command
{
    protected $signature = 'appriver:sync-licenses';

    protected $description = 'Sync M365 subscription seat counts from AppRiver for mapped clients';

    public function handle(): int
    {
        if (! AppRiverClient::isConnected()) {
            $this->error('AppRiver is not connected. Connect via Settings > Integrations first.');

            return self::FAILURE;
        }

        $client = new AppRiverClient;
        $service = new AppRiverLicenseSyncService($client);

        $this->info('Syncing AppRiver subscriptions...');

        $result = $service->syncLicenses(function ($r) {
            // Progress callback — silent for now
        });

        $this->info("Done: {$result->summary()}");

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
