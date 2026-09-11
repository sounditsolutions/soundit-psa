<?php

namespace Tests\Unit\Support;

use App\Models\Setting;
use App\Support\HdbPortalConfig;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The read path issue #1352 records as missing.
 *
 * #1332 shipped the entry surface with the default portal host rendered only as
 * a form PLACEHOLDER, so nothing applied it on read: an operator who cleared the
 * field left the (then hypothetical) fetch pointed at an empty string. The
 * settings test for that behaviour could only assert the default was SEEN on the
 * page. These assert it is USED.
 */
class HdbPortalConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The destination guard resolves the host and fails closed on NXDOMAIN.
        // The example names below resolve nowhere, so the resolution step gets a
        // public answer by default; the cases that care name their own.
        $this->resolveHostsAs(['93.184.216.34']);
    }

    /**
     * Answer every host lookup with $ips for the rest of this test.
     *
     * @param  string[]  $ips
     */
    private function resolveHostsAs(array $ips): void
    {
        $this->app->instance(HdbPortalConfig::HOST_RESOLVER, fn (string $host) => $ips);
    }

    public function test_an_unset_portal_url_reads_as_the_default_host(): void
    {
        $this->assertSame(HdbPortalConfig::DEFAULT_BASE_URL, HdbPortalConfig::baseUrl());
    }

    public function test_a_cleared_portal_url_reads_as_the_default_host(): void
    {
        // This is the #1352 case exactly: the writer stores '' rather than
        // echoing the default back, so the reader is what makes clearing safe.
        Setting::setValue('hdb_base_url', '');

        $this->assertSame(HdbPortalConfig::DEFAULT_BASE_URL, HdbPortalConfig::baseUrl());
    }

    public function test_a_whitespace_only_portal_url_reads_as_the_default_host(): void
    {
        Setting::setValue('hdb_base_url', "  \n");

        $this->assertSame(HdbPortalConfig::DEFAULT_BASE_URL, HdbPortalConfig::baseUrl());
    }

    public function test_a_stored_portal_url_wins_over_the_default(): void
    {
        Setting::setValue('hdb_base_url', 'https://portal.example.test');

        $this->assertSame('https://portal.example.test', HdbPortalConfig::baseUrl());
    }

    public function test_the_portal_url_never_carries_a_trailing_slash(): void
    {
        // Callers concatenate '/login'; a stored trailing slash would produce a
        // '//login' that some hosts 404 and others redirect.
        Setting::setValue('hdb_base_url', 'https://portal.example.test/');

        $this->assertSame('https://portal.example.test', HdbPortalConfig::baseUrl());
    }

    public function test_the_default_host_matches_the_one_the_settings_form_shows(): void
    {
        // The controller used to hold its own private copy of this string. If the
        // two ever drift, the form promises one host and the client uses another.
        $this->assertSame('https://beta.helpdeskbuttons.com', HdbPortalConfig::DEFAULT_BASE_URL);
    }

    public function test_the_default_and_a_stored_https_host_are_postable_destinations(): void
    {
        $this->assertTrue(HdbPortalConfig::hasPostableBaseUrl());

        Setting::setValue('hdb_base_url', 'https://portal.example.test/');

        $this->assertTrue(HdbPortalConfig::hasPostableBaseUrl());
    }

    public function test_a_plain_http_portal_url_is_not_a_postable_destination(): void
    {
        // The stored password is POSTed to this host, and the setting is writable
        // by any authenticated user (psa #1344) — http would put it on the wire
        // in cleartext for whoever chose the host.
        Setting::setValue('hdb_base_url', 'http://portal.example.test');

        $this->assertFalse(HdbPortalConfig::hasPostableBaseUrl());
    }

    public function test_an_internal_or_credentialled_portal_url_is_not_a_postable_destination(): void
    {
        $this->assertFalse(HdbPortalConfig::isPostableBaseUrl('https://169.254.169.254/latest/meta-data'));
        $this->assertFalse(HdbPortalConfig::isPostableBaseUrl('https://[::1]'));
        $this->assertFalse(HdbPortalConfig::isPostableBaseUrl('https://localhost'));
        $this->assertFalse(HdbPortalConfig::isPostableBaseUrl('https://portal.internal'));
        $this->assertFalse(HdbPortalConfig::isPostableBaseUrl('https://someone:something@portal.example.test'));
        $this->assertFalse(HdbPortalConfig::isPostableBaseUrl('not a url'));
    }

    public function test_a_trailing_root_dot_does_not_smuggle_a_reserved_suffix_past_the_list(): void
    {
        // `vault.internal.` is the same name to a resolver as `vault.internal`,
        // but str_ends_with('.internal') is false for it — and the same hole is
        // open on .local, .localhost and .home.arpa.
        $this->assertFalse(HdbPortalConfig::isPostableBaseUrl('https://vault.internal.'));
        $this->assertFalse(HdbPortalConfig::isPostableBaseUrl('https://printer.local.'));
        $this->assertFalse(HdbPortalConfig::isPostableBaseUrl('https://box.home.arpa.'));
    }

    public function test_a_legacy_ipv4_spelling_is_still_an_ip_literal(): void
    {
        // FILTER_VALIDATE_IP calls none of these an address and each carries a
        // dot, so a plain string test lets them through — while every resolver
        // still reaches 127.0.0.1 through them.
        $this->assertFalse(HdbPortalConfig::isPostableBaseUrl('https://0177.0.0.1'));
        $this->assertFalse(HdbPortalConfig::isPostableBaseUrl('https://0x7f.0.0.1'));
        $this->assertFalse(HdbPortalConfig::isPostableBaseUrl('https://127.1'));
        $this->assertFalse(HdbPortalConfig::isPostableBaseUrl('https://2130706433'));
    }

    public function test_a_public_looking_name_that_resolves_inside_is_not_a_postable_destination(): void
    {
        // The name is registrable and its suffix is ordinary; only resolving it
        // shows where the stored password would actually be posted.
        $this->resolveHostsAs(['10.0.0.5']);
        $this->assertFalse(HdbPortalConfig::isPostableBaseUrl('https://portal.example.test'));

        // One public A-record does not launder a private one.
        $this->resolveHostsAs(['93.184.216.34', '169.254.169.254']);
        $this->assertFalse(HdbPortalConfig::isPostableBaseUrl('https://portal.example.test'));
    }

    public function test_a_host_that_resolves_to_nothing_is_refused(): void
    {
        // Fail closed: an unresolvable name is not evidence of a safe one.
        $this->resolveHostsAs([]);

        $this->assertFalse(HdbPortalConfig::isPostableBaseUrl('https://portal.example.test'));
    }

    public function test_an_unresolvable_host_is_a_different_verdict_from_a_refused_one(): void
    {
        // Both fail closed and both leave hasPostableBaseUrl() false, but only
        // one of them is a setting the operator should go and change — and this
        // predicate runs on every login attempt, not just at save time.
        Setting::setValue('hdb_base_url', 'https://portal.example.test');

        $this->resolveHostsAs([]);
        $this->assertFalse(HdbPortalConfig::hasPostableBaseUrl());
        $this->assertSame(HdbPortalConfig::BASE_URL_UNRESOLVED, HdbPortalConfig::baseUrlVerdict());

        $this->resolveHostsAs(['10.0.0.5']);
        $this->assertFalse(HdbPortalConfig::hasPostableBaseUrl());
        $this->assertSame(HdbPortalConfig::BASE_URL_REFUSED, HdbPortalConfig::baseUrlVerdict());

        Setting::setValue('hdb_base_url', 'http://portal.example.test');
        $this->assertSame(HdbPortalConfig::BASE_URL_REFUSED, HdbPortalConfig::baseUrlVerdict());

        Setting::setValue('hdb_base_url', 'https://portal.example.test');
        $this->resolveHostsAs(['93.184.216.34']);
        $this->assertSame(HdbPortalConfig::BASE_URL_POSTABLE, HdbPortalConfig::baseUrlVerdict());
    }

    public function test_the_password_is_returned_untrimmed(): void
    {
        // Leading/trailing whitespace inside a password is part of the password.
        Setting::setEncrypted('hdb_password', '  spaced secret  ');

        $this->assertSame('  spaced secret  ', HdbPortalConfig::password());
    }

    public function test_a_whitespace_only_password_counts_as_no_password(): void
    {
        Setting::setEncrypted('hdb_password', '   ');

        $this->assertNull(HdbPortalConfig::password());
        $this->assertFalse(HdbPortalConfig::isConfigured());
    }

    public function test_is_configured_requires_both_email_and_password(): void
    {
        $this->assertFalse(HdbPortalConfig::isConfigured());

        Setting::setValue('hdb_email', 'reports@example.test');
        $this->assertFalse(HdbPortalConfig::isConfigured());

        Setting::setEncrypted('hdb_password', 'pw');
        $this->assertTrue(HdbPortalConfig::isConfigured());
    }

    public function test_is_configured_does_not_require_a_totp_seed(): void
    {
        // Whether the subaccount has two-factor enrolled is the PORTAL's state.
        // A missing seed has to surface as a login outcome, not as "you never
        // filled the form in" — the two need different operator instructions.
        Setting::setValue('hdb_email', 'reports@example.test');
        Setting::setEncrypted('hdb_password', 'pw');

        $this->assertTrue(HdbPortalConfig::isConfigured());
        $this->assertFalse(HdbPortalConfig::hasTotpSecret());
    }

    public function test_it_generates_a_code_from_the_stored_seed(): void
    {
        Setting::setEncrypted('hdb_totp_secret', 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');

        $this->assertTrue(HdbPortalConfig::hasTotpSecret());

        $before = Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
        $generated = HdbPortalConfig::generateTotp();
        $after = Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');

        $this->assertNotNull($generated);
        $this->assertContains($generated, [$before, $after]);
    }

    public function test_an_undecodable_seed_generates_null_rather_than_throwing(): void
    {
        Setting::setEncrypted('hdb_totp_secret', 'not-base32!');

        $this->assertTrue(HdbPortalConfig::hasTotpSecret());
        $this->assertNull(HdbPortalConfig::generateTotp());
    }

    public function test_no_seed_generates_null(): void
    {
        $this->assertFalse(HdbPortalConfig::hasTotpSecret());
        $this->assertNull(HdbPortalConfig::generateTotp());
    }
}
