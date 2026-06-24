<?php

namespace Tests\Feature\Technician\Emergency;

use App\Models\Setting;
use App\Models\User;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the Phase 2 emergency/escalation/availability/SMS settings UI
 * (IntegrationsController::updateTechnician + the index view vars).
 *
 * CO-3: per-operator phone map persisted via technician_operator_phones.
 * CO-4: send_max_hold tier rebuilt from checkbox; send_ack tier preserved.
 */
class EmergencySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_saves_escalation_and_emergency_config(): void
    {
        $user = User::factory()->create();
        $justin = User::factory()->create();

        $this->actingAs($user)->post(route('settings.integrations.technician.update'), [
            'technician_enabled' => '1',
            'technician_escalation_chain' => [(string) $justin->id, (string) $user->id],
            'technician_escalation_timeout' => '20',
            'technician_emergency_keywords' => "down\noutage\nransomware",
            'technician_max_hold_message' => 'We are on it.',
            'technician_max_hold_auto' => '1',
        ])->assertRedirect();

        $this->assertSame([$justin->id, $user->id], TechnicianConfig::escalationChain());
        $this->assertSame(20, TechnicianConfig::escalationTimeoutMinutes());
        $this->assertContains('ransomware', TechnicianConfig::emergencyKeywords());
        $this->assertSame('We are on it.', TechnicianConfig::maxHoldMessage());
        $this->assertSame('auto', TechnicianConfig::tierMap()['send_max_hold'] ?? null);
    }

    /** CO-4: auto-ack checkbox and max-hold checkbox together → both keys in tier map. */
    public function test_co4_both_auto_tiers_persisted_together(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('settings.integrations.technician.update'), [
            'technician_enabled' => '1',
            'technician_auto_ack' => '1',
            'technician_max_hold_auto' => '1',
        ])->assertRedirect();

        $this->assertSame('auto', TechnicianConfig::tierMap()['send_ack'] ?? null);
        $this->assertSame('auto', TechnicianConfig::tierMap()['send_max_hold'] ?? null);
    }

    /** CO-4: unchecking max-hold removes only send_max_hold; send_ack is unaffected. */
    public function test_co4_unchecking_max_hold_only_removes_send_max_hold(): void
    {
        $user = User::factory()->create();

        // seed both tiers
        Setting::setValue('technician_action_tiers', json_encode(['send_ack' => 'auto', 'send_max_hold' => 'auto']));

        $this->actingAs($user)->post(route('settings.integrations.technician.update'), [
            'technician_enabled' => '1',
            'technician_auto_ack' => '1',
            // no technician_max_hold_auto
        ])->assertRedirect();

        $this->assertSame('auto', TechnicianConfig::tierMap()['send_ack'] ?? null);
        $this->assertArrayNotHasKey('send_max_hold', TechnicianConfig::tierMap());
    }

    /** CO-3: operator phone number saved and retrieved via TechnicianConfig. */
    public function test_co3_operator_phone_persisted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('settings.integrations.technician.update'), [
            'technician_enabled' => '1',
            'technician_operator_phones' => [(string) $user->id => '+15555551234'],
        ])->assertRedirect();

        $this->assertSame('+15555551234', TechnicianConfig::operatorPhone($user->id));
    }

    /** CO-3: blank phone entry is removed (not stored as empty string). */
    public function test_co3_blank_phone_entry_is_removed(): void
    {
        $user = User::factory()->create();

        // seed a phone first
        Setting::setValue('technician_operator_phones', json_encode([(string) $user->id => '+15555559999']));

        $this->actingAs($user)->post(route('settings.integrations.technician.update'), [
            'technician_enabled' => '1',
            'technician_operator_phones' => [(string) $user->id => ''],
        ])->assertRedirect();

        $this->assertNull(TechnicianConfig::operatorPhone($user->id));
    }

    /** Emergency keywords textarea (newline-delimited) → JSON array. */
    public function test_emergency_keywords_parsed_from_textarea(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('settings.integrations.technician.update'), [
            'technician_emergency_keywords' => "down\n outage \nransomware",
        ])->assertRedirect();

        $keywords = TechnicianConfig::emergencyKeywords();
        $this->assertContains('down', $keywords);
        $this->assertContains('outage', $keywords);
        $this->assertContains('ransomware', $keywords);
    }

    /** Other numeric Phase 2 settings persist correctly. */
    public function test_numeric_phase2_settings_persisted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('settings.integrations.technician.update'), [
            'technician_escalation_timeout' => '25',
            'technician_emergency_reping' => '45',
            'technician_storm_window' => '10',
        ])->assertRedirect();

        $this->assertSame(25, TechnicianConfig::escalationTimeoutMinutes());
        $this->assertSame(45, TechnicianConfig::emergencyRepingMinutes());
        $this->assertSame(10, TechnicianConfig::stormWindowMinutes());
    }

    /** Max-hold message persisted as-is. */
    public function test_max_hold_message_persisted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('settings.integrations.technician.update'), [
            'technician_max_hold_message' => 'Heads up — we are on it!',
        ])->assertRedirect();

        $this->assertSame('Heads up — we are on it!', TechnicianConfig::maxHoldMessage());
    }

    /** Escalation chain order is preserved. */
    public function test_escalation_chain_order_preserved(): void
    {
        $user = User::factory()->create();
        $justin = User::factory()->create();

        $this->actingAs($user)->post(route('settings.integrations.technician.update'), [
            'technician_escalation_chain' => [(string) $justin->id, (string) $user->id],
        ])->assertRedirect();

        $this->assertSame([$justin->id, $user->id], TechnicianConfig::escalationChain());
    }

    /** Per-user availability map persisted. */
    public function test_operator_availability_persisted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('settings.integrations.technician.update'), [
            'technician_operator_availability' => [(string) $user->id => '1'],
        ])->assertRedirect();

        $this->assertTrue(TechnicianConfig::operatorAvailable($user->id));
    }

    /** Emergency age minutes p1–p4 map persisted. */
    public function test_emergency_age_minutes_persisted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('settings.integrations.technician.update'), [
            'technician_emergency_age_minutes' => ['p1' => '10', 'p2' => '30', 'p3' => '120', 'p4' => '720'],
        ])->assertRedirect();

        $this->assertSame(10, TechnicianConfig::emergencyAgeMinutes(\App\Enums\TicketPriority::P1));
        $this->assertSame(30, TechnicianConfig::emergencyAgeMinutes(\App\Enums\TicketPriority::P2));
        $this->assertSame(120, TechnicianConfig::emergencyAgeMinutes(\App\Enums\TicketPriority::P3));
        $this->assertSame(720, TechnicianConfig::emergencyAgeMinutes(\App\Enums\TicketPriority::P4));
    }
}
