<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Cipp\CippClient;
use App\Services\Cipp\CippDeviceSyncService;
use App\Services\SyncResult;
use App\Support\CippConfig;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheInterface;

class CippSyncDevices extends Command
{
    protected $signature = 'cipp:sync-devices
        {--client= : Sync a specific client by ID}
        {--dry-run : Preview changes without writing}';

    protected $description = 'Sync Intune managed devices from CIPP into the assets table';

    public function handle(): int
    {
        if (! CippConfig::isConfigured()) {
            $this->error('CIPP is not configured.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('[DRY RUN] No changes will be written.');
        }

        $cippClient = new CippClient(
            [
                'api_url' => CippConfig::get('api_url'),
                'tenant_id' => CippConfig::get('tenant_id'),
                'client_id' => CippConfig::get('client_id'),
                'client_secret' => CippConfig::get('client_secret'),
                'application_id' => CippConfig::get('application_id'),
            ],
            app(CacheInterface::class),
        );

        $service = new CippDeviceSyncService($cippClient);

        $clientId = $this->option('client');

        if ($clientId) {
            $clientModel = Client::find($clientId);
            if (! $clientModel || ! $clientModel->cipp_tenant_domain) {
                $this->error("Client #{$clientId} not found or has no CIPP tenant mapping.");

                return self::FAILURE;
            }

            $this->info("Syncing Intune devices for {$clientModel->name}...");
            $result = new SyncResult;
            $service->syncDevicesForClient($clientModel, $result, $dryRun);
        } else {
            $this->info('Syncing Intune devices for all mapped clients...');
            $result = $service->syncDevices(dryRun: $dryRun);
        }

        $prefix = $dryRun ? '[DRY RUN] Would: ' : 'Done: ';
        $this->info($prefix.$result->summary());

        if ($result->errors > 0) {
            foreach ($result->errorMessages as $msg) {
                $this->warn("  - {$msg}");
            }
        }

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
