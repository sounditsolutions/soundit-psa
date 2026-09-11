<?php

namespace App\Support;

use App\Models\Setting;

class ServosityConfig
{
    // Tactical RMM custom field IDs for Servosity deployment
    public const TACTICAL_SERVOSITY_ONE_URL_FIELD_ID = 31;

    public const TACTICAL_SERVOSITY_SC_URL_FIELD_ID = 32;

    public const TACTICAL_SERVOSITY_CRED_USER_FIELD_ID = 33;

    public const TACTICAL_SERVOSITY_CRED_PASS_FIELD_ID = 34;

    public static function get(string $key): ?string
    {
        return match ($key) {
            'api_token' => Setting::getEncrypted('servosity_api_token'),
            'totp_secret' => Setting::getEncrypted('servosity_totp_secret'),
            'totp_enrollment_id' => Setting::getValue('servosity_totp_enrollment_id'),
            'base_url' => Setting::getValue('servosity_base_url', 'https://api.servosity.com'),
            'connected_at' => Setting::getValue('servosity_connected_at'),
            'credential_username' => Setting::getValue('servosity_credential_username', 'PSA Backup'),
            default => null,
        };
    }

    public static function isEnabled(): bool
    {
        return Setting::getValue('servosity_enabled', '1') === '1';
    }

    public static function isConfigured(): bool
    {
        return ! empty(self::get('api_token'));
    }

    /**
     * The OFF=OFF predicate the MCP tool surface gates on (mirrors
     * ZorusConfig::isAvailable): a switched-off integration must not keep
     * answering, and an unconfigured one cannot.
     */
    public static function isAvailable(): bool
    {
        return self::isEnabled() && self::isConfigured();
    }

    /**
     * Generate a TOTP code from the stored secret (RFC 6238).
     * Returns null if no TOTP secret is configured.
     *
     * The algorithm moved to {@see Totp} when the HDB report portal became the
     * second caller (psa #340). Behaviour here is unchanged — same decoder, same
     * 30-second SHA1 step, same 6-digit zero-padded truncation.
     */
    public static function generateTotp(): ?string
    {
        return Totp::code(self::get('totp_secret'));
    }
}
