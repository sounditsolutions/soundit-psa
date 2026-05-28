<?php

namespace App\Support;

use App\Models\Setting;

class CippConfig
{
    public static function get(string $key): ?string
    {
        return match ($key) {
            'api_url' => Setting::getValue('cipp_api_url'),
            'tenant_id' => Setting::getValue('cipp_tenant_id'),
            'client_id' => Setting::getValue('cipp_client_id'),
            'client_secret' => Setting::getEncrypted('cipp_client_secret'),
            'application_id' => Setting::getValue('cipp_application_id'),
            default => null,
        };
    }

    public static function isEnabled(): bool
    {
        return Setting::getValue('cipp_enabled', '1') === '1';
    }

    public static function isConfigured(): bool
    {
        return ! empty(self::get('api_url'))
            && ! empty(self::get('tenant_id'))
            && ! empty(self::get('client_id'))
            && ! empty(self::get('client_secret'));
    }

    public static function isContactSyncEnabled(): bool
    {
        return self::isEnabled()
            && self::isConfigured()
            && Setting::getValue('cipp_contact_sync_enabled', '0') === '1';
    }

    public static function isDeviceSyncEnabled(): bool
    {
        return self::isEnabled()
            && self::isConfigured()
            && Setting::getValue('cipp_device_sync_enabled', '0') === '1';
    }
}
