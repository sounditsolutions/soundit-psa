<?php

namespace App\Support;

use App\Models\Setting;

/**
 * PowerDMARC integration config (issue #689).
 *
 * Follows the house static-helper pattern (UnifiConfig / HuntressConfig): all
 * tenant config lives in the settings table, only the API key is encrypted.
 *
 * WHICH API: the PowerDMARC platform API (https://app.powerdmarc.com) — one
 * Bearer API key for the whole PowerDMARC account, covering every domain that
 * account manages. Read-only usage: domain status, aggregate (RUA) reports and
 * the DNS timeline; hosted-record writes are deliberately out of scope.
 */
class PowerDmarcConfig
{
    public const DEFAULT_BASE_URL = 'https://app.powerdmarc.com';

    public static function get(string $key): ?string
    {
        return match ($key) {
            'api_key' => Setting::getEncrypted('powerdmarc_api_key'),
            'base_url' => Setting::getValue('powerdmarc_base_url', self::DEFAULT_BASE_URL),
            default => null,
        };
    }

    /**
     * Master switch. OFF=OFF — per the CLAUDE.md rule this gates the AI tool surface
     * too, not just syncs, so a deployment that switches PowerDMARC off stops
     * publishing powerdmarc_* tools to the model.
     *
     * Defaults to '0': a net-new integration ships dormant.
     */
    public static function isEnabled(): bool
    {
        return Setting::getValue('powerdmarc_enabled', '0') === '1';
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
