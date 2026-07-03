<?php

namespace App\Services\Tactical\Actions;

use App\Services\Tactical\TacticalClient;

class PatchInstallAction implements TacticalAction
{
    public function key(): string
    {
        return 'tactical.patch_install';
    }

    public function isDestructive(): bool
    {
        return true;
    }

    /** @param array<string, mixed> $params */
    public function validateParams(array $params): array
    {
        return [];
    }

    /** @param array<string, mixed> $params */
    public function summary(array $params): string
    {
        return 'Install approved Windows patches';
    }

    /** @param array<string, mixed> $params */
    public function execute(TacticalClient $client, string $agentId, array $params): TacticalActionResult
    {
        $result = $client->installApprovedPatches($agentId);

        return TacticalActionResult::ok(is_scalar($result) ? (string) $result : 'Approved Windows patches queued for install.');
    }

    /** @param array<string, mixed> $params */
    public function payloadHash(array $params): string
    {
        return hash('sha256', 'install-approved-patches');
    }
}
