<?php

namespace App\Services\Chet;

use App\Services\Huntress\HuntressReadOnlyToolset;
use App\Services\Tactical\TacticalReadOnlyToolset;
use App\Support\HuntressConfig;
use App\Support\TacticalConfig;
use App\Support\TeamsBotConfig;

class ChetDataSurfaceTools
{
    /** @return array<int, array<string, mixed>> */
    public static function generalTools(): array
    {
        $tools = TeamsBotConfig::appId() !== null
            ? TeamsChatReadToolset::definitions()
            : [];

        if (TacticalConfig::isConfigured()) {
            $tools = array_merge($tools, TacticalReadOnlyToolset::generalDefinitions());
        }

        // Huntress P1 reads ship dormant — live only once the read-only account key is set.
        if (HuntressConfig::isConfigured()) {
            $tools = array_merge($tools, HuntressReadOnlyToolset::generalDefinitions());
        }

        return $tools;
    }

    /** @return array<int, array<string, mixed>> */
    public static function clientTools(): array
    {
        return TacticalConfig::isConfigured()
            ? TacticalReadOnlyToolset::clientDefinitions()
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
        // Ungated so operators can pre-grant Huntress reads before the key is set
        // (the live surface in generalTools() is what gates on configuration).
        return array_merge(
            TacticalReadOnlyToolset::definitions(),
            HuntressReadOnlyToolset::definitions(),
        );
    }

    public static function handles(string $toolName): bool
    {
        return TeamsChatReadToolset::handles($toolName)
            || TacticalReadOnlyToolset::handles($toolName)
            || HuntressReadOnlyToolset::handles($toolName);
    }

    public static function requiresClient(string $toolName): bool
    {
        return TacticalReadOnlyToolset::requiresClient($toolName)
            || HuntressReadOnlyToolset::requiresClient($toolName);
    }
}
