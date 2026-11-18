<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

class HuntressConfig
{
    /**
     * Where an operator enters the Huntress API key pair: the "API key" and
     * "API secret" inputs in the Huntress card on the Integrations page. Reads
     * and escalation resolution use that one pair (resolve uses an earlier
     * user pair instead while both of its halves are still stored; see
     * writeCredentials()). One constant so every remedy names the real field.
     */
    public const WRITE_CREDENTIAL_LOCATION = 'Settings > Integrations > Huntress EDR / ITDR > API key and API secret';

    public static function get(string $key): ?string
    {
        return match ($key) {
            'api_key' => Setting::getEncrypted('huntress_api_key'),
            'api_secret' => Setting::getEncrypted('huntress_api_secret'),
            'cw_api_key' => Setting::getEncrypted('huntress_cw_api_key'),
            'webhook_signing_secret' => Setting::getEncrypted('huntress_webhook_signing_secret'),
            'webhook_account_id' => Setting::getValue('huntress_webhook_account_id'),
            'user_api_key' => Setting::getEncrypted('huntress_user_api_key'),
            'user_api_secret' => Setting::getEncrypted('huntress_user_api_secret'),
            'system_user_id' => Setting::getValue('huntress_system_user_id'),
            default => null,
        };
    }

    public static function webhooksEnabled(): bool
    {
        return Setting::getValue('huntress_webhooks_enabled', '0') === '1';
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
     * True while BOTH halves of the earlier, separate user API key pair are
     * still stored. That pair predates the card's move to one key pair; it is
     * no longer entered anywhere, only kept until an administrator clears it.
     * A lone half is not a pair.
     */
    public static function hasEarlierUserPair(): bool
    {
        return ! empty(self::get('user_api_key'))
            && ! empty(self::get('user_api_secret'));
    }

    /**
     * True when either half of the earlier user pair is still stored (raw
     * row present, no decrypt). Drives the card's notice and clear control,
     * so a lone half can still be cleared.
     */
    public static function hasEarlierUserPairResidue(): bool
    {
        return filled(Setting::getValue('huntress_user_api_key'))
            || filled(Setting::getValue('huntress_user_api_secret'));
    }

    /**
     * The pair escalation resolution signs with, derived from what is stored
     * (nothing else decides it): the earlier user pair while both of its
     * halves are stored, otherwise the main API key pair. Who may call
     * resolve at all is the per-action grant on the MCP token.
     *
     * @return array{api_key: ?string, api_secret: ?string}
     */
    public static function writeCredentials(): array
    {
        if (self::hasEarlierUserPair()) {
            return [
                'api_key' => self::get('user_api_key'),
                'api_secret' => self::get('user_api_secret'),
            ];
        }

        return [
            'api_key' => self::get('api_key'),
            'api_secret' => self::get('api_secret'),
        ];
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
