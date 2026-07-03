<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Services\Cipp\CippMcpCatalogSyncResult;
use App\Services\Cipp\CippMcpCatalogSyncService;
use App\Support\CippConfig;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CippMcpSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_saving_cipp_settings_persists_mcp_credentials_with_encrypted_secret(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.cipp.update'), [
                'api_url' => 'https://cipp.example.test',
                'tenant_id' => 'tenant-1',
                'client_id' => 'legacy-client',
                'client_secret' => 'legacy-secret',
                'mcp_client_id' => 'mcp-client',
                'mcp_client_secret' => 'mcp-secret',
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('mcp-client', CippConfig::get('mcp_client_id'));
        $this->assertSame('mcp-secret', CippConfig::get('mcp_client_secret'));
        $this->assertNotSame('mcp-secret', Setting::where('key', 'cipp_mcp_client_secret')->value('value'));
        $this->assertFalse(CippConfig::isMcpRelayEnabled());
    }

    public function test_blank_mcp_secret_submit_keeps_existing_secret(): void
    {
        Setting::setEncrypted('cipp_mcp_client_secret', 'original-mcp-secret');

        $this->actingAs($this->user)
            ->post(route('settings.integrations.cipp.update'), [
                'api_url' => 'https://cipp.example.test',
                'tenant_id' => 'tenant-1',
                'client_id' => 'legacy-client',
                'client_secret' => 'legacy-secret',
                'mcp_client_id' => 'mcp-client',
                'mcp_client_secret' => '',
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('original-mcp-secret', CippConfig::get('mcp_client_secret'));
    }

    public function test_mcp_relay_enablement_requires_mcp_credentials(): void
    {
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_mcp_enabled', '1');

        $this->assertFalse(CippConfig::isMcpRelayEnabled());

        Setting::setValue('cipp_mcp_client_id', 'mcp-client');
        Setting::setEncrypted('cipp_mcp_client_secret', 'mcp-secret');

        $this->assertTrue(CippConfig::isMcpRelayEnabled());
    }

    public function test_mcp_catalog_auto_sync_is_default_off_and_requires_mcp_credentials(): void
    {
        $this->assertFalse(CippConfig::isMcpCatalogSyncEnabled());

        Setting::setValue('cipp_mcp_catalog_sync_enabled', '1');
        $this->assertFalse(CippConfig::isMcpCatalogSyncEnabled());

        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_mcp_client_id', 'mcp-client');
        Setting::setEncrypted('cipp_mcp_client_secret', 'mcp-secret');

        $this->assertTrue(CippConfig::isMcpCatalogSyncEnabled());
    }

    public function test_mcp_relay_toggle_rejects_enable_without_mcp_credentials(): void
    {
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');

        $this->actingAs($this->user)
            ->post(route('settings.integrations.toggle'), [
                'integration' => 'cipp_mcp',
                'enabled' => '1',
            ])
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('error');

        $this->assertSame('0', Setting::getValue('cipp_mcp_enabled', '0'));
    }

    public function test_mcp_catalog_auto_sync_toggle_rejects_enable_without_mcp_credentials(): void
    {
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');

        $this->actingAs($this->user)
            ->post(route('settings.integrations.toggle'), [
                'integration' => 'cipp_mcp_catalog_sync',
                'enabled' => '1',
            ])
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('error');

        $this->assertSame('0', Setting::getValue('cipp_mcp_catalog_sync_enabled', '0'));
    }

    public function test_integrations_page_masks_cipp_mcp_secret(): void
    {
        Setting::setValue('cipp_mcp_client_id', 'mcp-client');
        Setting::setEncrypted('cipp_mcp_client_secret', 'top-secret-mcp-value');

        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('MCP Client ID')
            ->assertSee('mcp-client')
            ->assertDontSee('top-secret-mcp-value');
    }

    public function test_integrations_page_renders_mcp_catalog_sync_button_and_auto_sync_toggle(): void
    {
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_client_id', 'legacy-client');
        Setting::setEncrypted('cipp_client_secret', 'legacy-secret');
        Setting::setValue('cipp_mcp_client_id', 'mcp-client');
        Setting::setEncrypted('cipp_mcp_client_secret', 'mcp-secret');
        Setting::setValue('cipp_connected_at', now()->toDateTimeString());

        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee(route('settings.integrations.cipp.sync-mcp-catalog'), false)
            ->assertSee('Sync MCP Catalog')
            ->assertSee('Auto-sync MCP catalog weekly');
    }

    public function test_cipp_mcp_catalog_sync_button_runs_sync_service(): void
    {
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_mcp_client_id', 'mcp-client');
        Setting::setEncrypted('cipp_mcp_client_secret', 'mcp-secret');

        $service = Mockery::mock(CippMcpCatalogSyncService::class);
        $service->shouldReceive('sync')->once()->andReturn(new CippMcpCatalogSyncResult(
            seen: 231,
            active: 214,
            created: 214,
            updated: 0,
            deactivated: 0,
        ));
        $this->app->instance(CippMcpCatalogSyncService::class, $service);

        $this->actingAs($this->user)
            ->post(route('settings.integrations.cipp.sync-mcp-catalog'))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('success', 'CIPP MCP catalog sync complete: 231 seen, 214 active, 214 created, 0 updated, 0 deactivated.');
    }

    public function test_cipp_mcp_catalog_sync_schedule_is_registered_and_default_off(): void
    {
        $this->assertFalse($this->scheduleEvent('cipp:sync-mcp-catalog')->filtersPass($this->app));

        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_mcp_client_id', 'mcp-client');
        Setting::setEncrypted('cipp_mcp_client_secret', 'mcp-secret');
        Setting::setValue('cipp_mcp_catalog_sync_enabled', '1');

        $this->assertTrue($this->scheduleEvent('cipp:sync-mcp-catalog')->filtersPass($this->app));
    }

    public function test_cipp_mcp_catalog_sync_command_is_registered_and_uses_service(): void
    {
        $service = Mockery::mock(CippMcpCatalogSyncService::class);
        $service->shouldReceive('sync')->once()->andReturn(new CippMcpCatalogSyncResult(
            seen: 2,
            active: 2,
            created: 1,
            updated: 1,
            deactivated: 0,
        ));
        $this->app->instance(CippMcpCatalogSyncService::class, $service);

        $this->artisan('cipp:sync-mcp-catalog')
            ->expectsOutput('CIPP MCP catalog sync complete: 2 seen, 2 active, 1 created, 1 updated, 0 deactivated.')
            ->assertExitCode(0);
    }

    private function scheduleEvent(string $summaryNeedle): Event
    {
        foreach ($this->app->make(Schedule::class)->events() as $event) {
            if (str_contains($event->getSummaryForDisplay(), $summaryNeedle)) {
                return $event;
            }
        }

        $this->fail("Scheduled event [{$summaryNeedle}] was not registered.");
    }
}
