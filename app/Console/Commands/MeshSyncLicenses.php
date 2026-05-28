<?php

namespace App\Console\Commands;

use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshLicenseSyncService;
use App\Support\MeshConfig;
use Illuminate\Console\Command;

class MeshSyncLicenses extends Command
{
    protected $signature = 'mesh:sync-licenses';

    protected $description = 'Sync license counts from Mesh Email Security for mapped clients';

    public function handle(): int
    {
        if (! MeshConfig::isConfigured()) {
            $this->error('Mesh is not configured. Add API key in Settings → Integrations.');
            return self::FAILURE;
        }

        $client = new MeshClient([
            'api_key' => MeshConfig::get('api_key'),
            'base_url' => MeshConfig::get('base_url'),
        ]);

        $service = new MeshLicenseSyncService($client);

        $this->info('Syncing Mesh licenses...');

        $result = $service->syncLicenses(function ($r) {
            // Progress callback — silent for now
        });

        $this->info("Done: {$result->created} created, {$result->updated} updated, {$result->errors} errors.");

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
