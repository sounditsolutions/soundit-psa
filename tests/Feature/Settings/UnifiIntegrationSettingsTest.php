<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Services\Unifi\UnifiClient;
use App\Support\UnifiConfig;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UniFi Integrations settings card (psa-g5l80): credential save (encrypted, blank =
 * keep), optional base-URL override (blank = clear), Test Connection, and the master
 * toggle whose confirmation names the MCP tool-withdrawal consequence (OFF=OFF).
 */
class UnifiIntegrationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /** @param array<int, Response> $queue */
    private function bindClientReturning(array $queue): void
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $http = new GuzzleClient(['base_uri' => 'https://api.ui.com/', 'handler' => $stack]);

        $this->app->instance(UnifiClient::class, new UnifiClient(['api_key' => 'test-key'], $http));
    }

    private function sitesFixture(): Response
    {
        // Vendor example payload, committed verbatim from the Site Manager OpenAPI spec.
        $path = base_path('tests/Fixtures/unifi/list_sites.json');

        return new Response(200, ['Content-Type' => 'application/json'], (string) file_get_contents($path));
    }

    public function test_the_unifi_card_renders_on_the_integrations_page(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk();

        $response->assertSee('UniFi Site Manager');
        $response->assertSee(route('settings.integrations.unifi.update'), false);
        $response->assertSee(route('settings.integrations.unifi.test'), false);
    }

    public function test_saving_unifi_settings_encrypts_the_api_key(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.unifi.update'), [
                'api_key' => 'unifi-secret-key',
                'base_url' => '',
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('unifi-secret-key', UnifiConfig::get('api_key'));
        $this->assertNotSame('unifi-secret-key', Setting::where('key', 'unifi_api_key')->value('value'));
    }

    public function test_blank_api_key_submit_keeps_the_existing_secret(): void
    {
        Setting::setEncrypted('unifi_api_key', 'original-key');

        $this->actingAs($this->user)
            ->post(route('settings.integrations.unifi.update'), [
                'api_key' => '',
                'base_url' => '',
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('original-key', UnifiConfig::get('api_key'));
    }

    public function test_base_url_override_saves_and_blank_clears_back_to_the_default(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.unifi.update'), [
                'api_key' => '',
                'base_url' => 'https://unifi-proxy.example.test',
            ]);

        $this->assertSame('https://unifi-proxy.example.test', UnifiConfig::baseUrl());

        $this->actingAs($this->user)
            ->post(route('settings.integrations.unifi.update'), [
                'api_key' => '',
                'base_url' => '',
            ]);

        $this->assertSame(UnifiConfig::DEFAULT_BASE_URL, UnifiConfig::baseUrl(), 'a blank base URL must clear the override');
    }

    public function test_test_connection_reports_unconfigured_without_an_api_key(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.unifi.test'))
            ->assertOk()
            ->assertJson(['success' => false]);

        $this->assertNull(Setting::getValue('unifi_connected_at'));
    }

    public function test_test_connection_stamps_connected_at_when_the_api_answers(): void
    {
        Setting::setEncrypted('unifi_api_key', 'test-key');
        $this->bindClientReturning([$this->sitesFixture()]);

        $this->actingAs($this->user)
            ->post(route('settings.integrations.unifi.test'))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertNotNull(Setting::getValue('unifi_connected_at'));
    }

    public function test_test_connection_fails_cleanly_when_the_api_rejects_the_key(): void
    {
        Setting::setEncrypted('unifi_api_key', 'bad-key');
        $this->bindClientReturning([new Response(401, [], '{"httpStatusCode":401}')]);

        $this->actingAs($this->user)
            ->post(route('settings.integrations.unifi.test'))
            ->assertOk()
            ->assertJson(['success' => false]);

        $this->assertNull(Setting::getValue('unifi_connected_at'), 'a failed test must not stamp a connection');
    }

    public function test_the_toggle_flips_the_master_switch_and_names_the_mcp_consequence(): void
    {
        Setting::setEncrypted('unifi_api_key', 'test-key');

        // OFF → ON
        $this->actingAs($this->user)
            ->post(route('settings.integrations.toggle'), ['integration' => 'unifi', 'enabled' => '1'])
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'MCP'));

        $this->assertTrue(UnifiConfig::isEnabled());

        // ON → OFF (an unchecked switch posts no `enabled` field)
        $this->actingAs($this->user)
            ->post(route('settings.integrations.toggle'), ['integration' => 'unifi'])
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'no longer offered to MCP'));

        $this->assertFalse(UnifiConfig::isEnabled(), 'OFF=OFF — the switch must actually withdraw the capability');
    }
}
