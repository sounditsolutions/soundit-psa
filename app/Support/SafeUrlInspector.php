<?php

namespace App\Support;

/**
 * SSRF guard for outbound base URLs the user can set (the Tactical API URL).
 * Spec §11.4 / amendment B2 — save-time validation.
 *
 * The Tactical API key bypasses 2FA and is high-value; a staff user repointing
 * the base URL at a metadata endpoint (169.254.169.254) or an internal host
 * could exfiltrate it or SSRF the VPS. This rejects:
 *   - any non-https URL, or a URL with no parseable host;
 *   - IP LITERALS in private/reserved/link-local/metadata ranges, including
 *     IPv6 ([::1], [::ffff:169.254.169.254]) and decimal-encoded IPv4
 *     (https://2130706433/);
 *   - HOSTNAMES whose DNS resolves to ANY such range (one public + one private
 *     A-record ⇒ reject), and NXDOMAIN fails CLOSED.
 *
 * NOT covered here (documented residual, P3 follow-up): request-time peer-IP
 * re-pinning for the DNS-rebinding TOCTOU window. The P2 controls are this
 * save-time check + the outbound client's allow_redirects=false.
 */
class SafeUrlInspector
{
    /**
     * @param  string  $url  the candidate URL
     * @param  callable|null  $resolver  host => string[]|false (defaults to gethostbynamel)
     * @return string|null an error message if the URL is unsafe, else null
     */
    public static function reject(string $url, ?callable $resolver = null): ?string
    {
        $resolver ??= 'gethostbynamel';

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return 'Enter a valid URL.';
        }

        if (($parts['scheme'] ?? null) !== 'https') {
            return 'The Tactical API URL must use https://.';
        }

        $host = $parts['host'];

        // Strip IPv6 brackets: parse_url leaves [::1] as "[::1]".
        $host = trim($host, '[]');

        // 1. IP literal? Check it directly — never route a literal through DNS.
        if ($literal = self::normalizeIpLiteral($host)) {
            return self::ipIsSafe($literal) ? null : self::unsafeMsg($literal);
        }

        // 2. Hostname → resolve and check EVERY A-record. Fail closed on NXDOMAIN.
        $ips = $resolver($host);
        if ($ips === false || ! is_array($ips) || $ips === []) {
            return "The Tactical API host '{$host}' could not be resolved (rejected for safety).";
        }

        foreach ($ips as $ip) {
            if (! self::ipIsSafe($ip)) {
                return self::unsafeMsg($ip)." (resolved from {$host})";
            }
        }

        return null;
    }

    /**
     * Return a canonical IP string if $host is an IP literal (dotted-quad, IPv6,
     * decimal-encoded IPv4, or IPv4-mapped IPv6), else null (it's a hostname).
     */
    private static function normalizeIpLiteral(string $host): ?string
    {
        // Plain IPv4 / IPv6 literal.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::extractMappedV4($host);
        }

        // Decimal-encoded IPv4, e.g. 2130706433 == 127.0.0.1.
        if (ctype_digit($host)) {
            $n = (int) $host;
            if ($n >= 0 && $n <= 4294967295) {
                return long2ip($n);
            }
        }

        return null;
    }

    /**
     * If an IPv6 string embeds an IPv4 address (::ffff:169.254.169.254 or
     * ::169.254.169.254), return the embedded IPv4 so range checks apply to it.
     */
    private static function extractMappedV4(string $ip): string
    {
        if (preg_match('/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/', $ip, $m)
            && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $m[1];
        }

        return $ip;
    }

    private static function ipIsSafe(string $ip): bool
    {
        // Reject anything that isn't a public, routable IP.
        $public = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        if ($public === false) {
            return false;
        }

        // Belt-and-suspenders explicit blocks (some PHP builds don't flag all of these).
        $lower = strtolower($ip);
        if ($lower === '::1' || $lower === '0.0.0.0') {
            return false;
        }
        if (str_starts_with($lower, 'fe80:')) {       // IPv6 link-local fe80::/10
            return false;
        }
        if (str_starts_with($ip, '169.254.')) {        // IPv4 link-local / metadata 169.254.0.0/16
            return false;
        }

        return true;
    }

    private static function unsafeMsg(string $ip): string
    {
        return "The Tactical API URL resolves to a private or reserved address ({$ip}), which is not allowed.";
    }
}
