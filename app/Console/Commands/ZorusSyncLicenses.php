<?php

namespace App\Console\Commands;

use App\Services\Zorus\ZorusClient;
use App\Services\Zorus\ZorusLicenseSyncService;
use App\Support\ZorusConfig;
use Illuminate\Console\Command;

class ZorusSyncLicenses extends Command
{
    protected $signature = 'zorus:sync-licenses';

    protected $description = 'Sync DNS security endpoint counts from Zorus for mapped clients';

    public function handle(): int
    {
        if (! ZorusConfig::isConfigured()) {
            $this->error('Zorus is not configured. Add API credentials in Settings > Integrations.');

            return self::FAILURE;
        }

        $client = new ZorusClient([
            'api_key' => ZorusConfig::get('api_key'),
        ]);

        $service = new ZorusLicenseSyncService($client);

        $this->info('Syncing Zorus licenses...');

        $result = $service->syncLicenses();

        $this->info("Done: {$result->summary()}");

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
