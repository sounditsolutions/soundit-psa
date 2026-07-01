<?php

namespace App\Services\Chet;

use App\Services\Tactical\TacticalReadOnlyToolset;
use App\Support\TacticalConfig;
use App\Support\TeamsBotConfig;

class ChetDataSurfaceTools
{
    /** @return array<int, array<string, mixed>> */
    public static function generalTools(): array
    {
        return TeamsBotConfig::appId() !== null
            ? TeamsChatReadToolset::definitions()
            : [];
    }

    /** @return array<int, array<string, mixed>> */
    public static function clientTools(): array
    {
        return TacticalConfig::isConfigured()
            ? TacticalReadOnlyToolset::definitions()
            : [];
    }

    /** @return array<int, array<string, mixed>> */
    public static function registryGeneralTools(): array
    {
        return TeamsChatReadToolset::definitions();
    }

    /** @return array<int, array<string, mixed>> */
    public static function registryIntegrationTools(): array
    {
        return TacticalReadOnlyToolset::definitions();
    }

    public static function handles(string $toolName): bool
    {
        return TeamsChatReadToolset::handles($toolName)
            || TacticalReadOnlyToolset::handles($toolName);
    }

    public static function requiresClient(string $toolName): bool
    {
        return TacticalReadOnlyToolset::handles($toolName);
    }
}
