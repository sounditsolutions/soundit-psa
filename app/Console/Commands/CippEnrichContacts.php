<?php

namespace App\Console\Commands;

use App\Services\Cipp\CippClient;
use App\Services\Cipp\CippContactEnrichmentService;
use App\Models\Client;
use App\Support\CippConfig;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheInterface;

class CippEnrichContacts extends Command
{
    protected $signature = 'cipp:enrich-contacts {--client= : Enrich a specific client by ID}';

    protected $description = 'Enrich CIPP-synced contacts with mailbox size and MFA status';

    public function handle(): int
    {
        if (! CippConfig::isConfigured()) {
            $this->error('CIPP is not configured.');

            return self::FAILURE;
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

        $service = new CippContactEnrichmentService($cippClient);

        $clientId = $this->option('client');

        if ($clientId) {
            $clientModel = Client::find($clientId);
            if (! $clientModel || ! $clientModel->cipp_tenant_domain) {
                $this->error("Client #{$clientId} not found or has no CIPP tenant mapping.");

                return self::FAILURE;
            }

            $this->info("Enriching contacts for {$clientModel->name}...");
            $result = new \App\Services\SyncResult;
            $service->enrichForClient($clientModel, $result);
        } else {
            $this->info('Enriching CIPP contacts (mailbox + MFA)...');
            $result = $service->enrichContacts();
        }

        $this->info("Done: {$result->summary()}");

        if ($result->errors > 0) {
            foreach ($result->errorMessages as $msg) {
                $this->warn("  - {$msg}");
            }
        }

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
