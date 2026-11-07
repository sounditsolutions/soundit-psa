<?php

namespace App\Services\Mcp;

use App\Models\Client;
use App\Services\Cipp\HandlesCippTools;

/** REST-only dispatch behind the staff MCP controller's explicit-grant gate. */
class CippCompareToolExecutor
{
    use HandlesCippTools;

    public function __construct(protected ?int $clientId) {}

    public function execute(array $input): array
    {
        return $this->cippCompareUserAccess($input);
    }

    protected function cippScopeClient(): ?Client
    {
        return $this->clientId === null ? null : Client::find($this->clientId);
    }

    protected function cippLogPrefix(): string
    {
        return '[MCP]';
    }
}
