<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Cipp\CippClient;
use App\Services\Cipp\CippContactSyncService;
use App\Services\PersonService;
use App\Services\SyncResult;
use App\Support\CippConfig;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheInterface;

class CippSyncContacts extends Command
{
    protected $signature = 'cipp:sync-contacts
        {--client= : Sync a specific client by ID}
        {--dry-run : Preview changes without writing}';

    protected $description = 'Sync M365 users from CIPP into the people table for mapped clients';

    public function handle(): int
    {
        if (! CippConfig::isConfigured()) {
            $this->error('CIPP is not configured. Connect via Settings > Integrations first.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('[DRY RUN] No changes will be written.');
        }

        // Note: the cipp_contact_sync_enabled toggle only gates the SCHEDULED cron
        // (in routes/console.php). Manual runs from CLI or UI always proceed.

        $client = new CippClient(
            [
                'api_url' => CippConfig::get('api_url'),
                'tenant_id' => CippConfig::get('tenant_id'),
                'client_id' => CippConfig::get('client_id'),
                'client_secret' => CippConfig::get('client_secret'),
                'application_id' => CippConfig::get('application_id'),
            ],
            app(CacheInterface::class),
        );

        $service = new CippContactSyncService($client, app(PersonService::class));

        $clientId = $this->option('client');

        if ($clientId) {
            $clientModel = Client::find($clientId);
            if (! $clientModel || ! $clientModel->cipp_tenant_domain) {
                $this->error("Client #{$clientId} not found or has no CIPP tenant mapping.");

                return self::FAILURE;
            }

            $this->info("Syncing contacts for {$clientModel->name}...");
            $result = new SyncResult;
            $service->syncClientContacts($clientModel, $result, $dryRun);
        } else {
            $this->info('Syncing CIPP/M365 contacts for all mapped clients...');
            $result = $service->syncContacts(null, $dryRun);
        }

        $prefix = $dryRun ? '[DRY RUN] Would: ' : 'Done: ';
        $this->info($prefix . $result->summary());

        if ($result->errors > 0) {
            foreach ($result->errorMessages as $msg) {
                $this->warn("  - {$msg}");
            }
        }

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
