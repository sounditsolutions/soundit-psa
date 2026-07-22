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
            'mcp_client_id' => Setting::getValue('cipp_mcp_client_id'),
            'mcp_client_secret' => Setting::getEncrypted('cipp_mcp_client_secret'),
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

    /**
     * psa-wzjzz: this was the ONLY member of this family that did not consult the CIPP
     * master switch — isContactSyncEnabled(), isDeviceSyncEnabled() and
     * isMcpCatalogSyncEnabled() all open with self::isEnabled(), and this one did not.
     * The omission let the dynamic CIPP MCP relay keep executing with cipp_enabled='0',
     * so the executor could not act as defence in depth behind the publication gate.
     * `cipp_mcp_enabled` is a sub-switch of the integration, not an alternative to it.
     */
    public static function isMcpRelayEnabled(): bool
    {
        return self::isEnabled()
            && Setting::getValue('cipp_mcp_enabled', '0') === '1'
            && self::isMcpConfigured();
    }

    public static function isMcpCatalogSyncEnabled(): bool
    {
        return self::isEnabled()
            && self::isMcpConfigured()
            && Setting::getValue('cipp_mcp_catalog_sync_enabled', '0') === '1';
    }

    public static function isMcpConfigured(): bool
    {
        return ! empty(self::get('api_url'))
            && ! empty(self::get('tenant_id'))
            && ! empty(self::get('mcp_client_id'))
            && ! empty(self::get('mcp_client_secret'));
    }
}
