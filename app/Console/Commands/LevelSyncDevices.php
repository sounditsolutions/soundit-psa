<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Level\LevelSyncService;
use Illuminate\Console\Command;

class LevelSyncDevices extends Command
{
    protected $signature = 'level:sync-devices {--client= : Sync devices for a specific client ID}';

    protected $description = 'Sync devices from Level RMM for mapped clients';

    public function handle(LevelSyncService $sync): int
    {
        if ($clientId = $this->option('client')) {
            $client = Client::find($clientId);
            if (! $client) {
                $this->error("Client ID {$clientId} not found.");

                return self::FAILURE;
            }
            if (! $client->level_group_id) {
                $this->error("Client '{$client->name}' has no Level group mapping.");

                return self::FAILURE;
            }

            $this->info("Syncing devices for {$client->name} (Level group {$client->level_group_id})...");
            $result = $sync->syncDevicesForClient($client);
        } else {
            $mappedCount = Client::whereNotNull('level_group_id')->count();
            if ($mappedCount === 0) {
                $this->warn('No clients are mapped to Level groups.');
                $this->info('Map groups at: Settings > Integrations > Level RMM > Group Mapping');

                return self::SUCCESS;
            }

            $this->info("Syncing devices for {$mappedCount} mapped client(s)...");
            $result = $sync->syncAllDevices(function (int $current, int $total, string $clientName) {
                $this->line("  [{$current}/{$total}] {$clientName}");
            });
        }

        $this->newLine();
        $this->info("Done: {$result->summary()}");

        foreach ($result->errorMessages as $error) {
            $this->warn("  Error: {$error}");
        }

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
