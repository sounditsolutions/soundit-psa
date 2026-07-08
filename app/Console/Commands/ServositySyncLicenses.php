<?php

namespace App\Console\Commands;

use App\Services\Servosity\ServosityClient;
use App\Services\Servosity\ServosityLicenseSyncService;
use App\Support\ServosityConfig;
use Illuminate\Console\Command;

class ServositySyncLicenses extends Command
{
    protected $signature = 'servosity:sync-licenses';

    protected $description = 'Sync backup license counts from Servosity for mapped clients';

    public function handle(): int
    {
        if (! ServosityConfig::isConfigured()) {
            $this->error('Servosity is not configured. Add API credentials in Settings > Integrations.');

            return self::FAILURE;
        }

        $client = new ServosityClient([
            'api_token' => ServosityConfig::get('api_token'),
            'base_url' => ServosityConfig::get('base_url'),
        ]);

        $service = new ServosityLicenseSyncService($client);

        $this->info('Syncing Servosity licenses...');

        $result = $service->syncLicenses(function ($r) {
            // Progress callback — silent for now
        });

        $this->info("Done: {$result->summary()}");

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
