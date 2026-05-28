<?php

namespace App\Support;

use App\Models\Setting;

class MeshConfig
{
    public static function get(string $key): ?string
    {
        return match ($key) {
            'api_key' => Setting::getEncrypted('mesh_api_key'),
            'base_url' => Setting::getValue('mesh_base_url', 'https://hub-us.emailsecurity.app'),
            default => null,
        };
    }

    public static function isEnabled(): bool
    {
        return Setting::getValue('mesh_enabled', '1') === '1';
    }

    public static function isConfigured(): bool
    {
        return ! empty(self::get('api_key'));
    }
}
