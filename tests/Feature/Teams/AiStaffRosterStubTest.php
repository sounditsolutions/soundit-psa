<?php

namespace Tests\Feature\Teams;

use App\Models\TeamsPersona;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The read-only "AI Staff" roster stub on Settings > Integrations (Teams
 * AI-Staff Personas P1 Task 5). Hand-registered personas (Gus first) are
 * listed with SAFE fields only — no create/edit/delete forms, no secret
 * field. The provisioning wizard arrives in P2; this is a feel-test stub.
 */
class AiStaffRosterStubTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * LOAD-BEARING: the roster must never leak bot_client_secret, neither the
     * plaintext submitted at create time nor the encrypted ciphertext stored
     * at rest.
     */
    public function test_secret_is_never_rendered_on_the_integrations_page(): void
    {
        $persona = TeamsPersona::create([
            'persona_key' => 'gus',
            'display_name' => 'Gus',
            'role_blurb' => 'Helpdesk-voiced AI staffer',
            'bot_app_id' => 'gus-app',
            'bot_client_secret' => 'super-secret-value',
            'enabled' => true,
        ]);

        // The raw, still-encrypted column value — must not leak either.
        $ciphertext = DB::table('teams_personas')->where('id', $persona->id)->value('bot_client_secret');
        $this->assertNotEmpty($ciphertext);
        $this->assertNotSame('super-secret-value', $ciphertext);

        $response = $this->actingAs($this->user)->get(route('settings.integrations'));

        $response->assertOk();
        $response->assertSee('Gus');
        $response->assertSee('Persona enabled');
        $response->assertSee('Secret set');
        $response->assertDontSee('super-secret-value');
        $response->assertDontSee($ciphertext, false);
    }

    public function test_enabled_and_disabled_personas_render_with_correct_badges(): void
    {
        TeamsPersona::create([
            'persona_key' => 'gus',
            'display_name' => 'Gus',
            'role_blurb' => 'Helpdesk-voiced AI staffer',
            'bot_app_id' => 'gus-app',
            'enabled' => true,
        ]);
        TeamsPersona::create([
            'persona_key' => 'dormant-one',
            'display_name' => 'Dormant One',
            'role_blurb' => 'Not yet live',
            'bot_app_id' => 'dormant-app',
            'enabled' => false,
        ]);

        $response = $this->actingAs($this->user)->get(route('settings.integrations'));

        $response->assertOk();
        $response->assertSee('Gus');
        $response->assertSee('Dormant One');
        $response->assertSee('Persona enabled');
        $response->assertSee('Persona dormant');
        $response->assertSee('No secret');
    }

    public function test_empty_state_shown_when_no_personas_exist(): void
    {
        $response = $this->actingAs($this->user)->get(route('settings.integrations'));

        $response->assertOk();
        $response->assertSee('No AI-Staff personas yet');
    }
}
