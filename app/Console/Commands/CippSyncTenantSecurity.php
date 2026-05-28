<?php

namespace App\Console\Commands;

use App\Services\Cipp\CippClient;
use App\Services\Cipp\CippTenantSecuritySyncService;
use App\Support\CippConfig;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheInterface;

class CippSyncTenantSecurity extends Command
{
    protected $signature = 'cipp:sync-tenant-security';

    protected $description = 'Snapshot transport rules, Safe Links policy, and Safe Attachments filters per CIPP-mapped client';

    public function handle(): int
    {
        if (! CippConfig::isConfigured()) {
            $this->error('CIPP is not configured.');

            return self::FAILURE;
        }

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

        $service = new CippTenantSecuritySyncService($client);
        $result = $service->syncAll();

        $this->info('Done: '.$result->summary());

        if ($result->errors > 0) {
            foreach ($result->errorMessages as $msg) {
                $this->warn("  - {$msg}");
            }
        }

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
