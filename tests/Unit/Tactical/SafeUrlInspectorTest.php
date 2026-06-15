<?php

namespace Tests\Unit\Tactical;

use App\Support\SafeUrlInspector;
use PHPUnit\Framework\TestCase;

/**
 * Task 7 / amendment B2: SSRF guard for the Tactical API URL (save-time).
 *
 * Reject non-https, IP literals in private/reserved/link-local/metadata ranges
 * (incl. IPv6 + decimal-encoded), and hostnames whose DNS resolves to ANY such
 * range; NXDOMAIN fails CLOSED. DNS is injected so the matrix is deterministic
 * (no real lookups).
 */
class SafeUrlInspectorTest extends TestCase
{
    /** A resolver stub: maps host => A-records, or false for NXDOMAIN. */
    private function resolver(array $map): callable
    {
        return fn (string $host) => $map[$host] ?? false;
    }

    public function test_accepts_a_normal_https_host_resolving_public(): void
    {
        $err = SafeUrlInspector::reject(
            'https://rmm-api.dev.soundpsa.com',
            $this->resolver(['rmm-api.dev.soundpsa.com' => ['93.184.216.34']]),
        );

        $this->assertNull($err);
    }

    public function test_rejects_http_scheme(): void
    {
        $err = SafeUrlInspector::reject('http://rmm-api.dev.soundpsa.com', $this->resolver(['rmm-api.dev.soundpsa.com' => ['93.184.216.34']]));
        $this->assertNotNull($err);
        $this->assertStringContainsStringIgnoringCase('https', $err);
    }

    public function test_rejects_missing_scheme_or_host(): void
    {
        $this->assertNotNull(SafeUrlInspector::reject('not a url', $this->resolver([])));
        $this->assertNotNull(SafeUrlInspector::reject('https://', $this->resolver([])));
    }

    public function test_rejects_ipv6_loopback_literal(): void
    {
        // No DNS — a literal must be checked directly (brackets stripped first).
        $this->assertNotNull(SafeUrlInspector::reject('https://[::1]/', $this->resolver([])));
    }

    public function test_rejects_ipv4_mapped_metadata_ipv6_literal(): void
    {
        $this->assertNotNull(SafeUrlInspector::reject('https://[::ffff:169.254.169.254]/', $this->resolver([])));
    }

    public function test_rejects_decimal_encoded_loopback_literal(): void
    {
        // 2130706433 == 127.0.0.1
        $this->assertNotNull(SafeUrlInspector::reject('https://2130706433/', $this->resolver([])));
    }

    public function test_rejects_link_local_metadata_literal(): void
    {
        $this->assertNotNull(SafeUrlInspector::reject('https://169.254.169.254/', $this->resolver([])));
    }

    public function test_rejects_localhost_literal_name(): void
    {
        // "localhost" with no A-record stub -> NXDOMAIN -> reject (and it's a name we explicitly distrust)
        $this->assertNotNull(SafeUrlInspector::reject('https://localhost/', $this->resolver(['localhost' => ['127.0.0.1']])));
    }

    public function test_rejects_private_ranges(): void
    {
        foreach (['10.0.0.5', '172.16.0.9', '192.168.1.1', '0.0.0.0'] as $ip) {
            $this->assertNotNull(
                SafeUrlInspector::reject("https://{$ip}/", $this->resolver([])),
                "should reject private literal {$ip}"
            );
        }
    }

    public function test_rejects_when_any_a_record_is_private(): void
    {
        // One public + one private A-record -> reject (DNS-rebinding / split-horizon).
        $err = SafeUrlInspector::reject(
            'https://sneaky.example.com',
            $this->resolver(['sneaky.example.com' => ['93.184.216.34', '10.1.2.3']]),
        );

        $this->assertNotNull($err);
    }

    public function test_rejects_nxdomain_fail_closed(): void
    {
        $err = SafeUrlInspector::reject(
            'https://does-not-resolve.example.com',
            $this->resolver([]), // returns false => NXDOMAIN
        );

        $this->assertNotNull($err, 'NXDOMAIN must fail closed (reject)');
    }
}
