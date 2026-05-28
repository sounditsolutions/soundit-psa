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
     * Generate a TOTP code from the stored secret (RFC 6238).
     * Returns null if no TOTP secret is configured.
     */
    public static function generateTotp(): ?string
    {
        $secret = self::get('totp_secret');
        if (! $secret) {
            return null;
        }

        // Decode base32 secret
        $decoded = self::base32Decode($secret);
        if (! $decoded) {
            return null;
        }

        // Standard TOTP: 30-second period, 6-digit code, SHA1
        $timeCounter = pack('N*', 0, (int) floor(time() / 30));
        $hash = hash_hmac('sha1', $timeCounter, $decoded, true);

        $offset = ord($hash[19]) & 0x0f;
        $code = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % 1000000;

        return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Decode a base32-encoded string.
     */
    private static function base32Decode(string $input): ?string
    {
        $input = strtoupper(rtrim($input, '='));
        $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

        $buffer = 0;
        $bitsLeft = 0;
        $result = '';

        for ($i = 0, $len = strlen($input); $i < $len; $i++) {
            $val = strpos($map, $input[$i]);
            if ($val === false) {
                return null;
            }
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $result .= chr(($buffer >> $bitsLeft) & 0xff);
            }
        }

        return $result;
    }
}
