<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Support\HdbApiConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HdbApiKeySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
    }

    private function save(array $values): void
    {
        $this->post(route('settings.integrations.t2t.update'), $values)->assertRedirect(route('settings.integrations'));
    }

    public function test_save_encrypts_and_blank_and_mask_keep(): void
    {
        $this->save(['hdb_api_key' => 'synthetic-api-key']);
        $this->assertSame('synthetic-api-key', HdbApiConfig::apiKey());
        $this->assertNotSame('synthetic-api-key', Setting::getValue('hdb_api_key'));
        foreach (['', '••••••••'] as $value) {
            $this->save(['hdb_api_key' => $value]);
            $this->assertSame('synthetic-api-key', HdbApiConfig::apiKey());
        }
    }

    public function test_clear_deletes_key(): void
    {
        Setting::setEncrypted('hdb_api_key', 'synthetic-api-key');
        $this->save(['hdb_api_key_clear' => '1']);
        $this->assertDatabaseMissing('settings', ['key' => 'hdb_api_key']);
    }

    public function test_clear_wins_over_replacement(): void
    {
        Setting::setEncrypted('hdb_api_key', 'synthetic-api-key');
        $this->save(['hdb_api_key_clear' => '1', 'hdb_api_key' => 'synthetic-replacement']);
        $this->assertNull(HdbApiConfig::apiKey());
    }

    public function test_non_admin_cannot_clear_or_write_or_test(): void
    {
        Setting::setEncrypted('hdb_api_key', 'synthetic-api-key');
        $this->actingAs(User::factory()->tech()->create());
        $this->save(['hdb_api_key_clear' => '1', 'hdb_api_key' => 'synthetic-replacement']);
        $this->assertSame('synthetic-api-key', HdbApiConfig::apiKey());
        $this->postJson(route('settings.integrations.hdb-api.test'))->assertForbidden();
        $this->get(route('settings.integrations'))->assertOk()->assertDontSee('name="hdb_api_key"', false);
        Http::assertNothingSent();
    }

    public function test_clear_leaves_portal_credentials_unchanged(): void
    {
        Setting::setEncrypted('hdb_password', 'synthetic-password');
        Setting::setEncrypted('hdb_totp_secret', 'synthetic-seed');
        Setting::setEncrypted('hdb_api_key', 'synthetic-api-key');
        $this->save(['hdb_api_key_clear' => '1']);
        $this->assertSame('synthetic-password', Setting::getEncrypted('hdb_password'));
        $this->assertSame('synthetic-seed', Setting::getEncrypted('hdb_totp_secret'));
    }

    public function test_page_never_renders_stored_key_and_clear_requires_key(): void
    {
        $this->get(route('settings.integrations'))->assertOk()->assertSee('Reports API (beta)')->assertDontSee('name="hdb_api_key_clear"', false);
        Setting::setEncrypted('hdb_api_key', 'synthetic-api-key');
        $this->get(route('settings.integrations'))->assertOk()->assertDontSee('synthetic-api-key')->assertSee('name="hdb_api_key_clear"', false)->assertSee('••••••••');
    }

    public function test_key_validation(): void
    {
        $this->post(route('settings.integrations.t2t.update'), ['hdb_api_key' => str_repeat('x', 501)])->assertSessionHasErrors('hdb_api_key');
        $this->assertNull(HdbApiConfig::apiKey());
    }

    public function test_connection_statuses_and_portal_timestamp_unchanged(): void
    {
        Setting::setEncrypted('hdb_api_key', 'synthetic-api-key');
        Setting::setValue('hdb_connected_at', 'synthetic-timestamp');
        $statuses = [200 => 'connected', 401 => 'rejected', 403 => 'missing_permission', 500 => 'unreachable', 302 => 'unexpected', 404 => 'unexpected'];
        $sequence = Http::sequence();
        foreach ($statuses as $status => $label) {
            $sequence->push(['message' => 'synthetic-private-message'], $status);
        }
        Http::fake([HdbApiConfig::BASE_URL.'/groups' => $sequence]);
        foreach ($statuses as $status => $label) {
            $this->postJson(route('settings.integrations.hdb-api.test'))->assertOk()->assertJson(['message' => $label, 'http_status' => $status])->assertDontSee('synthetic-private-message');
            Http::assertSent(fn ($r) => $r->url() === HdbApiConfig::BASE_URL.'/groups');
            Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->hasHeader('Authorization', 'Bearer synthetic-api-key'));
        }
        Http::assertSentCount(6);
        $this->assertSame('synthetic-timestamp', Setting::getValue('hdb_connected_at'));
    }

    public function test_missing_key_and_transport_failure(): void
    {
        $this->postJson(route('settings.integrations.hdb-api.test'))->assertJson(['message' => 'not_configured']);
        Http::assertNothingSent();
        Setting::setEncrypted('hdb_api_key', 'synthetic-api-key');
        Http::fake([HdbApiConfig::BASE_URL.'/groups' => Http::failedConnection()]);
        $this->postJson(route('settings.integrations.hdb-api.test'))->assertJson(['message' => 'unreachable']);
    }
}
