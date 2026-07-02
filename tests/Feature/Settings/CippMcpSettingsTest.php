<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Support\CippConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
