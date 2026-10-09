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
        // #6335: Mesh switched off is the operator's choice, not a fault:
        // nothing is synced and nothing is logged. The schedule does not
        // run this command then (routes/console.php); a manual run says so.
        if (! MeshConfig::isEnabled()) {
            $this->warn('Mesh is switched off (Settings → Integrations); no Mesh licenses were synced.');

            return self::SUCCESS;
        }

        if (! MeshConfig::isConfigured()) {
            // #6213: a stored '0' is set but unusable, not 'not configured'.
            // #6297: the schedule runs this in the background, where console
            // output is discarded, so the refusal is logged too. #6335: only
            // while Mesh is switched on; the text names this run only.
            $text = MeshConfig::notConfiguredText('Mesh is not configured. Add API key in Settings → Integrations.');
            Log::warning('[MeshSyncLicenses] '.$text.' No Mesh licenses were synced on this run.');
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
