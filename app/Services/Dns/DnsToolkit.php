<?php

namespace App\Services\Dns;

/**
 * Shared DNS lookup helpers for AI tools (triage + assistant).
 * Uses PHP's built-in dns_get_record() — no external dependencies.
 */
class DnsToolkit
{
    private const VALID_TYPES = [
        'A' => DNS_A,
        'AAAA' => DNS_AAAA,
        'MX' => DNS_MX,
        'TXT' => DNS_TXT,
        'NS' => DNS_NS,
        'CNAME' => DNS_CNAME,
        'SOA' => DNS_SOA,
        'SRV' => DNS_SRV,
        'PTR' => DNS_PTR,
    ];

    /**
     * Perform a DNS lookup for a single record type.
     *
     * @param  string  $hostname  The hostname to look up
     * @param  string  $type  One of: A, AAAA, MX, TXT, NS, CNAME, SOA, SRV, PTR
     * @return array {records: [...], error?: string}
     */
    public static function lookup(string $hostname, string $type): array
    {
        $type = strtoupper(trim($type));
        $hostname = self::sanitizeHostname($hostname);

        if (! $hostname) {
            return ['error' => 'Invalid hostname'];
        }

        if (! isset(self::VALID_TYPES[$type])) {
            return ['error' => 'Invalid record type. Supported: '.implode(', ', array_keys(self::VALID_TYPES))];
        }

        // PTR lookups need the IP reversed with .in-addr.arpa or .ip6.arpa suffix
        if ($type === 'PTR') {
            $hostname = self::toPtrName($hostname);
            if (! $hostname) {
                return ['error' => 'Invalid IP address for PTR lookup'];
            }
        }

        // Suppress warnings from dns_get_record — we handle the empty result ourselves
        $records = @dns_get_record($hostname, self::VALID_TYPES[$type]);

        if ($records === false) {
            return ['error' => 'DNS lookup failed'];
        }

        return ['records' => self::simplifyRecords($records, $type)];
    }

    /**
     * Email health check — returns MX, SPF, and DMARC records for a domain in one call.
     * Most common email troubleshooting scenario.
     *
     * @param  string  $domain  The domain (not a specific host — e.g., "example.com" not "mail.example.com")
     * @return array {mx: [...], spf: string|null, dmarc: string|null, error?: string}
     */
    public static function emailHealth(string $domain): array
    {
        $domain = self::sanitizeHostname($domain);

        if (! $domain) {
            return ['error' => 'Invalid domain'];
        }

        $result = [
            'domain' => $domain,
            'mx' => [],
            'spf' => null,
            'dmarc' => null,
        ];

        // MX records
        $mx = @dns_get_record($domain, DNS_MX);
        if (is_array($mx)) {
            $result['mx'] = array_map(fn ($r) => [
                'priority' => $r['pri'] ?? null,
                'host' => $r['target'] ?? null,
            ], $mx);
            usort($result['mx'], fn ($a, $b) => ($a['priority'] ?? 999) <=> ($b['priority'] ?? 999));
        }

        // SPF (a TXT record starting with "v=spf1")
        $txt = @dns_get_record($domain, DNS_TXT);
        if (is_array($txt)) {
            foreach ($txt as $r) {
                $value = $r['txt'] ?? '';
                if (stripos($value, 'v=spf1') === 0) {
                    $result['spf'] = $value;
                    break;
                }
            }
        }

        // DMARC (TXT at _dmarc.domain)
        $dmarc = @dns_get_record('_dmarc.'.$domain, DNS_TXT);
        if (is_array($dmarc)) {
            foreach ($dmarc as $r) {
                $value = $r['txt'] ?? '';
                if (stripos($value, 'v=DMARC1') === 0) {
                    $result['dmarc'] = $value;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Strip the hostname to a safe subset — letters, digits, dots, hyphens, colons (for IPv6).
     * Returns null if the result would be empty or malformed.
     */
    private static function sanitizeHostname(string $hostname): ?string
    {
        $hostname = strtolower(trim($hostname));

        // Strip protocol prefix if present (e.g., "https://example.com")
        $hostname = preg_replace('#^https?://#', '', $hostname);

        // Strip trailing slash and path
        $hostname = preg_replace('#/.*$#', '', $hostname);

        if (! preg_match('/^[a-z0-9._:\-]+$/i', $hostname)) {
            return null;
        }

        if (strlen($hostname) > 253 || strlen($hostname) < 1) {
            return null;
        }

        return $hostname;
    }

    /**
     * Convert an IP address to its reverse DNS lookup name.
     * 1.2.3.4 -> 4.3.2.1.in-addr.arpa
     * ::1 -> 1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.ip6.arpa
     */
    private static function toPtrName(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return implode('.', array_reverse(explode('.', $ip))).'.in-addr.arpa';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            if ($bin === false) {
                return null;
            }
            $hex = bin2hex($bin);
            $nibbles = array_reverse(str_split($hex));

            return implode('.', $nibbles).'.ip6.arpa';
        }

        // Maybe it's already in .in-addr.arpa/.ip6.arpa form
        if (str_ends_with($ip, '.in-addr.arpa') || str_ends_with($ip, '.ip6.arpa')) {
            return $ip;
        }

        return null;
    }

    /**
     * Simplify PHP's dns_get_record output into a flatter, AI-friendly format.
     */
    private static function simplifyRecords(array $records, string $type): array
    {
        return array_values(array_map(function ($r) use ($type) {
            return match ($type) {
                'A' => ['host' => $r['host'] ?? null, 'ip' => $r['ip'] ?? null, 'ttl' => $r['ttl'] ?? null],
                'AAAA' => ['host' => $r['host'] ?? null, 'ipv6' => $r['ipv6'] ?? null, 'ttl' => $r['ttl'] ?? null],
                'MX' => ['host' => $r['host'] ?? null, 'priority' => $r['pri'] ?? null, 'target' => $r['target'] ?? null, 'ttl' => $r['ttl'] ?? null],
                'TXT' => ['host' => $r['host'] ?? null, 'txt' => $r['txt'] ?? null, 'ttl' => $r['ttl'] ?? null],
                'NS' => ['host' => $r['host'] ?? null, 'target' => $r['target'] ?? null, 'ttl' => $r['ttl'] ?? null],
                'CNAME' => ['host' => $r['host'] ?? null, 'target' => $r['target'] ?? null, 'ttl' => $r['ttl'] ?? null],
                'SOA' => [
                    'host' => $r['host'] ?? null,
                    'mname' => $r['mname'] ?? null,
                    'rname' => $r['rname'] ?? null,
                    'serial' => $r['serial'] ?? null,
                    'refresh' => $r['refresh'] ?? null,
                    'retry' => $r['retry'] ?? null,
                    'expire' => $r['expire'] ?? null,
                    'minimum-ttl' => $r['minimum-ttl'] ?? null,
                    'ttl' => $r['ttl'] ?? null,
                ],
                'SRV' => [
                    'host' => $r['host'] ?? null,
                    'priority' => $r['pri'] ?? null,
                    'weight' => $r['weight'] ?? null,
                    'port' => $r['port'] ?? null,
                    'target' => $r['target'] ?? null,
                    'ttl' => $r['ttl'] ?? null,
                ],
                'PTR' => ['host' => $r['host'] ?? null, 'target' => $r['target'] ?? null, 'ttl' => $r['ttl'] ?? null],
                default => $r,
            };
        }, $records));
    }
}
