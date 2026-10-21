<?php

namespace Tests\Feature\Settings;

use App\Models\CarbonMcpTool;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Carbon\FakesCarbonMcp;
use Tests\TestCase;

/** The Carbon Connect card on Settings > Integrations (card PEYvh4NL). */
class CarbonMcpSettingsTest extends TestCase
{
    use FakesCarbonMcp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_disconnected_card_offers_connect_only(): void
    {
        $html = $this->actingAs(User::factory()->admin()->create())->get(route('settings.integrations'))->assertOk()->getContent();

        $this->assertStringContainsString('id="carbon-mcp-card" data-state="not_connected"', $html);
        $this->assertStringContainsString('Connect Carbon', $html);
        $this->assertStringContainsString(route('auth.carbon-mcp'), $html);
        $this->assertStringNotContainsString('id="carbon-mcp-sync-btn"', $html);
        $this->assertStringNotContainsString('id="carbon-mcp-signout-btn"', $html);
        Http::assertNothingSent();
    }

    public function test_connected_card_shows_who_scopes_catalogue_and_actions(): void
    {
        $this->seedConnected();
        Setting::setValue('carbon_mcp_connected_by', 'Alex Example');
        Setting::setValue('carbon_mcp_last_sync_at', '2026-10-21T18:00:00+00:00');
        CarbonMcpTool::create(['local_name' => 'carbon_a', 'upstream_name' => 'a', 'read_only' => true, 'sensitive' => false, 'active' => true]);
        CarbonMcpTool::create(['local_name' => 'carbon_b', 'upstream_name' => 'b', 'read_only' => false, 'sensitive' => true, 'active' => true]);
        CarbonMcpTool::create(['local_name' => 'carbon_c', 'upstream_name' => 'c', 'read_only' => false, 'sensitive' => true, 'active' => false]);

        $html = $this->actingAs(User::factory()->admin()->create())->get(route('settings.integrations'))->assertOk()->getContent();

        $this->assertStringContainsString('data-state="connected"', $html);
        $this->assertStringContainsString('Alex Example', $html);
        foreach (['mcp:read', 'mcp:products:write', 'mcp:orders:draft', 'mcp:companies:write', 'mcp:customers:write'] as $scope) {
            $this->assertStringContainsString('<code class="me-1">'.$scope.'</code>', $html);
        }
        $this->assertStringContainsString('2 active tools (1 read-only, 1 write)', $html);
        $this->assertStringContainsString('Reconnect Carbon', $html);
        $this->assertStringContainsString('id="carbon-mcp-sync-btn"', $html);
        $this->assertStringContainsString('id="carbon-mcp-signout-btn"', $html);
        $this->assertStringContainsString('id="carbon_mcp_enabled" checked', $html);
        $this->assertStringNotContainsString(self::REFRESH, $html);
        $this->assertStringNotContainsString(self::CLIENT_SECRET_CANARY, $html);
    }

    public function test_a_non_admin_sees_status_but_no_connect_sync_or_sign_out(): void
    {
        $this->seedConnected();
        CarbonMcpTool::create(['local_name' => 'carbon_a', 'upstream_name' => 'a', 'read_only' => true, 'sensitive' => false, 'active' => true]);
        $tech = User::factory()->tech()->create();

        $html = $this->actingAs($tech)->get(route('settings.integrations'))->assertOk()->getContent();

        $this->assertStringContainsString('id="carbon-mcp-card" data-state="connected"', $html);
        $this->assertStringNotContainsString('id="carbon-mcp-connect-btn"', $html);
        $this->assertStringNotContainsString('id="carbon-mcp-sync-btn"', $html);
        $this->assertStringNotContainsString('id="carbon-mcp-signout-btn"', $html);

        // The sync route itself refuses a non-admin, and nothing reaches Carbon.
        $this->actingAs($tech)->post(route('settings.integrations.carbon.sync-mcp-catalog'))->assertForbidden();
        $this->assertNull(Setting::getValue('carbon_mcp_last_sync_at'));
        Http::assertNothingSent();
    }

