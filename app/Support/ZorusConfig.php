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

    /**
     * Both the switch and the credentials — the predicate the AI tool surface
     * gates on (OFF=OFF, psa-wzjzz): switching Zorus off withdraws its tools,
     * it does not merely stop the syncs.
     */
    public static function isAvailable(): bool
    {
        return self::isEnabled() && self::isConfigured();
    }
}
