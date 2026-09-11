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
