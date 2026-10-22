<?php

namespace App\Console\Commands;

use App\Services\Zorus\ZorusClient;
use App\Services\Zorus\ZorusDeviceSyncService;
use App\Support\ZorusConfig;
use Illuminate\Console\Command;

class ZorusSyncDevices extends Command
{
    protected $signature = 'zorus:sync-devices';

    protected $description = 'Sync DNS security endpoint data from Zorus to local assets';

    public function handle(): int
    {
        if (! ZorusConfig::isEnabled()) {
            $this->warn('Zorus integration is switched off (set zorus_enabled=1 to re-enable). Skipping.');

            return self::SUCCESS;
        }

        if (! ZorusConfig::isConfigured()) {
            $this->error('Zorus is not configured. Add API credentials in Settings > Integrations.');

            return self::FAILURE;
        }

        $client = app()->make(ZorusClient::class, ['config' => [
            'api_key' => ZorusConfig::get('api_key'),
        ]]);

        $service = new ZorusDeviceSyncService($client);

        $this->info('Syncing Zorus devices...');

        $result = $service->syncDevices();

        $this->info("Done: {$result->summary()}");

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
