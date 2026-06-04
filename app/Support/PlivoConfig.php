<?php

namespace App\Support;

use App\Models\Setting;

class PlivoConfig
{
    public static function isEnabled(): bool
    {
        return Setting::getValue('plivo_enabled', '1') === '1';
    }

    public static function isConfigured(): bool
    {
        return ! empty(self::get('auth_id')) && ! empty(self::get('auth_token'));
    }

    private static array $map = [
        'auth_id' => ['plivo_auth_id', 'services.plivo.auth_id', false],
        'auth_token' => ['plivo_auth_token', 'services.plivo.auth_token', true],
        'webhook_secret' => ['plivo_webhook_secret', 'services.plivo.webhook_secret', true],
        'did_number' => ['plivo_did_number', 'services.plivo.did_number', false],
        'app_id' => ['plivo_app_id', 'services.plivo.app_id', false],
    ];

    public static function get(string $key): ?string
    {
        if (! isset(self::$map[$key])) {
            return config("services.plivo.{$key}");
        }

        [$settingKey, $configKey, $encrypted] = self::$map[$key];

        return Setting::settingOrConfig($settingKey, $configKey, $encrypted);
    }
}
