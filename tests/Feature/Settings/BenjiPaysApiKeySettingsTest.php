<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Support\BenjiPaysConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * BenjiPays API key on the Settings → Integrations billing tab: entry and
 * storage only. Nothing in the app calls the BenjiPays API yet; these tests fix
 * the storage contract that client will consume — encrypted at rest, masked in
 * the form, blank-submit-safe, replaceable, and never echoed or logged.
 *
 * Every key below is a dummy value; none of them is a real credential.
 */
class BenjiPaysApiKeySettingsTest extends TestCase
{
    use RefreshDatabase;

    private const MASK = '••••••••';

    private const DUMMY_KEY = 'bp_test_dummy_0123456789abcdef';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_the_card_renders_an_empty_password_field_when_no_key_is_stored(): void
    {
        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('BenjiPays')
            ->assertSee('id="benjipays_api_key"', false)
            ->assertSee('type="password"', false)
            ->assertSee('Not configured');
    }

    public function test_saving_a_key_stores_it_encrypted_not_plaintext(): void
    {
        $this->actingAs($this->user)
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.benjipays.update'), ['api_key' => self::DUMMY_KEY])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.integrations'));

        $raw = Setting::getValue('benjipays_api_key');
        $this->assertNotEmpty($raw);
        $this->assertNotSame(self::DUMMY_KEY, $raw, 'the key must be encrypted at rest');
        $this->assertStringNotContainsString(self::DUMMY_KEY, (string) $raw);

        $this->assertSame(self::DUMMY_KEY, BenjiPaysConfig::get('api_key'));
        $this->assertTrue(BenjiPaysConfig::isConfigured());
    }

    public function test_a_stored_key_is_masked_in_the_form_and_never_rendered(): void
    {
        Setting::setEncrypted('benjipays_api_key', self::DUMMY_KEY);

        $html = $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('Key stored')
            ->getContent();

        $this->assertStringNotContainsString(self::DUMMY_KEY, $html);
        $this->assertStringNotContainsString((string) Setting::getValue('benjipays_api_key'), $html, 'the ciphertext must not be rendered either');
        $this->assertMatchesRegularExpression('/id="benjipays_api_key"[^>]*value=""/s', $html);
        $this->assertMatchesRegularExpression('/id="benjipays_api_key"[^>]*placeholder="'.preg_quote(self::MASK, '/').'"/s', $html);
    }

    public function test_blank_submit_keeps_the_stored_key(): void
    {
        Setting::setEncrypted('benjipays_api_key', self::DUMMY_KEY);

        $this->actingAs($this->user)
            ->post(route('settings.integrations.benjipays.update'), ['api_key' => ''])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame(self::DUMMY_KEY, BenjiPaysConfig::get('api_key'));
    }

    public function test_mask_placeholder_submit_keeps_the_stored_key(): void
    {
        Setting::setEncrypted('benjipays_api_key', self::DUMMY_KEY);

        $this->actingAs($this->user)
            ->post(route('settings.integrations.benjipays.update'), ['api_key' => self::MASK])
            ->assertSessionHasNoErrors();

        $this->assertSame(self::DUMMY_KEY, BenjiPaysConfig::get('api_key'));
    }

    public function test_a_new_key_replaces_the_stored_one(): void
    {
        Setting::setEncrypted('benjipays_api_key', self::DUMMY_KEY);

        $this->actingAs($this->user)
            ->post(route('settings.integrations.benjipays.update'), ['api_key' => '  bp_test_replacement_key_9876  '])
            ->assertSessionHasNoErrors();

        $this->assertSame('bp_test_replacement_key_9876', BenjiPaysConfig::get('api_key'));
    }

    public function test_the_success_flash_never_contains_the_key(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('settings.integrations.benjipays.update'), ['api_key' => self::DUMMY_KEY]);

        $flash = (string) $response->baseResponse->getSession()->get('success');
        $this->assertNotSame('', $flash);
        $this->assertStringNotContainsString(self::DUMMY_KEY, $flash);
    }

    public function test_saving_writes_nothing_to_the_log(): void
    {
        Log::shouldReceive('info', 'warning', 'error', 'debug')->never();

        $this->actingAs($this->user)
            ->post(route('settings.integrations.benjipays.update'), ['api_key' => self::DUMMY_KEY])
            ->assertSessionHasNoErrors();
    }

    public function test_an_overlong_key_is_rejected_and_the_stored_key_survives(): void
    {
        Setting::setEncrypted('benjipays_api_key', self::DUMMY_KEY);

        $this->actingAs($this->user)
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.benjipays.update'), ['api_key' => str_repeat('x', 501)])
            ->assertSessionHasErrors('api_key');

        $this->assertSame(self::DUMMY_KEY, BenjiPaysConfig::get('api_key'));
    }

    public function test_a_guest_cannot_save_a_key(): void
    {
        $this->post(route('settings.integrations.benjipays.update'), ['api_key' => self::DUMMY_KEY])
            ->assertRedirect(route('login'));

        $this->assertNull(Setting::getValue('benjipays_api_key'));
    }

    public function test_config_reads_null_when_nothing_is_stored(): void
    {
        $this->assertNull(BenjiPaysConfig::get('api_key'));
        $this->assertFalse(BenjiPaysConfig::isConfigured());
    }
}
