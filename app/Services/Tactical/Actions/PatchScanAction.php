<?php

namespace App\Services\Tactical\Actions;

use App\Services\Tactical\TacticalClient;

class PatchScanAction implements TacticalAction
{
    public function key(): string
    {
        return 'tactical.patch_scan';
    }

    public function isDestructive(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $params */
    public function validateParams(array $params): array
    {
        return [];
    }

    /** @param array<string, mixed> $params */
    public function summary(array $params): string
    {
        return 'Start a Windows update scan';
    }

    /** @param array<string, mixed> $params */
    public function execute(TacticalClient $client, string $agentId, array $params): TacticalActionResult
    {
        $result = $client->scanPatches($agentId);

        return TacticalActionResult::ok(is_scalar($result) ? (string) $result : 'Windows update scan queued.');
    }
}
