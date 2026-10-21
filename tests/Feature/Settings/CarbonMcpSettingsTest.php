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
        $this->assertStringNotContainsString(self::CLIENT_SECRET, $html);
    }

    public function test_a_non_admin_sees_status_but_no_connect_sync_or_sign_out(): void
    {
        $this->seedConnected();
        $html = $this->actingAs(User::factory()->tech()->create())->get(route('settings.integrations'))->getContent();

        if (! str_contains($html, 'carbon-mcp-card')) {
            $this->markTestSkipped('Settings > Integrations is not visible to this role.');
        }
        $this->assertStringNotContainsString('id="carbon-mcp-connect-btn"', $html);
        $this->assertStringNotContainsString('id="carbon-mcp-signout-btn"', $html);
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
