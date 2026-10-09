<?php

namespace App\Console\Commands;

use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshLicenseSyncService;
use App\Support\MeshConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MeshSyncLicenses extends Command
{
    protected $signature = 'mesh:sync-licenses';

    protected $description = 'Sync license counts from Mesh Email Security for mapped clients';

    public function handle(): int
    {
        if (! MeshConfig::isConfigured()) {
            // #6213: a stored '0' is set but unusable, not 'not configured'.
            // #6297: the schedule runs this in the background, where console
            // output is discarded, so the refusal is logged too.
            $text = MeshConfig::notConfiguredText('Mesh is not configured. Add API key in Settings → Integrations.');
            Log::warning('[MeshSyncLicenses] '.$text.' No Mesh licenses were synced; license counts are not being updated.');
            $this->error($text);

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
