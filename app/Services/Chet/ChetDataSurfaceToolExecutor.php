<?php

namespace App\Services\Chet;

use App\Services\Huntress\HuntressReadOnlyToolset;
use App\Services\Tactical\TacticalReadOnlyToolset;
use App\Services\Unifi\UnifiReadOnlyToolset;

class ChetDataSurfaceToolExecutor
{
    public function execute(string $toolName, array $input, ?int $clientId): mixed
    {
        if (TacticalReadOnlyToolset::handles($toolName)) {
            if (TacticalReadOnlyToolset::requiresClient($toolName) && $clientId === null) {
                return ['error' => 'client_id is required for '.$toolName.'.'];
            }

            return app(TacticalReadOnlyToolset::class)->execute($toolName, $input, $clientId);
        }

        if (HuntressReadOnlyToolset::handles($toolName)) {
            return app(HuntressReadOnlyToolset::class)->execute($toolName, $input, $clientId);
        }

        if (UnifiReadOnlyToolset::handles($toolName)) {
            if (UnifiReadOnlyToolset::requiresClient($toolName) && $clientId === null) {
                return ['error' => 'client_id is required for '.$toolName.'.'];
            }

            return app(UnifiReadOnlyToolset::class)->execute($toolName, $input, $clientId);
        }

        if (TeamsChatReadToolset::handles($toolName)) {
            return app(TeamsChatReadToolset::class)->execute($toolName, $input);
        }

        return ['error' => "Unknown tool: {$toolName}"];
    }
}
