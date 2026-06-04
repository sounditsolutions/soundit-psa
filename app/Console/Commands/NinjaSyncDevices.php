<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Ninja\NinjaSyncService;
use Illuminate\Console\Command;

class NinjaSyncDevices extends Command
{
    protected $signature = 'ninja:sync-devices {--client= : Sync devices for a specific client ID}';

    protected $description = 'Sync devices from NinjaRMM for mapped clients';

    public function handle(NinjaSyncService $sync): int
    {
        if ($clientId = $this->option('client')) {
            $client = Client::find($clientId);
            if (! $client) {
                $this->error("Client ID {$clientId} not found.");

                return self::FAILURE;
            }
            if (! $client->ninja_org_id) {
                $this->error("Client '{$client->name}' has no NinjaRMM org mapping.");

                return self::FAILURE;
            }

            $this->info("Syncing devices for {$client->name} (Ninja org {$client->ninja_org_id})...");
            $result = $sync->syncDevicesForClient($client);
        } else {
            $mappedCount = Client::whereNotNull('ninja_org_id')->count();
            if ($mappedCount === 0) {
                $this->warn('No clients are mapped to NinjaRMM organizations.');
                $this->info('Run: php artisan ninja:map-orgs --auto');

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
