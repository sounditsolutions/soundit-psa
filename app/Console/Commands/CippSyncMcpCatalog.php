<?php

namespace App\Console\Commands;

use App\Services\Cipp\CippMcpCatalogSyncService;
use Illuminate\Console\Command;

class CippSyncMcpCatalog extends Command
{
    protected $signature = 'cipp:sync-mcp-catalog';

    protected $description = 'Sync the dynamic CIPP MCP tool catalog into PSA grant metadata';

    public function handle(CippMcpCatalogSyncService $service): int
    {
        try {
            $result = $service->sync();
        } catch (\Throwable $e) {
            $this->error("CIPP MCP catalog sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info($result->summary());

        return self::SUCCESS;
    }
}
