<?php

namespace App\Support;

use App\Models\Setting;

/**
 * UniFi Site Manager integration config (psa-1ynqc).
 *
 * Follows the house static-helper pattern (HuntressConfig / ControlDConfig): all
 * tenant config lives in the settings table, only the key is encrypted.
 *
 * WHICH API: the Site Manager CLOUD API (https://api.ui.com) — one API key for the
 * whole UI account, covering every console/site that account administers. This is
 * deliberately NOT the per-controller local Network API: an MSP would need network
 * reachability into each client site for that, and the local API cannot serve
 * cross-site ISP metrics at all.
 */
class UnifiConfig
{
    public const DEFAULT_BASE_URL = 'https://api.ui.com';

    public static function get(string $key): ?string
    {
        return match ($key) {
            'api_key' => Setting::getEncrypted('unifi_api_key'),
            'base_url' => Setting::getValue('unifi_base_url', self::DEFAULT_BASE_URL),
            default => null,
        };
    }

    /**
     * Master switch. OFF=OFF — per the CLAUDE.md rule this gates the AI tool surface
     * too, not just syncs, so a deployment that switches UniFi off stops publishing
     * unifi_* tools to the model.
     *
     * Defaults to '0': a net-new integration ships dormant.
     */
    public static function isEnabled(): bool
    {
        return Setting::getValue('unifi_enabled', '0') === '1';
    }

    public static function isConfigured(): bool
    {
        return ! empty(self::get('api_key'));
    }

    /** Both the switch and the credentials — the predicate the tool surface gates on. */
    public static function isAvailable(): bool
    {
        return self::isEnabled() && self::isConfigured();
    }

    public static function baseUrl(): string
    {
        $configured = trim((string) self::get('base_url'));

        return rtrim($configured !== '' ? $configured : self::DEFAULT_BASE_URL, '/');
    }
}
