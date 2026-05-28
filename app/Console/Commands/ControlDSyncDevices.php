<?php

namespace App\Console\Commands;

use App\Services\ControlD\ControlDClient;
use App\Services\ControlD\ControlDDeviceSyncService;
use App\Support\ControlDConfig;
use Illuminate\Console\Command;

class ControlDSyncDevices extends Command
{
    protected $signature = 'controld:sync-devices';

    protected $description = 'Sync DNS security device data from Control D to local assets';

    public function handle(): int
    {
        if (! ControlDConfig::isConfigured()) {
            $this->error('Control D is not configured. Add API credentials in Settings → Integrations.');

            return self::FAILURE;
        }

        $client = new ControlDClient([
            'api_key' => ControlDConfig::get('api_key'),
        ]);

        $service = new ControlDDeviceSyncService($client);

        $this->info('Syncing Control D devices...');

        $result = $service->syncDevices(function ($r) {
            // Progress callback — silent for now
        });

        $this->info("Done: {$result->summary()}");

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
