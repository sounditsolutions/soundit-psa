<?php

namespace App\Console\Commands;

use App\Services\Carbon\CarbonMcpCatalogSyncService;
use App\Services\Carbon\CarbonMcpConnector;
use Illuminate\Console\Command;

/** Sync the Carbon Connect MCP tool catalogue (card PEYvh4NL); the cipp:sync-mcp-catalog pattern. */
class CarbonSyncMcpCatalog extends Command
{
    protected $signature = 'carbon:sync-mcp-catalog';

    protected $description = 'Sync the Carbon Connect MCP tool catalogue into PSA grant metadata';

    public function handle(CarbonMcpCatalogSyncService $service, CarbonMcpConnector $connector): int
    {
        if (! $connector->isConnected()) {
            $this->error('Carbon MCP is not connected; an admin signs in at Settings > Integrations > Carbon Connect first.');

            return self::FAILURE;
        }

        try {
            $result = $service->sync();
        } catch (\Throwable $e) {
            // Every exception the Carbon classes throw is PSA-authored (status / code / class).
            $this->error('Carbon MCP catalogue sync failed: '.($e instanceof \App\Services\Carbon\CarbonMcpException ? $e->getMessage() : class_basename($e)));

            return self::FAILURE;
        }

        $this->info(CarbonMcpCatalogSyncService::summary($result));

        return self::SUCCESS;
    }
}
