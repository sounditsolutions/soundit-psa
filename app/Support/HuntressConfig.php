<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

class HuntressConfig
{
    public static function get(string $key): ?string
    {
        return match ($key) {
            'api_key' => Setting::getEncrypted('huntress_api_key'),
            'api_secret' => Setting::getEncrypted('huntress_api_secret'),
            'cw_api_key' => Setting::getEncrypted('huntress_cw_api_key'),
            'system_user_id' => Setting::getValue('huntress_system_user_id'),
            default => null,
        };
    }

    public static function isEnabled(): bool
    {
        return Setting::getValue('huntress_enabled', '1') === '1';
    }

    /**
     * Phase 1: API sync is configured (key + secret for Huntress API).
     */
    public static function isConfigured(): bool
    {
        return ! empty(self::get('api_key'))
            && ! empty(self::get('api_secret'));
    }

    /**
     * Phase 2: CW Compat shim is configured (separate key for inbound ticket push).
     */
    public static function isCwCompatConfigured(): bool
    {
        return ! empty(self::get('cw_api_key'));
    }

    /**
     * Get the user ID for audit trail attribution on Huntress-created tickets.
     * Falls back to the first user if not configured.
     */
    public static function systemUserId(): ?int
    {
        $configured = self::get('system_user_id');

        if ($configured) {
            return (int) $configured;
        }

        return User::orderBy('id')->value('id');
    }

    /**
     * Generate CW-format credentials for Huntress's ConnectWise Manage integration.
     *
     * Returns all four values needed for the Huntress CW form:
     * - host: Our CW compat base URL
     * - company_id: Arbitrary (not validated)
     * - public_key: Arbitrary (not validated)
     * - private_key: The secret we store and validate
     *
     * @return array{host: string, company_id: string, public_key: string, private_key: string}
     */
    public static function generateApiKey(): array
    {
        return [
            'host' => preg_replace('#^https?://#', '', rtrim(config('app.url'), '/').'/api/huntress'),
            'company_id' => 'SoundPSA',
            'public_key' => bin2hex(random_bytes(8)),
            'private_key' => bin2hex(random_bytes(16)),
        ];
    }
}
