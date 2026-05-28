<?php

namespace App\Console\Commands;

use App\Services\ControlD\ControlDClient;
use App\Services\ControlD\ControlDLicenseSyncService;
use App\Support\ControlDConfig;
use Illuminate\Console\Command;

class ControlDSyncLicenses extends Command
{
    protected $signature = 'controld:sync-licenses';

    protected $description = 'Sync DNS security device counts from Control D for mapped clients';

    public function handle(): int
    {
        if (! ControlDConfig::isConfigured()) {
            $this->error('Control D is not configured. Add API credentials in Settings → Integrations.');

            return self::FAILURE;
        }

        $client = new ControlDClient([
            'api_key' => ControlDConfig::get('api_key'),
        ]);

        $service = new ControlDLicenseSyncService($client);

        $this->info('Syncing Control D licenses...');

        $result = $service->syncLicenses(function ($r) {
            // Progress callback — silent for now
        });

        $this->info("Done: {$result->summary()}");

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
