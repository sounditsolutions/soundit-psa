<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Read side of the HDB report-portal credentials entered on the Tier2Tickets /
 * HelpDesk Buttons integrations card (psa #340).
 *
 * The entry surface shipped in #1332 with no consumer at all, which is what
 * issue #1352 records: the settings page renders the default host as a form
 * PLACEHOLDER only, and no code applied it on read. `baseUrl()` below is that
 * missing read path, and it is the single place the default is resolved.
 *
 * The blank-means-default rule is deliberate and is a contract with the writer:
 * {@see \App\Http\Controllers\Web\IntegrationsController::updateT2t()} stores an
 * empty string when the operator clears the field rather than echoing the
 * default back into the setting, precisely so the default can move later without
 * a data migration. A reader that treats blank as "unconfigured" would break it.
 */
final class HdbPortalConfig
{
    /**
     * The vendor's beta portal. Both HDB press links point at this host, so a
     * fresh install works without the operator typing anything.
     */
    public const DEFAULT_BASE_URL = 'https://beta.helpdeskbuttons.com';

    /**
     * The portal origin, never with a trailing slash.
     *
     * Blank (or whitespace-only) means "use the default host" — see the class
     * docblock. Callers build paths as `baseUrl().'/login'`.
     */
    public static function baseUrl(): string
    {
        $stored = trim((string) Setting::getValue('hdb_base_url', ''));

        return rtrim($stored !== '' ? $stored : self::DEFAULT_BASE_URL, '/');
    }

    /**
     * Whether {@see baseUrl()} is somewhere this integration will POST the
     * decrypted service-subaccount password and a live one-time code.
     *
     * The Portal URL setting is written from a form any authenticated user can
     * reach (psa #1344 is still open), so the admin-only gate on Test Connection
     * decides only WHO spends the credential — this decides WHERE it may go.
     * {@see \App\Services\Hdb\HdbAuthClient} measures a returned form action
     * AGAINST this origin, so it inherits whatever the setting names and cannot
     * be the check.
     */
    public static function hasPostableBaseUrl(): bool
    {
        return self::isPostableBaseUrl(self::baseUrl());
    }

    /**
     * The origin test, applied on read here and on write by
     * {@see \App\Http\Controllers\Web\IntegrationsController::updateT2t()}.
     *
     * Refuses, in order: anything that is not https (plain http puts the stored
     * password on the wire in cleartext), a URL carrying its own credentials, an
     * IP literal or a dotless name (169.254.169.254 and short internal names are
     * the targets this exists for), and the reserved internal suffixes.
     */
    public static function isPostableBaseUrl(string $url): bool
    {
        $parts = parse_url(rtrim(trim($url), '/'));

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || ! str_contains($host, '.')) {
            return false;
        }

        foreach (['.local', '.localhost', '.internal', '.home.arpa'] as $reserved) {
            if (str_ends_with($host, $reserved)) {
                return false;
            }
        }

        return true;
    }

    public static function email(): string
    {
        return trim((string) Setting::getValue('hdb_email', ''));
    }

    /**
     * The stored service-subaccount password, or null when none is stored.
     *
     * Not trimmed: leading or trailing whitespace inside a password is part of
     * the password. Only the "is anything stored" test trims.
     */
    public static function password(): ?string
    {
        $stored = (string) Setting::getEncrypted('hdb_password', '');

        return trim($stored) !== '' ? $stored : null;
    }

    public static function totpSecret(): ?string
    {
        $stored = trim((string) Setting::getEncrypted('hdb_totp_secret', ''));

        return $stored !== '' ? $stored : null;
    }

    public static function hasTotpSecret(): bool
    {
        return self::totpSecret() !== null;
    }

    /**
     * Email and password are what a login attempt needs. The seed is NOT part of
     * this predicate: whether the subaccount has two-factor enrolled is the
     * portal's state, not ours, and a missing seed has to surface as a login
     * outcome rather than as "not configured" — otherwise an operator who
     * enrolled 2FA but never pasted the seed sees the same message as one who
     * never filled the form in.
     */
    public static function isConfigured(): bool
    {
        return self::email() !== '' && self::password() !== null;
    }

    /**
     * The current six-digit code for the stored seed, or null when no seed is
     * stored or the stored seed is not decodable base32.
     */
    public static function generateTotp(): ?string
    {
        return Totp::code(self::totpSecret());
    }
}
