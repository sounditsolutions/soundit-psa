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
     * Container key for the DNS seam {@see isPostableBaseUrl()} resolves through.
     * Unbound in production, where resolution is gethostbynamel inside
     * {@see SafeUrlInspector}; bound by tests, whose portal hosts are RFC 6761
     * example names that resolve nowhere and would otherwise all fail closed.
     */
    public const HOST_RESOLVER = 'hdb.host_resolver';

    /**
     * What this integration will do with a Portal URL, as {@see baseUrlVerdict()}
     * reports it. Three cases, because a boolean cannot carry the third: the URL
     * is postable, we found something WRONG with it, or we could not CHECK it —
     * the host did not resolve.
     *
     * All three fail closed; only the middle one is a setting the operator can
     * go and fix, and {@see \App\Services\Hdb\HdbAuthClient} consults this on
     * every attempt, so a resolver outage against an unchanged, valid URL must
     * not be reported as a refused one.
     */
    public const BASE_URL_POSTABLE = 'postable';

    public const BASE_URL_REFUSED = 'refused';

    public const BASE_URL_UNRESOLVED = 'unresolved';

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
        return self::baseUrlVerdict() === self::BASE_URL_POSTABLE;
    }

    /**
     * The same read-path test as {@see hasPostableBaseUrl()}, undiluted: which of
     * the three {@see BASE_URL_POSTABLE} cases {@see baseUrl()} is in.
     *
     * The client calls this rather than the boolean so that "could not resolve"
     * — an infrastructure outage that says nothing about the stored setting —
     * does not surface with the same reason code as a URL we refused on sight.
     */
    public static function baseUrlVerdict(): string
    {
        return self::verdictFor(self::baseUrl());
    }

    /**
     * The origin test, applied on read here and on write by
     * {@see \App\Http\Controllers\Web\IntegrationsController::updateT2t()}.
     *
     * Refuses, in order: anything that is not https (plain http puts the stored
     * password on the wire in cleartext), a URL carrying its own credentials, a
     * dotless name, an IP literal in ANY spelling, the reserved internal
     * suffixes, and — the part no string test can decide — a name that RESOLVES
     * into private, reserved, link-local or metadata space.
     *
     * That last step is {@see SafeUrlInspector}, the same check
     * {@see \App\Rules\SafeWebhookUrl} applies to the PowerDMARC portal URLs
     * saved a few methods away in the controller: it normalises the legacy
     * inet_aton IPv4 spellings, tests EVERY A-record, and fails closed on
     * NXDOMAIN. The rule object itself is not reused because it rejects an empty
     * string, and empty is how this field says "use the default host".
     */
    public static function isPostableBaseUrl(string $url): bool
    {
        return self::verdictFor($url) === self::BASE_URL_POSTABLE;
    }

    /**
     * The test itself, keeping the one distinction the boolean above throws
     * away: a name that did not RESOLVE is not a name we found something wrong
     * with. Both are refusals, and the write path treats them alike; the read
     * path must not, or a resolver outage reads as a hostile Portal URL.
     */
    private static function verdictFor(string $url): string
    {
        $parts = parse_url(rtrim(trim($url), '/'));

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return self::BASE_URL_REFUSED;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return self::BASE_URL_REFUSED;
        }

        // Normalised BEFORE it is tested: `vault.internal.` and `vault.internal`
        // are the same name to a resolver, so a trailing root dot left on the
        // string walks straight past the suffix list below.
        $host = rtrim(strtolower(trim((string) ($parts['host'] ?? ''), '[]')), '.');

        if ($host === '' || ! str_contains($host, '.')) {
            return self::BASE_URL_REFUSED;
        }

        // No IP literal, whatever its spelling. Testing the RIGHTMOST LABEL is
        // what makes that true of the inet_aton forms (0177.0.0.1, 0x7f.0.0.1,
        // 127.1) that FILTER_VALIDATE_IP does not call addresses while every
        // resolver still reaches 127.0.0.1 through them: each ends in a numeric
        // label, and no hostname this integration may post to does.
        $labels = explode('.', $host);

        if (preg_match('/^[a-z][a-z0-9-]*$/', (string) end($labels)) !== 1) {
            return self::BASE_URL_REFUSED;
        }

        foreach (['.local', '.localhost', '.internal', '.home.arpa'] as $reserved) {
            if (str_ends_with($host, $reserved)) {
                return self::BASE_URL_REFUSED;
            }
        }

        // The resolver's answer is observed through this wrapper rather than read
        // back out of the inspector's rejection MESSAGE: a falsy answer means
        // "we could not check", which still fails closed exactly as before but is
        // a different fact from "this resolves somewhere we will not post to".
        $unresolved = false;
        $resolver = self::hostResolver() ?? 'gethostbynamel';
        $observed = function (string $candidate) use ($resolver, &$unresolved) {
            $ips = $resolver($candidate);

            if ($ips === false || ! is_array($ips) || $ips === []) {
                $unresolved = true;
            }

            return $ips;
        };

        if (SafeUrlInspector::reject('https://'.$host, $observed, 'HDB Portal URL') === null) {
            return self::BASE_URL_POSTABLE;
        }

        return $unresolved ? self::BASE_URL_UNRESOLVED : self::BASE_URL_REFUSED;
    }

    /**
     * The host-resolution seam {@see isPostableBaseUrl()} hands to
     * {@see SafeUrlInspector}: null in production, which is gethostbynamel.
     *
     * Tests bind {@see HOST_RESOLVER} because a guard that fails closed on
     * NXDOMAIN refuses every example hostname, which is correct in production
     * and unusable in a suite that cannot own a real portal host.
     */
    private static function hostResolver(): ?callable
    {
        if (! app()->bound(self::HOST_RESOLVER)) {
            return null;
        }

        $resolver = app(self::HOST_RESOLVER);

        return is_callable($resolver) ? $resolver : null;
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
