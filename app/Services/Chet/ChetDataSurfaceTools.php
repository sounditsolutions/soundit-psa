<?php

namespace App\Services\Chet;

use App\Services\Huntress\HuntressReadOnlyToolset;
use App\Services\Tactical\TacticalReadOnlyToolset;
use App\Services\Unifi\UnifiReadOnlyToolset;
use App\Support\HuntressConfig;
use App\Support\TacticalConfig;
use App\Support\TeamsBotConfig;
use App\Support\UnifiConfig;

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

        // UniFi network telemetry ships dormant, and OFF=OFF: isAvailable() is the
        // master switch AND the key, so switching UniFi off withdraws these tools.
        if (UnifiConfig::isAvailable()) {
            $tools = array_merge($tools, UnifiReadOnlyToolset::generalDefinitions());
        }

        return $tools;
    }

    /** @return array<int, array<string, mixed>> */
    public static function clientTools(): array
    {
        $tools = TacticalConfig::isConfigured()
            ? TacticalReadOnlyToolset::clientDefinitions()
            : [];

        if (UnifiConfig::isAvailable()) {
            $tools = array_merge($tools, UnifiReadOnlyToolset::clientDefinitions());
        }

        return $tools;
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
            UnifiReadOnlyToolset::definitions(),
        );
    }

    public static function handles(string $toolName): bool
    {
        return TeamsChatReadToolset::handles($toolName)
            || TacticalReadOnlyToolset::handles($toolName)
            || HuntressReadOnlyToolset::handles($toolName)
            || UnifiReadOnlyToolset::handles($toolName);
    }

    public static function requiresClient(string $toolName): bool
    {
        return TacticalReadOnlyToolset::requiresClient($toolName)
            || HuntressReadOnlyToolset::requiresClient($toolName)
            || UnifiReadOnlyToolset::requiresClient($toolName);
    }
}
