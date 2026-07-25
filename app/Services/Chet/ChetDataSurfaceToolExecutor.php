<?php

namespace App\Services\Chet;

use App\Services\Comet\CometReadOnlyToolset;
use App\Services\Huntress\HuntressReadOnlyToolset;
use App\Services\ScreenConnect\ScreenConnectReadOnlyToolset;
use App\Services\Servosity\ServosityReadOnlyToolset;
use App\Services\Tactical\TacticalReadOnlyToolset;
use App\Services\Unifi\UnifiReadOnlyToolset;
use App\Services\Zorus\ZorusReadOnlyToolset;

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

        if (ScreenConnectReadOnlyToolset::handles($toolName)) {
            if (ScreenConnectReadOnlyToolset::requiresClient($toolName) && $clientId === null) {
                return ['error' => 'client_id is required for '.$toolName.'.'];
            }

            return app(ScreenConnectReadOnlyToolset::class)->execute($toolName, $input, $clientId);
        }

        if (ZorusReadOnlyToolset::handles($toolName)) {
            if (ZorusReadOnlyToolset::requiresClient($toolName) && $clientId === null) {
                return ['error' => 'client_id is required for '.$toolName.'.'];
            }

            return app(ZorusReadOnlyToolset::class)->execute($toolName, $input, $clientId);
        }

        if (CometReadOnlyToolset::handles($toolName)) {
            if (CometReadOnlyToolset::requiresClient($toolName) && $clientId === null) {
                return ['error' => 'client_id is required for '.$toolName.'.'];
            }

            return app(CometReadOnlyToolset::class)->execute($toolName, $input, $clientId);
        }

        if (ServosityReadOnlyToolset::handles($toolName)) {
            if (ServosityReadOnlyToolset::requiresClient($toolName) && $clientId === null) {
                return ['error' => 'client_id is required for '.$toolName.'.'];
            }

            return app(ServosityReadOnlyToolset::class)->execute($toolName, $input, $clientId);
        }

        if (TeamsChatReadToolset::handles($toolName)) {
            return app(TeamsChatReadToolset::class)->execute($toolName, $input);
        }

        return ['error' => "Unknown tool: {$toolName}"];
    }
}
