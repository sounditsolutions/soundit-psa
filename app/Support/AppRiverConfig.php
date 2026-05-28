<?php

namespace App\Support;

use App\Models\Setting;

class AppRiverConfig
{
    public static function get(string $key): ?string
    {
        return match ($key) {
            'client_id' => Setting::getEncrypted('appriver_client_id'),
            'client_secret' => Setting::getEncrypted('appriver_client_secret'),
            'base_url' => Setting::getValue('appriver_base_url', 'https://unityapi.webrootcloudav.com'),
            'connected_at' => Setting::getValue('appriver_connected_at'),
            default => null,
        };
    }

    public static function isEnabled(): bool
    {
        return Setting::getValue('appriver_enabled', '1') === '1';
    }

    public static function isConfigured(): bool
    {
        return ! empty(self::get('client_id')) && ! empty(self::get('client_secret'));
    }
}
