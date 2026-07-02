<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Covers the "AI Technician" integrations-page toggle (feat/ai-technician-toggle).
 *
 * Auth gate: settings routes live inside Route::middleware('auth')->group(),
 * so actingAs($user) with any valid user is all that is required.
 */
class TechnicianIntegrationToggleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    // --- technician_enabled ---

    public function test_posting_with_technician_enabled_enables_the_technician(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.technician.update'), [
                'technician_enabled' => '1',
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertTrue(TechnicianConfig::enabled());
    }

    public function test_posting_without_technician_enabled_disables_the_technician(): void
    {
        Setting::setValue('technician_enabled', '1');

        $this->actingAs($this->user)
            ->post(route('settings.integrations.technician.update'), [])
            ->assertRedirect(route('settings.integrations'));

        $this->assertFalse(TechnicianConfig::enabled());
    }

    public function test_posting_with_emergency_enabled_only_leaves_the_draft_technician_disabled(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.technician.update'), [
                'technician_emergency_enabled' => '1',
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertFalse(TechnicianConfig::enabled());
        $this->assertTrue(TechnicianConfig::emergencyEnabled());
    }

    // --- technician_action_tiers / auto-ack ---

    public function test_posting_with_auto_ack_sets_send_ack_to_auto(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.technician.update'), [
                'technician_enabled' => '1',
                'technician_auto_ack' => '1',
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('auto', TechnicianConfig::tierMap()['send_ack'] ?? null);
    }

    public function test_posting_without_auto_ack_clears_send_ack(): void
    {
        Setting::setValue('technician_action_tiers', json_encode(['send_ack' => 'auto']));

        $this->actingAs($this->user)
            ->post(route('settings.integrations.technician.update'), [
                'technician_enabled' => '1',
                // no technician_auto_ack
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertArrayNotHasKey('send_ack', TechnicianConfig::tierMap());
    }

    // --- coverage-start anchor (psa-wmqp): stamp on OFF→ON, clear on disable ---

    public function test_enabling_off_to_on_stamps_coverage_start(): void
    {
        $this->assertNull(TechnicianConfig::coverageStartAt());

        $this->actingAs($this->user)
            ->post(route('settings.integrations.technician.update'), [
                'technician_enabled' => '1',
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertTrue(TechnicianConfig::enabled());
        $this->assertNotNull(TechnicianConfig::coverageStartAt(), 'enabling stamps the coverage anchor');
    }

    public function test_disabling_clears_coverage_start(): void
    {
        Setting::setValue('technician_enabled', '1');
        TechnicianConfig::recordCoverageStart();
        $this->assertNotNull(TechnicianConfig::coverageStartAt());

        $this->actingAs($this->user)
            ->post(route('settings.integrations.technician.update'), [])
            ->assertRedirect(route('settings.integrations'));

        $this->assertFalse(TechnicianConfig::enabled());
        $this->assertNull(TechnicianConfig::coverageStartAt(), 'disabling clears the anchor so a later enable re-anchors fresh');
    }

    public function test_saving_while_already_enabled_does_not_re_anchor_coverage_start(): void
    {
        // Already covering since three days ago; an unrelated in-place settings save
        // must NOT reset the window — only an OFF→ON transition re-anchors.
        Setting::setValue('technician_enabled', '1');
        $anchor = Carbon::parse('2026-06-23 09:00:00');
        Setting::setValue('technician_coverage_start_at', $anchor->toIso8601String());

        $this->actingAs($this->user)
            ->post(route('settings.integrations.technician.update'), [
                'technician_enabled' => '1',
                'technician_digest_time' => '09:30', // an unrelated field change
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertTrue(TechnicianConfig::enabled());
        $this->assertTrue(TechnicianConfig::coverageStartAt()->equalTo($anchor), 'an in-place save must not move the coverage anchor');
    }

    public function test_enabling_emergency_only_stamps_coverage_start(): void
    {
        $this->assertNull(TechnicianConfig::coverageStartAt());

        $this->actingAs($this->user)
            ->post(route('settings.integrations.technician.update'), [
                'technician_emergency_enabled' => '1',
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertFalse(TechnicianConfig::enabled());
        $this->assertTrue(TechnicianConfig::emergencyEnabled());
        $this->assertNotNull(TechnicianConfig::coverageStartAt(), 'emergency-only coverage stamps the same age anchor');
    }

    public function test_disabling_emergency_only_clears_coverage_start(): void
    {
        Setting::setValue('technician_emergency_enabled', '1');
        TechnicianConfig::recordCoverageStart();

        $this->actingAs($this->user)
            ->post(route('settings.integrations.technician.update'), [])
            ->assertRedirect(route('settings.integrations'));

        $this->assertFalse(TechnicianConfig::enabled());
        $this->assertFalse(TechnicianConfig::emergencyEnabled());
        $this->assertNull(TechnicianConfig::coverageStartAt(), 'coverage anchor clears once both technician and emergency backstop are off');
    }

    public function test_switching_from_technician_to_emergency_only_preserves_coverage_start(): void
    {
        Setting::setValue('technician_enabled', '1');
        $anchor = Carbon::parse('2026-06-23 09:00:00');
        Setting::setValue('technician_coverage_start_at', $anchor->toIso8601String());

        $this->actingAs($this->user)
            ->post(route('settings.integrations.technician.update'), [
                'technician_emergency_enabled' => '1',
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertFalse(TechnicianConfig::enabled());
        $this->assertTrue(TechnicianConfig::emergencyEnabled());
        $this->assertTrue(TechnicianConfig::coverageStartAt()->equalTo($anchor), 'coverage remains anchored while the emergency plane stays on');
    }

    // --- flash message ---

    public function test_save_flashes_success_message(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.technician.update'), [])
            ->assertSessionHas('success', 'AI Technician settings saved.');
    }

    // --- page renders the card ---

    public function test_integrations_page_renders_ai_technician_card(): void
    {
        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('AI Technician')
            ->assertSee('Enable emergency backstop')
            ->assertSee('Teams webhook URL')
            ->assertSee('Notify email');
    }
}
