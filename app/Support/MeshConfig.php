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

    /**
     * #6162: true only for a key the Mesh clients would send past their
     * own key checks: isSendableKey(), the same predicate
     * MeshWriteClient::isConfigured() uses and the same two sets
     * MeshClient::apiKeyRefusal() refuses before send. So a blank key or
     * true is no longer configured here while every client refuses it.
     * Why a key is not usable is apiKeyRefusal()'s text, which tells a
     * missing key from a stored one the PSA does not send (#6161).
     */
    public static function isConfigured(): bool
    {
        return self::isSendableKey(self::get('api_key'));
    }

    /** Neither apiKeyMissing() nor apiKeyUnusable(). The header rule is the clients' own. */
    public static function isSendableKey(mixed $key): bool
    {
        return ! self::apiKeyMissing($key) && ! self::apiKeyUnusable($key);
    }

    /**
     * #6161, #6162: the one definition of a MISSING key: no value at all
     * (null or false), '' or only spaces and tabs (which PSR-7 trims to
     * ''). Only these are reported as 'not configured'.
     */
    public static function apiKeyMissing(mixed $key): bool
    {
        return $key === null || $key === false || (is_string($key) && trim($key, " \t") === '');
    }

    /**
     * #6161: a value IS stored, but the PSA does not send it as a key:
     * '0', 0 or 0.0 (which the old empty() test counted as missing) and
     * true (which would be sent as '1'). Reported as set but unusable,
     * never as 'not configured'.
     */
    public static function apiKeyUnusable(mixed $key): bool
    {
        return $key === true || $key === '0' || $key === 0 || $key === 0.0;
    }
}
