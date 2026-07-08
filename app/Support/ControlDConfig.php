<?php

namespace App\Support;

use App\Models\Setting;

class ControlDConfig
{
    public static function get(string $key): ?string
    {
        return match ($key) {
            'api_key' => Setting::getEncrypted('controld_api_key'),
            'stats_endpoint' => Setting::getValue('controld_stats_endpoint'),
            default => null,
        };
    }

    public static function isEnabled(): bool
    {
        return Setting::getValue('controld_enabled', '1') === '1';
    }

    public static function isConfigured(): bool
    {
        return ! empty(self::get('api_key'));
    }

    /**
     * Analytics is available when the main API key is set and we have a stats endpoint.
     * The stats endpoint is auto-detected from the org API and cached in settings.
     */
    public static function isAnalyticsConfigured(): bool
    {
        return self::isConfigured() && ! empty(self::get('stats_endpoint'));
    }
}
