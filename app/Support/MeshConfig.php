<?php

namespace App\Support;

use App\Models\Setting;
use App\Services\Mesh\MeshClient;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;

class MeshConfig
{
    /**
     * #6296: the end of 'the Mesh API key …' for a stored row that does
     * not decrypt (an APP_KEY rotation, a row copied from another
     * environment).
     */
    public const UNDECRYPTABLE_KEY = 'is stored but could not be decrypted';

    /**
     * #6296: get('api_key') is null for a stored row that does not
     * decrypt, so no caller can send it, and logs a status-only warning
     * (the exception class only: never the row, the ciphertext or the
     * exception's message). isKeyStoredButUnusable() reports that row as
     * set but unusable; hasStoredKey() reads whether a row exists without
     * decrypting it.
     */
    public static function get(string $key): ?string
    {
        return match ($key) {
            'api_key' => self::readKey()[0],
            'base_url' => Setting::getValue('mesh_base_url', 'https://hub-us.emailsecurity.app'),
            default => null,
        };
    }

    /**
     * #6296: whether a mesh_api_key row is stored at all, read without
     * decrypting it (the reaper schedule gate, routes/console.php).
     */
    public static function hasStoredKey(): bool
    {
        return Setting::getValue('mesh_api_key') !== null;
    }

    /**
     * The stored key and whether its row failed to decrypt.
     *
     * @return array{0: string|null, 1: bool}
     */
    private static function readKey(): array
    {
        try {
            return [Setting::getEncrypted('mesh_api_key'), false];
        } catch (DecryptException $e) {
            Log::warning('[MeshConfig] The stored Mesh API key could not be decrypted ('.$e::class.'); it is treated as unusable and no Mesh call is made with it.');

            return [null, true];
        }
    }

    /**
     * Why a STORED key is not used, as the end of 'the Mesh API key …', or
     * null when no key is stored (missing) or the stored key is usable.
     * #6161: '0', 0, 0.0 or true is MeshClient::UNUSABLE_KEY. #6288: a key
     * an HTTP header cannot carry (a CR or LF) is the header rule's text,
     * the same rule every client applies before send. #6296: a row that
     * does not decrypt is UNDECRYPTABLE_KEY.
     */
    public static function storedKeyRefusal(): ?string
    {
        [$key, $undecryptable] = self::readKey();
        if ($undecryptable) {
            return self::UNDECRYPTABLE_KEY;
        }
        if (self::apiKeyMissing($key)) {
            return null;
        }
        if (! self::isSendableKey($key)) {
            return MeshClient::apiKeyRefusal($key);
        }

        return MeshClient::headerValueRefusal($key);
    }

    /**
     * The short reason storedKeyRefusal() names, for texts worded as 'a
     * stored API key the PSA does not send (…)', or null as there.
     */
    public static function storedKeyNote(): ?string
    {
        return match ($refusal = self::storedKeyRefusal()) {
            null => null,
            MeshClient::UNUSABLE_KEY => 'zero or true',
            self::UNDECRYPTABLE_KEY => 'it could not be decrypted',
            default => 'it '.$refusal,
        };
    }

    public static function isEnabled(): bool
    {
        return Setting::getValue('mesh_enabled', '1') === '1';
    }

    /**
     * #6162: true only for a stored key every client would send: a key
     * that is not missing and has no storedKeyRefusal(). False for a
     * missing key, a stored '0' (#6161), a key holding a CR or LF (#6288:
     * the header rule every client applies before send, so the operator
     * surfaces and gates agree with that refusal, which stays in the
     * clients) and a row that does not decrypt (#6296).
     */
    public static function isConfigured(): bool
    {
        [$key, $undecryptable] = self::readKey();

        return ! $undecryptable && self::isSendableKey($key) && MeshClient::headerValueRefusal($key) === null;
    }

    /**
     * #6213: a key IS stored, but isConfigured() is false because the PSA
     * does not send it (storedKeyRefusal()). Operator texts use this to
     * say the key is set but unusable instead of 'not configured'.
     */
    public static function isKeyStoredButUnusable(): bool
    {
        return self::storedKeyRefusal() !== null;
    }

    /**
     * #6213: the operator text for isConfigured() being false: $missing
     * when no key is stored (null, '' or blanks), else that Mesh has a
     * stored key the PSA does not send, with storedKeyNote()'s reason.
     * #6302: not worded 'API key is set, …', which the audit redactor
     * (WikiRedactor's keyword rule) reads as a key and its value and
     * redacts; the staff tool texts use the same wording.
     */
    public static function notConfiguredText(string $missing): string
    {
        $note = self::storedKeyNote();

        return $note !== null
            ? "Mesh has a stored API key the PSA does not send ({$note}). Replace it in Settings → Integrations."
            : $missing;
    }

    /**
     * A scalar that is neither apiKeyMissing() nor apiKeyUnusable(). #6208:
     * an array (even []) or an object is not sendable, as the old empty()
     * test also refused []. The rest of the header rule is not applied
     * here (MeshWriteClient::isConfigured() uses this predicate); the
     * clients apply it before send, and isConfigured() applies it to the
     * stored key (#6288).
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
