<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\TeamsPersona;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the consolidated "AI & Automation" integrations panel (psa-bv2j).
 *
 * The panel groups the AI cards into Foundation / Ticket intelligence (PSA-native)
 * / Autonomous & conversational (superseded by GC Chet), explains the GC Chet
 * relationship, and surfaces a live summary of which Chet-superseded PSA-native
 * features are still switched on. This is a presentation change over the existing
 * per-card forms — the assertions target the new orientation copy and the
 * data-driven summary, not any new write path.
 *
 * Auth gate: settings routes live inside the 'auth' middleware group, so
 * actingAs($user) with any valid user is all that is required.
 */
class AiIntegrationPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_panel_is_grouped_and_explains_gc_chet_supersession(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk();

        // The three explained groups.
        $response->assertSee('Foundation');
        $response->assertSee('Ticket intelligence');
        $response->assertSee('Autonomous');

        // The orientation banner names GC Chet and points at the MCP token surface
        // (Chet's connection point — the companion control panel, psa-15az).
        $response->assertSee('Running GC Chet?');
        $response->assertSee(route('settings.mcp-tokens.index'));

        // The supersession flag the operator is meant to act on.
        $response->assertSee('Superseded by GC Chet');
        $response->assertSee('PSA-native');
    }

    public function test_summary_reads_chet_has_the_floor_when_no_native_feature_is_on(): void
    {
        // Clean DB: technician off, teams bot off, no personas.
        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('GC Chet has the floor')
            ->assertDontSee('Still enabled:');
    }

    public function test_summary_lists_every_enabled_superseded_feature(): void
    {
        Setting::setValue('technician_enabled', '1');
        Setting::setValue('teams_bot_enabled', '1');

        $response = $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk();

        $response->assertSee('Still enabled:');
        // The joined feature list is unique to the summary strip.
        $response->assertSee('AI Technician, Teams Bot');
        // Plural guidance for two-or-more features. The "below" suffix is unique to
        // the summary strip — the always-on banner uses "turn them off so …".
        $response->assertSee('turn them off below');
        $response->assertDontSee('GC Chet has the floor');
    }

    public function test_summary_uses_singular_guidance_for_a_lone_feature(): void
    {
        // The emergency backstop alone still counts the Technician as "on".
        Setting::setValue('technician_emergency_enabled', '1');

        // "below" is unique to the summary strip; the banner uses "turn them off so …".
        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('Still enabled:')
            ->assertSee('turn it off below')
            ->assertDontSee('turn them off below');
    }

    public function test_summary_counts_an_enabled_teams_persona(): void
    {
        TeamsPersona::create([
            'persona_key' => 'ada',
            'display_name' => 'Ada',
            'bot_app_id' => '33333333-3333-3333-3333-333333333333',
            'enabled' => true,
        ]);

        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('Still enabled:')
            ->assertSee('AI Staff personas'); // the phrase is unique to the summary strip
    }

    public function test_a_dormant_persona_does_not_trip_the_summary(): void
    {
        TeamsPersona::create([
            'persona_key' => 'grace',
            'display_name' => 'Grace',
            'bot_app_id' => '44444444-4444-4444-4444-444444444444',
            'enabled' => false,
        ]);

        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('GC Chet has the floor')
            ->assertDontSee('AI Staff personas');
    }
}
