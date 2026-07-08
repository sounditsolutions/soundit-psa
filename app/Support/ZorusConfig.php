<?php

namespace App\Support;

use App\Models\Setting;

class ZorusConfig
{
    public static function get(string $key): ?string
    {
        return match ($key) {
            'api_key' => Setting::getEncrypted('zorus_api_key'),
            default => null,
        };
    }

    public static function isEnabled(): bool
    {
        return Setting::getValue('zorus_enabled', '1') === '1';
    }

    public static function isConfigured(): bool
    {
        return ! empty(self::get('api_key'));
    }
}