    public function test_refresh_failed_says_the_last_refresh_failed_and_retries_not_refreshed_automatically(): void
    {
        $this->seedConnected();
        Setting::setValue('carbon_mcp_refresh_failed_at', '2026-10-21T18:30:00+00:00');
        Setting::setValue('carbon_mcp_refresh_error_code', 'no response (ConnectionException)');

        $html = $this->actingAs(User::factory()->admin()->create())->get(route('settings.integrations'))->assertOk()->getContent();

        $this->assertStringContainsString('data-state="refresh_failed"', $html);
        $this->assertStringContainsString('The last refresh failed. PSA retries it on the next Carbon call; if it keeps failing, an admin reconnects Carbon.', $html);
        $this->assertStringContainsString('the last refresh failed (see below).', $html);
        $card = substr($html, (int) strpos($html, 'id="carbon-mcp-card"'));
        $card = substr($card, 0, (int) strpos($card, 'id="carbon_mcp_enabled"'));
        $this->assertStringNotContainsString('refreshed automatically', $card);
        $this->assertStringNotContainsString('no longer works', $card);
    }

    public function test_a_connected_card_says_refreshed_automatically(): void
    {
        $this->seedConnected();

        $html = $this->actingAs(User::factory()->admin()->create())->get(route('settings.integrations'))->assertOk()->getContent();

        $card = substr($html, (int) strpos($html, 'id="carbon-mcp-card"'));
        $card = substr($card, 0, (int) strpos($card, 'id="carbon_mcp_enabled"'));
        $this->assertStringContainsString('refreshed automatically.', $card);
        $this->assertStringNotContainsString('The last refresh failed', $card);
    }

    public function test_a_non_admin_cannot_toggle_carbon_mcp_and_the_setting_is_unchanged(): void
    {
        $this->seedConnected(enabled: false);
        $tech = User::factory()->tech()->create();

        $this->actingAs($tech)->post(route('settings.integrations.toggle'), ['integration' => 'carbon_mcp', 'enabled' => '1'])
            ->assertForbidden();
        $this->assertSame('0', Setting::getValue('carbon_mcp_enabled'));

        Setting::setValue('carbon_mcp_enabled', '1');
        $this->actingAs($tech)->post(route('settings.integrations.toggle'), ['integration' => 'carbon_mcp'])
            ->assertForbidden();
        $this->assertSame('1', Setting::getValue('carbon_mcp_enabled'));

        // An admin still toggles it, both ways.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('settings.integrations.toggle'), ['integration' => 'carbon_mcp'])
            ->assertSessionHas('success', fn (string $m): bool => str_starts_with($m, 'Carbon Connect MCP disabled.'));
        $this->assertSame('0', Setting::getValue('carbon_mcp_enabled'));
        $this->actingAs($admin)->post(route('settings.integrations.toggle'), ['integration' => 'carbon_mcp', 'enabled' => '1'])
            ->assertSessionHas('success', fn (string $m): bool => str_starts_with($m, 'Carbon Connect MCP enabled.'));
        $this->assertSame('1', Setting::getValue('carbon_mcp_enabled'));
    }

    public function test_the_carbon_admin_check_leaves_other_integration_switches_as_they_were(): void
    {
        Setting::setValue('huntress_enabled', '0');

        $this->actingAs(User::factory()->tech()->create())->post(route('settings.integrations.toggle'), ['integration' => 'huntress', 'enabled' => '1'])
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('success');
        $this->assertSame('1', Setting::getValue('huntress_enabled'));
    }

    public function test_the_switch_cannot_be_turned_on_without_a_sign_in(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('settings.integrations.toggle'), ['integration' => 'carbon_mcp', 'enabled' => '1'])
            ->assertSessionHas('error');
        $this->assertSame('0', Setting::getValue('carbon_mcp_enabled'));

        $this->seedConnected(enabled: false);
        $this->actingAs($admin)->post(route('settings.integrations.toggle'), ['integration' => 'carbon_mcp', 'enabled' => '1'])
            ->assertSessionHas('success', fn (string $m): bool => str_starts_with($m, 'Carbon Connect MCP enabled.'));
        $this->assertSame('1', Setting::getValue('carbon_mcp_enabled'));
    }
}
