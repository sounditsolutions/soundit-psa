<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Services\PowerDmarc\PowerDmarcClient;
use App\Support\PowerDmarcConfig;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PowerDMARC Integrations settings card (issue #689): credential save
 * (encrypted, blank = keep), optional base-URL override (blank = clear), Test
 * Connection, and the master toggle whose confirmation names the MCP
 * tool-withdrawal consequence (OFF=OFF).
 *
 * Base-URL URLs in these tests use public IP literals, never hostnames:
 * SafeWebhookUrl (#724) DNS-resolves hostnames and fails closed on NXDOMAIN,
 * so a .test-TLD hostname would bounce for the wrong reason.
 */
class PowerDmarcIntegrationSettingsTest extends TestCase
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
        $http = new GuzzleClient(['base_uri' => 'https://app.powerdmarc.com/', 'handler' => $stack]);

        $this->app->instance(PowerDmarcClient::class, new PowerDmarcClient(['api_key' => 'test-key'], $http));
    }

    private function mePayload(): Response
    {
        // /api/v1/me returns the UserDataResource DIRECTLY — no {data} envelope
        // (PowerDmarcClient shape fact 1). Test Connection must accept exactly
        // this shape.
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 100, 'name' => 'John Doe', 'email' => 'user@example.com', 'role' => 'Admin',
        ]));
    }

    public function test_the_powerdmarc_card_renders_on_the_integrations_page(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk();

        $response->assertSee('PowerDMARC');
        $response->assertSee(route('settings.integrations.powerdmarc.update'), false);
        $response->assertSee(route('settings.integrations.powerdmarc.test'), false);
    }

    public function test_saving_powerdmarc_settings_encrypts_the_api_key(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.powerdmarc.update'), [
                'api_key' => 'powerdmarc-secret-key',
                'base_url' => '',
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('powerdmarc-secret-key', PowerDmarcConfig::get('api_key'));
        $this->assertNotSame('powerdmarc-secret-key', Setting::where('key', 'powerdmarc_api_key')->value('value'));
    }

    public function test_a_full_length_powerdmarc_jwt_saves(): void
    {
        // Real PowerDMARC keys are JWTs well past the old max:500 — Charlie's
        // measured 1326 chars and the form bounced it silently (Chet, 08-28).
        $jwt = 'eyJhbGciOiJSUzI1NiJ9.'.str_repeat('a', 1400).'.sig';

        $this->actingAs($this->user)
            ->post(route('settings.integrations.powerdmarc.update'), [
                'api_key' => $jwt,
                'base_url' => '',
            ])
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHasNoErrors();

        $this->assertSame($jwt, PowerDmarcConfig::get('api_key'));
    }

    public function test_an_oversized_api_key_bounces_with_an_error_and_stores_nothing(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.powerdmarc.update'), [
                'api_key' => str_repeat('a', 5001),
                'base_url' => '',
            ])
            ->assertSessionHasErrors('api_key');

        $this->assertNull(Setting::where('key', 'powerdmarc_api_key')->value('value'));
    }

    public function test_a_validation_bounce_is_visible_on_the_integrations_page(): void
    {
        // The PowerDMARC card lives in a non-default tab, so a bounce must
        // surface in the page-level summary or it looks like Save did nothing.
        $this->actingAs($this->user)
            ->from(route('settings.integrations'))
            ->followingRedirects()
            ->post(route('settings.integrations.powerdmarc.update'), [
                'api_key' => str_repeat('a', 5001),
                'base_url' => '',
            ])
            ->assertOk()
            ->assertSee('Settings not saved.');
    }

    public function test_blank_api_key_submit_keeps_the_existing_secret(): void
    {
        Setting::setEncrypted('powerdmarc_api_key', 'original-key');

        $this->actingAs($this->user)
            ->post(route('settings.integrations.powerdmarc.update'), [
                'api_key' => '',
                'base_url' => '',
            ])
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('original-key', PowerDmarcConfig::get('api_key'));
    }

    public function test_base_url_override_saves_and_blank_clears_back_to_the_default(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.powerdmarc.update'), [
                'api_key' => '',
                'base_url' => 'https://93.184.216.34/pdmarc-proxy',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('https://93.184.216.34/pdmarc-proxy', PowerDmarcConfig::baseUrl());

        $this->actingAs($this->user)
            ->post(route('settings.integrations.powerdmarc.update'), [
                'api_key' => '',
                'base_url' => '',
            ]);

        $this->assertSame(PowerDmarcConfig::DEFAULT_BASE_URL, PowerDmarcConfig::baseUrl(), 'a blank base URL must clear the override');
    }

    public function test_the_base_url_rejects_plain_http(): void
    {
        // #724: the client sends the Bearer key to whatever base_url names —
        // an http:// override would ship the credential in cleartext.
        $this->actingAs($this->user)
            ->post(route('settings.integrations.powerdmarc.update'), [
                'api_key' => '',
                'base_url' => 'http://93.184.216.34/pdmarc-proxy',
            ])
            ->assertSessionHasErrors('base_url');

        $this->assertSame(PowerDmarcConfig::DEFAULT_BASE_URL, PowerDmarcConfig::baseUrl(), 'a rejected override must not be stored');
    }

    public function test_the_base_url_rejects_private_and_metadata_targets(): void
    {
        foreach ([
            'https://169.254.169.254/latest/meta-data',
            'https://10.0.0.5/pdmarc',
            'https://127.0.0.1/pdmarc',
        ] as $url) {
            $this->actingAs($this->user)
                ->post(route('settings.integrations.powerdmarc.update'), [
                    'api_key' => '',
                    'base_url' => $url,
                ])
                ->assertSessionHasErrors('base_url');
        }

        $this->assertSame(PowerDmarcConfig::DEFAULT_BASE_URL, PowerDmarcConfig::baseUrl(), 'a rejected override must not be stored');
    }

    public function test_non_admin_users_cannot_write_powerdmarc_settings(): void
    {
        // #762: base_url decides where the stored key is sent (#724), so the
        // write route is admin-gated. 403, and nothing may be stored.
        $tech = User::factory()->tech()->create();

        $this->actingAs($tech)
            ->post(route('settings.integrations.powerdmarc.update'), [
                'api_key' => 'smuggled-key',
                'base_url' => 'https://93.184.216.34/attacker',
            ])
            ->assertForbidden();

        $this->assertNull(Setting::where('key', 'powerdmarc_api_key')->value('value'));
        $this->assertSame(PowerDmarcConfig::DEFAULT_BASE_URL, PowerDmarcConfig::baseUrl());
    }

    public function test_an_explicit_admin_passes_the_write_gate(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('settings.integrations.powerdmarc.update'), [
                'api_key' => 'admin-key',
                'base_url' => '',
            ])
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHasNoErrors();

        $this->assertSame('admin-key', PowerDmarcConfig::get('api_key'));
    }

    public function test_test_connection_reports_unconfigured_without_an_api_key(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.powerdmarc.test'))
            ->assertOk()
            ->assertJson(['success' => false]);

        $this->assertNull(Setting::getValue('powerdmarc_connected_at'));
    }

    public function test_test_connection_stamps_connected_at_when_the_api_answers(): void
    {
        Setting::setEncrypted('powerdmarc_api_key', 'test-key');
        $this->bindClientReturning([$this->mePayload()]);

        $this->actingAs($this->user)
            ->post(route('settings.integrations.powerdmarc.test'))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertNotNull(Setting::getValue('powerdmarc_connected_at'));
    }

    public function test_test_connection_fails_cleanly_when_the_api_rejects_the_key(): void
    {
        Setting::setEncrypted('powerdmarc_api_key', 'bad-key');
        $this->bindClientReturning([new Response(401, [], '{"message":"Unauthenticated."}')]);

        $this->actingAs($this->user)
            ->post(route('settings.integrations.powerdmarc.test'))
            ->assertOk()
            ->assertJson(['success' => false]);

        $this->assertNull(Setting::getValue('powerdmarc_connected_at'), 'a failed test must not stamp a connection');
    }

    public function test_the_toggle_flips_the_master_switch_and_names_the_mcp_consequence(): void
    {
        Setting::setEncrypted('powerdmarc_api_key', 'test-key');

        // OFF → ON
        $this->actingAs($this->user)
            ->post(route('settings.integrations.toggle'), ['integration' => 'powerdmarc', 'enabled' => '1'])
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'MCP'));

        $this->assertTrue(PowerDmarcConfig::isEnabled());

        // ON → OFF (an unchecked switch posts no `enabled` field)
        $this->actingAs($this->user)
            ->post(route('settings.integrations.toggle'), ['integration' => 'powerdmarc'])
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'no longer offered to MCP'));

        $this->assertFalse(PowerDmarcConfig::isEnabled(), 'OFF=OFF — the switch must actually withdraw the capability');
    }

    public function test_powerdmarc_ships_dormant_the_switch_defaults_off(): void
    {
        // A keyed but never-toggled deployment must stay dark: enabled defaults
        // '0', so configuring credentials alone does not light the MCP surface.
        Setting::setEncrypted('powerdmarc_api_key', 'test-key');

        $this->assertTrue(PowerDmarcConfig::isConfigured());
        $this->assertFalse(PowerDmarcConfig::isEnabled());
        $this->assertFalse(PowerDmarcConfig::isAvailable());
    }
}
