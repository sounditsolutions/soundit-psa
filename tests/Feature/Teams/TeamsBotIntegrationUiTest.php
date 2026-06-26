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
}
