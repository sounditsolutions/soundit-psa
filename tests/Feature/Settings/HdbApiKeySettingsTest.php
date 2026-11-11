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

    public function test_saving_api_settings_preserves_t2t_key_and_saves_company_id(): void
    {
        Setting::setEncrypted('t2t_api_key', 'existing-private-key');
        $this->save(['company_id' => 'SoundPSA', 'hdb_api_key' => 'synthetic-api-key']);
        $this->assertSame('existing-private-key', Setting::getEncrypted('t2t_api_key'));
        $this->assertSame('SoundPSA', Setting::getValue('t2t_company_id'));
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
        $this->get(route('settings.integrations'))->assertOk()->assertSee('Reports API')->assertDontSee('Reports API (beta)')->assertDontSee('name="hdb_api_key_clear"', false);
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

    public function test_retired_portal_fields_and_action_are_absent(): void
    {
        $page = $this->get(route('settings.integrations'))->assertOk();
        $page->assertDontSee('HDB Report Portal')->assertDontSee('settings/integrations/hdb/test');
        foreach (['hdb_base_url', 'hdb_email', 'hdb_password', 'hdb_totp_secret'] as $key) {
            $page->assertDontSee('name="'.$key.'"', false);
        }
        $page->assertSee('Reports are read through the API. Without a saved key, the HelpDesk Buttons report tool is not available.');
        $this->post('/settings/integrations/hdb/test')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_old_fields_never_change_stored_rows(): void
    {
        $values = ['hdb_base_url' => 'https://portal.example.test', 'hdb_email' => 'synthetic@example.test', 'hdb_password' => 'synthetic-password', 'hdb_totp_secret' => 'synthetic-seed', 'hdb_connected_at' => 'synthetic-stamp'];
        foreach ($values as $key => $value) {
            Setting::setValue($key, $value);
        }
        $snapshot = Setting::whereIn('key', array_keys($values))->orderBy('key')->get()->toArray();
        foreach ([User::factory()->create(), User::factory()->tech()->create()] as $user) {
            $this->actingAs($user);
            foreach ([array_fill_keys(array_keys($values), ''), array_fill_keys(array_keys($values), 'replacement'), []] as $input) {
                $this->save($input);
                $this->assertSame($snapshot, Setting::whereIn('key', array_keys($values))->orderBy('key')->get()->toArray());
            }
        }
        Http::assertNothingSent();
    }
}
