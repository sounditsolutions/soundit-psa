<?php

namespace App\Support;

use App\Models\Setting;
use App\Services\Mesh\MeshClient;

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
     * #6162: isSendableKey() of the stored key, the same predicate
     * MeshWriteClient::isConfigured() uses. False for a missing key and
     * for a stored '0' (get() returns a string or null, so only those
     * reach this method). Not the whole of what the clients refuse
     * before send (#6207): the header rule (MeshClient::headerValueRefusal())
     * is the clients' own, so a stored key holding a CR or LF is
     * configured here and every client then refuses it before send.
     * Why a key is not usable is MeshClient::apiKeyRefusal()'s text,
     * which tells a missing key from a stored one the PSA does not send
     * (#6161).
     */
    public static function isConfigured(): bool
    {
        return self::isSendableKey(self::get('api_key'));
    }

    /**
     * #6213: a key IS stored, but isConfigured() is false because the PSA
     * does not send it (a stored '0'). Operator texts use this to say the
     * key is set but unusable instead of 'not configured'.
     */
    public static function isKeyStoredButUnusable(): bool
    {
        $key = self::get('api_key');

        return ! self::isSendableKey($key) && ! self::apiKeyMissing($key);
    }

    /**
     * #6213: the operator text for isConfigured() being false: $missing
     * when no key is stored (null, '' or blanks), else that the stored key
     * is set but unusable, with MeshClient::UNUSABLE_KEY's wording.
     */
    public static function notConfiguredText(string $missing): string
    {
        return self::isKeyStoredButUnusable()
            ? 'The Mesh API key '.MeshClient::UNUSABLE_KEY.'. Replace it in Settings → Integrations.'
            : $missing;
    }

    /**
     * A scalar that is neither apiKeyMissing() nor apiKeyUnusable(). #6208:
     * an array (even []) or an object is not sendable, as the old empty()
     * test also refused []. The rest of the header rule is the clients'
     * own (see isConfigured()).
     */
    public static function isSendableKey(mixed $key): bool
    {
        return is_scalar($key) && ! self::apiKeyMissing($key) && ! self::apiKeyUnusable($key);
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
     * true (which would be sent as '1'). A string is trimmed of spaces
     * and tabs first, as apiKeyMissing() trims it, because PSR-7 trims
     * them from a header value: ' 0' or "0\t" would go out as '0'.
     * Reported as set but unusable, never as 'not configured'.
     */
    public static function apiKeyUnusable(mixed $key): bool
    {
        return $key === true || $key === 0 || $key === 0.0 || (is_string($key) && trim($key, " \t") === '0');
    }
}
