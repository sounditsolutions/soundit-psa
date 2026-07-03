<?php

namespace App\Services\Cipp;

final readonly class CippMcpCatalogSyncResult
{
    public function __construct(
        public int $seen,
        public int $active,
        public int $created,
        public int $updated,
        public int $deactivated,
    ) {}

    public function summary(): string
    {
        return "CIPP MCP catalog sync complete: {$this->seen} seen, {$this->active} active, {$this->created} created, {$this->updated} updated, {$this->deactivated} deactivated.";
    }
}
