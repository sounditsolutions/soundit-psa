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

    public function test_enabled_is_strict_fail_closed_only_exact_1_enables(): void
    {
        // Fail-closed like NinjaConfig/ZorusConfig (=== '1'). A stray or legacy value must NEVER
        // enable a toolset that rides a tenant-wide Graph token: a (bool) cast treats the string
        // "false" (and "true", "2", …) as TRUE, which is the opposite of fail-closed (psa-abl0i.2).
        foreach (['false', 'true', '0', 'no', 'off', '2', ' 1', ''] as $value) {
            Setting::setValue('calendar_enabled', $value);
            $this->assertFalse(CalendarConfig::isEnabled(), "calendar_enabled='{$value}' must not enable the toolset");
        }

        Setting::setValue('calendar_enabled', '1');
        $this->assertTrue(CalendarConfig::isEnabled());
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

    public function test_a_json_object_allowlist_is_rejected_fail_closed(): void
    {
        // psa-abl0i.5 SPINE re-review: a stored JSON OBJECT decodes to an associative PHP array;
        // array_values() would silently admit its VALUES as if they were a list. The sole mailbox
        // boundary must not be widened by a non-list shape — an object denies everyone.
        Setting::setValue('calendar_allowed_owner_upns', json_encode(['mailbox' => 'billing@soundit.co']));

        $this->assertSame([], CalendarConfig::allowedOwnerUpns());
        $this->assertFalse(CalendarConfig::ownerUpnAllowed('billing@soundit.co'));
    }

    public function test_a_non_string_member_denies_the_whole_allowlist(): void
    {
        // One malformed member denies the WHOLE value — a partial list from corrupt/legacy storage
        // is not a trustworthy allowlist, and must never admit its well-formed siblings.
        Setting::setValue('calendar_allowed_owner_upns', json_encode(['charlie@soundit.co', 123]));

        $this->assertSame([], CalendarConfig::allowedOwnerUpns());
        $this->assertFalse(CalendarConfig::ownerUpnAllowed('charlie@soundit.co'));
    }

    public function test_a_nested_or_blank_member_denies_the_whole_allowlist(): void
    {
        Setting::setValue('calendar_allowed_owner_upns', json_encode(['charlie@soundit.co', ['nested@soundit.co']]));
        $this->assertSame([], CalendarConfig::allowedOwnerUpns());

        Setting::setValue('calendar_allowed_owner_upns', json_encode(['charlie@soundit.co', '   ']));
        $this->assertSame([], CalendarConfig::allowedOwnerUpns());
        $this->assertFalse(CalendarConfig::ownerUpnAllowed('charlie@soundit.co'));
    }

    public function test_a_numeric_key_json_object_is_rejected_fail_closed(): void
    {
        // psa-abl0i.7 SPINE re-review: the assoc-mode decode + array_is_list() check STILL admits a
        // numeric-key JSON OBJECT. json_decode('{"0":"billing@.."}', true) => [0 => "billing@.."],
        // which array_is_list() calls a list — so the SOLE mailbox boundary was silently widened by a
        // shape that is not a JSON array at all. Identity-preserving object-mode decode keeps a JSON
        // object a stdClass (is_array() === false), so it is denied. Stored as a literal to be
        // unambiguous: a numeric string key on a PHP array would be re-cast to int and json_encode to
        // an array, hiding the very case under test.
        Setting::setValue('calendar_allowed_owner_upns', '{"0":"billing@soundit.co"}');

        $this->assertSame([], CalendarConfig::allowedOwnerUpns());
        $this->assertFalse(CalendarConfig::ownerUpnAllowed('billing@soundit.co'));
    }

    public function test_an_empty_json_object_is_denied_as_a_non_list(): void
    {
        // {} is not an empty allowlist — it is a non-list shape and must be denied as such, distinct
        // from a genuine empty JSON list [] (a legitimate, fail-closed empty allowlist). Both yield []
        // by outcome, but only the object path is a *rejection*; this pins the distinction.
        Setting::setValue('calendar_allowed_owner_upns', '{}');
        $this->assertSame([], CalendarConfig::allowedOwnerUpns());

        Setting::setValue('calendar_allowed_owner_upns', '[]');
        $this->assertSame([], CalendarConfig::allowedOwnerUpns());
    }

    public function test_an_invalid_upn_member_denies_the_whole_allowlist(): void
    {
        // psa-abl0i.7 SPINE re-review: the read seam accepted EVERY non-blank string, including a
        // non-UPN like "not-a-valid-upn". R1 required valid/normalized UPN members — the Settings
        // email rule cannot be the only proof (corrupt/legacy/hand-edited storage bypasses the writer).
        // One invalid member denies the WHOLE stored value.
        Setting::setValue('calendar_allowed_owner_upns', json_encode(['charlie@soundit.co', 'not-a-valid-upn']));

        $this->assertSame([], CalendarConfig::allowedOwnerUpns());
        $this->assertFalse(CalendarConfig::ownerUpnAllowed('charlie@soundit.co'));
    }

    public function test_a_padded_valid_member_is_normalized_on_return(): void
    {
        // A valid UPN with surrounding whitespace is normalized (trimmed) on the way out, so the
        // returned list is the clean, canonical allowlist R1 asked for.
        Setting::setValue('calendar_allowed_owner_upns', json_encode(['  charlie@soundit.co  ']));

        $this->assertSame(['charlie@soundit.co'], CalendarConfig::allowedOwnerUpns());
    }

    public function test_a_well_formed_json_list_is_still_accepted(): void
    {
        Setting::setValue('calendar_allowed_owner_upns', json_encode(['charlie@soundit.co', 'justin@soundit.co']));

        $this->assertSame(['charlie@soundit.co', 'justin@soundit.co'], CalendarConfig::allowedOwnerUpns());
        $this->assertTrue(CalendarConfig::ownerUpnAllowed('justin@soundit.co'));
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
