<?php

namespace App\Console\Commands;

use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalScriptSyncService;
use App\Support\TacticalConfig;
use Illuminate\Console\Command;

class TacticalSyncScripts extends Command
{
    protected $signature = 'tactical:sync-scripts';

    protected $description = 'Sync script library from Tactical RMM';

    public function handle(): int
    {
        if (! TacticalConfig::isConfigured()) {
            $this->warn('Tactical RMM is not configured.');

            return self::FAILURE;
        }

        $service = new TacticalScriptSyncService(new TacticalClient);
        $stats = $service->syncScripts();

        $this->info("Synced: {$stats['synced']}, Created: {$stats['created']}, Removed: {$stats['removed']}");

        return self::SUCCESS;
    }
}
