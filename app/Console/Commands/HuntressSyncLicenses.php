<?php

namespace App\Console\Commands;

use App\Services\Huntress\HuntressClient;
use App\Services\Huntress\HuntressLicenseSyncService;
use App\Support\HuntressConfig;
use Illuminate\Console\Command;

class HuntressSyncLicenses extends Command
{
    protected $signature = 'huntress:sync-licenses';

    protected $description = 'Sync EDR/ITDR license counts from Huntress for mapped clients';

    public function handle(): int
    {
        if (! HuntressConfig::isConfigured()) {
            $this->error('Huntress is not configured. Add API credentials in Settings → Integrations.');

            return self::FAILURE;
        }

        $client = new HuntressClient([
            'api_key' => HuntressConfig::get('api_key'),
            'api_secret' => HuntressConfig::get('api_secret'),
        ]);

        $service = new HuntressLicenseSyncService($client);

        $this->info('Syncing Huntress licenses...');

        $result = $service->syncLicenses(function ($r) {
            // Progress callback — silent for now
        });

        $this->info("Done: {$result->summary()}");

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
