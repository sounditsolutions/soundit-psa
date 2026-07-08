<?php

namespace Tests\Feature\Teams;

use App\Models\Setting;
use App\Models\User;
use App\Support\TeamsBotConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Integrations-page "Teams Bot Credentials" panel (E1). The operator enters
 * the App ID + tenant ID (plain) and the Entra client secret (masked, write-only,
 * encrypted at rest — exactly like the existing encrypted Teams webhook field).
 */
class TeamsBotIntegrationUiTest extends TestCase
{
    use RefreshDatabase;

    private const MASK = '••••••••';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_saving_credentials_persists_and_encrypts_the_secret(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.teams-bot.update'), [
                'teams_bot_app_id' => '11111111-1111-1111-1111-111111111111',
                'teams_bot_tenant_id' => '22222222-2222-2222-2222-222222222222',
                'teams_bot_client_secret' => 'the-entra-secret',
                'teams_bot_enabled' => '1',
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('11111111-1111-1111-1111-111111111111', TeamsBotConfig::appId());
        $this->assertSame('22222222-2222-2222-2222-222222222222', TeamsBotConfig::tenantId());
        $this->assertSame('the-entra-secret', TeamsBotConfig::clientSecret());
        $this->assertTrue(TeamsBotConfig::enabled());

        // Encrypted at rest.
        $this->assertNotSame('the-entra-secret', Setting::where('key', 'teams_bot_client_secret')->value('value'));
    }

    public function test_blank_secret_submit_keeps_the_existing_secret(): void
    {
        TeamsBotConfig::setClientSecret('original-secret');

        $this->actingAs($this->user)
            ->post(route('settings.integrations.teams-bot.update'), [
                'teams_bot_app_id' => 'app',
                'teams_bot_tenant_id' => 'tenant',
                'teams_bot_client_secret' => '', // blank ⇒ keep existing
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('original-secret', TeamsBotConfig::clientSecret());
    }

    public function test_masked_secret_submit_keeps_the_existing_secret(): void
    {
        TeamsBotConfig::setClientSecret('original-secret');

        $this->actingAs($this->user)
            ->post(route('settings.integrations.teams-bot.update'), [
                'teams_bot_app_id' => 'app',
                'teams_bot_tenant_id' => 'tenant',
                'teams_bot_client_secret' => self::MASK, // the echoed mask ⇒ keep existing
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('original-secret', TeamsBotConfig::clientSecret());
    }

    public function test_unchecking_enable_disables_the_bridge(): void
    {
        Setting::setValue('teams_bot_enabled', '1');

        $this->actingAs($this->user)
            ->post(route('settings.integrations.teams-bot.update'), [
                'teams_bot_app_id' => 'app',
                'teams_bot_tenant_id' => 'tenant',
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertFalse(TeamsBotConfig::enabled());
    }

    public function test_integrations_page_renders_the_panel_and_never_leaks_the_secret(): void
    {
        Setting::setValue('teams_bot_app_id', '11111111-1111-1111-1111-111111111111');
        TeamsBotConfig::setClientSecret('top-secret-value');

        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('Teams Bot')
            ->assertDontSee('top-secret-value'); // never render the raw secret
    }

    // ── psa-i4cf: ambient "culture" controls ─────────────────────────────────

    public function test_panel_renders_the_ambient_controls_prepopulated(): void
    {
        // The live-tuned values that must be surfaced (not changed).
        Setting::setValue('teams_ambient_enabled', '1');
        Setting::setValue('teams_ambient_eagerness', 'high');
        Setting::setValue('teams_ambient_banter', '1');
        Setting::setValue('teams_ambient_cooldown_seconds', '90');

        $response = $this->actingAs($this->user)->get(route('settings.integrations'))->assertOk();

        // The four operator controls exist, framed as culture.
        $response->assertSee('Ambient participation');
        $response->assertSee('teams_ambient_enabled', false);
        $response->assertSee('teams_ambient_eagerness', false);
        $response->assertSee('teams_ambient_banter', false);
        $response->assertSee('teams_ambient_cooldown_seconds', false);
        // Operator-friendly eagerness labels.
        $response->assertSee('Reserved');
        $response->assertSee('Balanced');
        $response->assertSee('Eager');
        // Pre-populated from the live settings: the cooldown shows 90 and the current
        // eagerness ('high') is the selected option.
        $response->assertSee('value="90"', false);
        $response->assertSee('value="high" selected', false);
    }

    public function test_saving_persists_the_ambient_dials(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.teams-bot.update'), [
                'teams_ambient_enabled' => '1',
                'teams_ambient_eagerness' => 'high',
                'teams_ambient_banter' => '1',
                'teams_ambient_cooldown_seconds' => '90',
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertTrue(TeamsBotConfig::ambientEnabled());
        $this->assertSame('high', TeamsBotConfig::ambientEagerness());
        $this->assertTrue(TeamsBotConfig::ambientBanter());
        $this->assertSame(90, TeamsBotConfig::ambientCooldownSeconds());
    }

    public function test_unchecking_the_ambient_toggles_disables_them(): void
    {
        Setting::setValue('teams_ambient_enabled', '1');
        Setting::setValue('teams_ambient_banter', '1');

        $this->actingAs($this->user)
            ->post(route('settings.integrations.teams-bot.update'), [
                'teams_ambient_eagerness' => 'normal',
                'teams_ambient_cooldown_seconds' => '60',
                // no teams_ambient_enabled, no teams_ambient_banter
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertFalse(TeamsBotConfig::ambientEnabled());
        $this->assertFalse(TeamsBotConfig::ambientBanter());
    }

    public function test_invalid_eagerness_falls_back_to_normal(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.teams-bot.update'), [
                'teams_ambient_eagerness' => 'bananas',
                'teams_ambient_cooldown_seconds' => '60',
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('normal', TeamsBotConfig::ambientEagerness());
    }

    public function test_cooldown_is_clamped_to_a_sane_maximum(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.teams-bot.update'), [
                'teams_ambient_eagerness' => 'normal',
                'teams_ambient_cooldown_seconds' => '99999',
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame(3600, TeamsBotConfig::ambientCooldownSeconds());
    }

    public function test_cooldown_floor_and_absent_eagerness_default(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.teams-bot.update'), [
                'teams_ambient_cooldown_seconds' => '0', // below the reader's floor
                // no teams_ambient_eagerness submitted
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame(5, TeamsBotConfig::ambientCooldownSeconds()); // reader floors at 5
        $this->assertSame('normal', TeamsBotConfig::ambientEagerness()); // absent → normal
    }
}
