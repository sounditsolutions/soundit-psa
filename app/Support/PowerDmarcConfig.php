<?php

namespace App\Support;

use App\Models\Setting;

/**
 * PowerDMARC integration config (issue #689).
 *
 * Follows the house static-helper pattern (UnifiConfig / HuntressConfig): all
 * tenant config lives in the settings table, only the API key is encrypted.
 *
 * WHICH API: the PowerDMARC platform API (https://app.powerdmarc.com) — one
 * Bearer API key for the whole PowerDMARC account, covering every domain that
 * account manages. Read-only usage: domain status, aggregate (RUA) reports and
 * the DNS timeline; hosted-record writes are deliberately out of scope.
 */
class PowerDmarcConfig
{
    public const DEFAULT_BASE_URL = 'https://app.powerdmarc.com';

    /**
     * Default wall-clock budget for ONE MSSP page walk (#801), in seconds.
     *
     * Sized to the ceiling allMsspDomains() advertises, not to a round number:
     * 400 pages x ~15 pinned rows is ~6000 domains, and reaching it costs 400
     * SEQUENTIAL HTTPS round trips at ~150-250ms each against the tenant
     * portal. A 20s budget covers only ~80-130 of those pages, so accounts well
     * inside the documented ceiling would throw on every fetchDomains() path —
     * the same 'the feature fails for exactly the account class it exists for'
     * breakage the 400-page cap was raised to fix. 120s clears 400 pages at the
     * slow end of that range.
     *
     * Note the budget is NOT sized under max_execution_time: on Unix php-fpm
     * that timer excludes time spent waiting on sockets, so it was never the
     * thing killing a network-bound walk. The budget exists so a THROTTLED or
     * cycling portal fails loudly and inside the request instead of pinning a
     * worker until the gateway gives up — which is why it stays settable:
     * installs behind a shorter proxy timeout lower it, very large accounts on
     * a slow portal raise it.
     */
    public const DEFAULT_MSSP_WALK_SECONDS = 120;

    public static function get(string $key): ?string
    {
        return match ($key) {
            'api_key' => Setting::getEncrypted('powerdmarc_api_key'),
            'base_url' => Setting::getValue('powerdmarc_base_url', self::DEFAULT_BASE_URL),
            'mssp_base_url' => Setting::getValue('powerdmarc_mssp_base_url', ''),
            'mssp_walk_seconds' => Setting::getValue('powerdmarc_mssp_walk_seconds', ''),
            default => null,
        };
    }

    /**
     * MSSP tenant-portal host (#801), e.g. https://<tenant>.powerdmarc.com —
     * a DIFFERENT host from base_url (the end-user platform). Null means the
     * MSSP enumeration lane is OFF and domain enumeration uses the end-user
     * /api/v1/domains listing as before. There is no default: the tenant host
     * is account-specific and cannot be guessed.
     */
    public static function msspBaseUrl(): ?string
    {
        $configured = rtrim(trim((string) self::get('mssp_base_url')), '/');

        return $configured !== '' ? $configured : null;
    }

    /**
     * Wall-clock budget for one MSSP page walk (#801). Blank/0 means the
     * default; the bounds match the settings form so a hand-edited settings row
     * cannot disable the deadline (0/negative) or make it unreachable.
     */
    public static function msspWalkSeconds(): int
    {
        $configured = (int) trim((string) self::get('mssp_walk_seconds'));

        if ($configured <= 0) {
            return self::DEFAULT_MSSP_WALK_SECONDS;
        }

        return max(10, min(600, $configured));
    }

    /**
     * Master switch. OFF=OFF — per the CLAUDE.md rule this gates the AI tool surface
     * too, not just syncs, so a deployment that switches PowerDMARC off stops
     * publishing powerdmarc_* tools to the model.
     *
     * Defaults to '0': a net-new integration ships dormant.
     */
    public static function isEnabled(): bool
    {
        return Setting::getValue('powerdmarc_enabled', '0') === '1';
    }

    public static function isConfigured(): bool
    {
        return ! empty(self::get('api_key'));
    }

    /** Both the switch and the credentials — the predicate the tool surface gates on. */
    public static function isAvailable(): bool
    {
        return self::isEnabled() && self::isConfigured();
    }

    public static function baseUrl(): string
    {
        $configured = trim((string) self::get('base_url'));

        return rtrim($configured !== '' ? $configured : self::DEFAULT_BASE_URL, '/');
    }

    /**
     * Per-client API key override (ops 440/442), or null when the client has
     * none stored. Callers fall back to the account-level key: an MSSP account
     * key is refused (403) on the end-user platform routes, but a DIRECT
     * PowerDMARC account's key works account-wide — so absence of a per-client
     * key must keep meaning "use the account key", never "unconfigured".
     */
    public static function apiKeyForClient(int $clientId): ?string
    {
        $key = \App\Models\ClientPowerdmarcKey::where('client_id', $clientId)->first()?->api_key;

        return is_string($key) && $key !== '' ? $key : null;
    }
}
