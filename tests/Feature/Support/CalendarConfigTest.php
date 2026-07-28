<?php

namespace Tests\Feature\Support;

use App\Models\Setting;
use App\Support\CalendarConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The calendar UPN allowlist is the SECURITY SPINE of the calendar toolset (psa-abl0i):
 * Charlie dropped the Azure Application Access Policy, so this server-side allowlist is now
 * the ONLY constraint on which mailboxes the tenant-wide token may act as owner/organizer for.
 * It MUST fail closed — an empty/unset list denies everyone — and match case-insensitively
 * (UPNs are email-like). These tests exercise that membership logic directly.
 */
class CalendarConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_by_default_off_equals_off(): void
    {
        // No setting written -> the toolset is OFF (never live on upgrade without an explicit opt-in).
        $this->assertFalse(CalendarConfig::isEnabled());
    }

    public function test_enabled_only_when_the_flag_is_truthy(): void
    {
        Setting::setValue('calendar_enabled', '1');
        $this->assertTrue(CalendarConfig::isEnabled());

        Setting::setValue('calendar_enabled', '0');
        $this->assertFalse(CalendarConfig::isEnabled());
    }

    public function test_allowed_owner_upns_is_empty_when_unset(): void
    {
        $this->assertSame([], CalendarConfig::allowedOwnerUpns());
    }

    public function test_allowed_owner_upns_decodes_the_json_list(): void
    {
        Setting::setValue('calendar_allowed_owner_upns', json_encode(['charlie@soundit.co', 'justin@soundit.co']));

        $this->assertSame(['charlie@soundit.co', 'justin@soundit.co'], CalendarConfig::allowedOwnerUpns());
    }

    public function test_owner_upn_allowed_matches_a_listed_upn(): void
    {
        Setting::setValue('calendar_allowed_owner_upns', json_encode(['charlie@soundit.co', 'justin@soundit.co']));

        $this->assertTrue(CalendarConfig::ownerUpnAllowed('charlie@soundit.co'));
        $this->assertTrue(CalendarConfig::ownerUpnAllowed('justin@soundit.co'));
    }

    public function test_owner_upn_allowed_is_case_insensitive_and_trims(): void
    {
        // UPNs are email-like — case-insensitive. Surrounding whitespace must not defeat the match
        // in either direction (a padded stored entry or a padded lookup).
        Setting::setValue('calendar_allowed_owner_upns', json_encode(['Charlie@SoundIT.co', '  dispatch@soundit.co  ']));

        $this->assertTrue(CalendarConfig::ownerUpnAllowed('charlie@soundit.co'));
        $this->assertTrue(CalendarConfig::ownerUpnAllowed('  CHARLIE@soundit.CO '));
        $this->assertTrue(CalendarConfig::ownerUpnAllowed('dispatch@soundit.co'));
    }

    public function test_owner_upn_not_on_the_list_is_refused(): void
    {
        Setting::setValue('calendar_allowed_owner_upns', json_encode(['charlie@soundit.co']));

        $this->assertFalse(CalendarConfig::ownerUpnAllowed('attacker@evil.example'));
        $this->assertFalse(CalendarConfig::ownerUpnAllowed('billing@soundit.co'), 'an internal-but-unlisted mailbox is still refused');
    }

    public function test_empty_allowlist_denies_everyone_fail_closed(): void
    {
        // The load-bearing invariant: with no allowlist configured, NO mailbox is an allowed owner.
        // A calendar owner/organizer/respond-as must never be permitted by an unset list.
        $this->assertFalse(CalendarConfig::ownerUpnAllowed('charlie@soundit.co'));

        Setting::setValue('calendar_allowed_owner_upns', json_encode([]));
        $this->assertFalse(CalendarConfig::ownerUpnAllowed('charlie@soundit.co'));
    }

    public function test_blank_or_malformed_upn_input_is_refused(): void
    {
        Setting::setValue('calendar_allowed_owner_upns', json_encode(['charlie@soundit.co']));

        $this->assertFalse(CalendarConfig::ownerUpnAllowed(''));
        $this->assertFalse(CalendarConfig::ownerUpnAllowed('   '));
    }

    public function test_malformed_allowlist_json_denies_everyone(): void
    {
        // A corrupt/non-array setting must not throw and must not accidentally admit anyone.
        Setting::setValue('calendar_allowed_owner_upns', 'not json at all');

        $this->assertSame([], CalendarConfig::allowedOwnerUpns());
        $this->assertFalse(CalendarConfig::ownerUpnAllowed('charlie@soundit.co'));
    }

    /**
     * isAvailable() is the OFF=OFF publication predicate the MCP tool surface gates on:
     * the toolset is live ONLY when it is both switched on AND the underlying Microsoft
     * Graph transport is configured. A missing Graph app credential means the executor
     * could never actually reach a calendar, so the tools must not publish (mirrors every
     * other vendor's isAvailable()).
     */
    private function configureGraph(bool $configured = true): void
    {
        config(['services.graph' => $configured ? [
            'tenant_id' => 'tenant-uuid',
            'client_id' => 'client-uuid',
            'client_secret' => 'shhh',
        ] : [
            'tenant_id' => null,
            'client_id' => null,
            'client_secret' => null,
        ]]);
    }

    public function test_is_available_requires_enabled_and_graph_configured(): void
    {
        Setting::setValue('calendar_enabled', '1');
        $this->configureGraph(true);

        $this->assertTrue(CalendarConfig::isAvailable());
    }

    public function test_is_available_is_false_when_graph_is_not_configured(): void
    {
        Setting::setValue('calendar_enabled', '1');
        $this->configureGraph(false);

        // Switched on but no Graph credentials — the executor could never reach Graph,
        // so the toolset is NOT live (unavailable_config, not granted).
        $this->assertFalse(CalendarConfig::isAvailable());
    }

    public function test_is_available_is_false_when_disabled_even_with_graph_configured(): void
    {
        Setting::setValue('calendar_enabled', '0');
        $this->configureGraph(true);

        // The master switch wins: OFF means the tools do not publish regardless of config.
        $this->assertFalse(CalendarConfig::isAvailable());
    }
}
