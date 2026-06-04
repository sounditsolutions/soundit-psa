<?php

namespace App\Support;

use App\Models\Setting;

class LevelConfig
{
    public static function isEnabled(): bool
    {
        return Setting::getValue('level_enabled', '1') === '1';
    }

    public static function isConfigured(): bool
    {
        return ! empty(self::get('api_key'));
    }

    private static array $map = [
        'api_key' => ['level_api_key', 'services.level.api_key', true],
        'base_url' => ['level_base_url', 'services.level.base_url', false],
        'webhook_secret' => ['level_webhook_secret', 'services.level.webhook_secret', true],
        'install_account_token' => ['level_install_account_token', 'services.level.install_account_token', true],
    ];

    public static function get(string $key): ?string
    {
        if (! isset(self::$map[$key])) {
            return config("services.level.{$key}");
        }

        [$settingKey, $configKey, $encrypted] = self::$map[$key];

        return Setting::settingOrConfig($settingKey, $configKey, $encrypted);
    }
}
