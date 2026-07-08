<?php

namespace App\Console\Commands;

use App\Services\Cipp\CippClient;
use App\Support\CippConfig;
use Illuminate\Console\Command;

class CippTest extends Command
{
    protected $signature = 'cipp:test';

    protected $description = 'Test CIPP API connectivity (OAuth2 + tenant list)';

    public function handle(): int
    {
        if (! CippConfig::isConfigured()) {
            $this->error('CIPP is not configured. Add credentials in Settings → Integrations.');

            return self::FAILURE;
        }

        $this->info('Testing CIPP API connection...');

        $client = new CippClient(
            [
                'api_url' => CippConfig::get('api_url'),
                'tenant_id' => CippConfig::get('tenant_id'),
                'client_id' => CippConfig::get('client_id'),
                'client_secret' => CippConfig::get('client_secret'),
                'application_id' => CippConfig::get('application_id'),
            ],
            app(\Illuminate\Contracts\Cache\Repository::class),
        );

        if ($client->isHealthy()) {
            $tenants = $client->listTenants();
            $count = is_array($tenants) ? count($tenants) : 0;
            $this->info("Connected to CIPP! Found {$count} tenant(s).");

            return self::SUCCESS;
        }

        $this->error('Failed to connect to CIPP. Check your credentials.');

        return self::FAILURE;
    }
}
