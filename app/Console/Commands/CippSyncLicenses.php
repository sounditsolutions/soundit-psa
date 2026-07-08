<?php

namespace App\Console\Commands;

use App\Services\Cipp\CippClient;
use App\Services\Cipp\CippLicenseSyncService;
use App\Support\CippConfig;
use Illuminate\Console\Command;

class CippSyncLicenses extends Command
{
    protected $signature = 'cipp:sync-licenses';

    protected $description = 'Sync M365 license counts from CIPP for mapped clients';

    public function handle(): int
    {
        if (! CippConfig::isConfigured()) {
            $this->error('CIPP is not configured. Add credentials in Settings → Integrations.');

            return self::FAILURE;
        }

        $client = new CippClient(
            [
                'api_url' => CippConfig::get('api_url'),
                'tenant_id' => CippConfig::get('tenant_id'),
                'client_id' => CippConfig::get('client_id'),
                'client_secret' => CippConfig::get('client_secret'),
                'application_id' => CippConfig::get('application_id'),
            ],
            app(\Illuminate\Contracts\Cache\Repository::class),
        );

        $service = new CippLicenseSyncService($client);

        $this->info('Syncing CIPP/M365 licenses...');

        $result = $service->syncLicenses();

        $this->info("Done: {$result->created} created, {$result->updated} updated, {$result->errors} errors.");

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
