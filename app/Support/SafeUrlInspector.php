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

        // inet_aton-style IPv4 encodings: decimal (2130706433), hex
        // (0x7f000001), octal (0177.0.0.1) and short forms (127.1). Recognized
        // as literals so they are range-checked directly rather than routed
        // through DNS, where a malicious answer could launder a private address.
        return self::normalizeInetAton($host);
    }

    /**
     * Parse an inet_aton-style IPv4 encoding to a canonical dotted-quad, or null
     * when $host is not such an encoding (then it is treated as a hostname).
     *
     * inet_aton accepts 1–4 dot-separated parts; each part may be decimal,
     * octal (leading 0) or hex (0x…), and the final part absorbs the remaining
     * low-order bytes (so "127.1" == 127.0.0.1, "0x7f000001" == 127.0.0.1).
     * We return a value ONLY for an unambiguous, in-range parse — anything
     * malformed returns null and falls through to the DNS branch (which fails
     * closed), so a half-parse never invents a public-looking address.
     */
    private static function normalizeInetAton(string $host): ?string
    {
        $parts = explode('.', $host);
        $count = count($parts);
        if ($count < 1 || $count > 4) {
            return null;
        }

        $values = [];
        foreach ($parts as $part) {
            $value = self::parseInetAtonPart($part);
            if ($value === null) {
                return null;
            }
            $values[] = $value;
        }

        // The last part holds the low (4 − (count−1)) bytes; each leading part
        // must be a single byte.
        $last = array_pop($values);
        $maxLast = match ($count) {
            1 => 0xFFFFFFFF,
            2 => 0xFFFFFF,
            3 => 0xFFFF,
            default => 0xFF,
        };
        if ($last > $maxLast) {
            return null;
        }
        foreach ($values as $leading) {
            if ($leading > 0xFF) {
                return null;
            }
        }

        $addr = $last;
        foreach ($values as $i => $leading) {
            $addr |= $leading << (8 * (3 - $i));
        }

        return long2ip($addr);
    }

    /**
     * Parse one inet_aton part (decimal / 0-octal / 0x-hex) to its integer
     * value, or null when it is not a clean numeric part or overflows 32 bits.
     */
    private static function parseInetAtonPart(string $part): ?int
    {
        if (preg_match('/^0[xX][0-9a-fA-F]+$/', $part)) {
            $value = hexdec(substr($part, 2));
        } elseif (preg_match('/^0[0-7]+$/', $part)) {
            $value = octdec(substr($part, 1));
        } elseif (preg_match('/^(?:0|[1-9][0-9]*)$/', $part)) {
            $value = (float) $part;
        } else {
            return null;
        }

        // hexdec/octdec/(float) yield a float for very large inputs; bound
        // before the int cast so an oversized part never wraps to a valid byte.
        if ($value < 0 || $value > 0xFFFFFFFF) {
            return null;
        }

        return (int) $value;
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

    /**
     * True only for a public, routable address. The single source of truth for
     * "is this IP safe to connect to" — shared by the save-time check above and
     * the request-time connection pin in TacticalClient (psa-rkf6), so the two
     * can never diverge on what counts as private/reserved.
     */
    public static function ipIsSafe(string $ip): bool
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
