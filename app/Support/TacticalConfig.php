<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Str;

class TacticalConfig
{
    public static function generateWebhookKey(): string
    {
        return Str::random(48);
    }

    public static function get(string $key): ?string
    {
        return match ($key) {
            'api_key' => Setting::getEncrypted('tactical_api_key'),
            'webhook_key' => Setting::getEncrypted('tactical_webhook_key'),
            'api_url' => Setting::getValue('tactical_api_url'),
            default => Setting::getValue("tactical_{$key}"),
        };
    }

    public static function apiUrl(): ?string
    {
        return self::get('api_url');
    }

    public static function isConfigured(): bool
    {
        return !empty(self::get('api_url')) && !empty(self::get('api_key'));
    }

    public static function isEnabled(): bool
    {
        return self::isConfigured();
    }

    /**
     * Minimum alert severity to create tickets.
     * Options: 'error', 'warning', 'info'
     * Alerts below this threshold are silently ignored.
     */
    public static function alertMinSeverity(): string
    {
        return self::get('alert_min_severity') ?: 'warning';
    }
}
