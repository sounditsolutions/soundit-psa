<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Support\HdbPortalConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HDB Report Portal credential fields on the Tier2Tickets / HelpDesk Buttons
 * integrations card (issue #340, entry surface only).
 *
 * The property that matters most here is the one-shot secret: the TOTP seed is
 * displayed exactly once at enrollment, so a save that silently drops or mangles
 * it is unrecoverable without re-enrolling. Hence the coverage below is weighted
 * to keep-what-is-stored, whitespace tolerance, and never echoing a secret back.
 *
 * Nothing in the app reads these settings yet — the login handshake and the
 * report fetch are separate work. These tests fix the storage contract they will
 * consume.
 */
class HdbReportPortalCredentialSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // The destination guard resolves the host and fails closed on NXDOMAIN.
        // The portal names used below resolve nowhere, so the resolution step
        // gets a public answer by default; the hostile cases name their own.
        $this->app->instance(HdbPortalConfig::HOST_RESOLVER, fn (string $host) => ['93.184.216.34']);
    }

    /** The placeholder the form shows for an already-stored secret. */
    private const MASK = '••••••••';

    /**
     * The T2T form posts every field it owns on each save, so a partial payload
     * would not exercise the real submit. Callers override only what they test.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_id' => 'SoundPSA',
            'callback_url' => '',
            'hdb_base_url' => 'https://beta.helpdeskbuttons.com',
            'hdb_email' => 'ticket-reports@example.test',
            'hdb_password' => '',
            'hdb_totp_secret' => '',
        ], $overrides);
    }

    public function test_the_card_renders_the_four_hdb_fields(): void
    {
        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('HDB Report Portal')
            ->assertSee('name="hdb_base_url"', false)
            ->assertSee('name="hdb_email"', false)
            ->assertSee('name="hdb_password"', false)
            ->assertSee('name="hdb_totp_secret"', false);
    }

    public function test_it_saves_the_non_secret_fields_as_plain_settings(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.t2t.update'), $this->payload([
                'hdb_base_url' => 'https://portal.example.test',
                'hdb_email' => 'reports@example.test',
            ]))
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('https://portal.example.test', Setting::getValue('hdb_base_url'));
        $this->assertSame('reports@example.test', Setting::getValue('hdb_email'));
    }

    public function test_it_stores_the_password_and_totp_seed_encrypted(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.t2t.update'), $this->payload([
                'hdb_password' => 'correct-horse-battery',
                'hdb_totp_secret' => 'JBSWY3DPEHPK3PXP',
            ]))
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('correct-horse-battery', Setting::getEncrypted('hdb_password'));
        $this->assertSame('JBSWY3DPEHPK3PXP', Setting::getEncrypted('hdb_totp_secret'));

        // Encrypted at rest: the raw setting row must not hold the plaintext.
        $this->assertStringNotContainsString(
            'correct-horse-battery',
            (string) Setting::where('key', 'hdb_password')->value('value')
        );
        $this->assertStringNotContainsString(
            'JBSWY3DPEHPK3PXP',
            (string) Setting::where('key', 'hdb_totp_secret')->value('value')
        );
    }

    public function test_a_blank_submit_keeps_the_stored_secrets(): void
    {
        Setting::setEncrypted('hdb_password', 'stored-password');
        Setting::setEncrypted('hdb_totp_secret', 'JBSWY3DPEHPK3PXP');

        $this->actingAs($this->user)
            ->post(route('settings.integrations.t2t.update'), $this->payload([
                'hdb_email' => 'someone-else@example.test',
            ]))
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('stored-password', Setting::getEncrypted('hdb_password'));
        $this->assertSame('JBSWY3DPEHPK3PXP', Setting::getEncrypted('hdb_totp_secret'));
    }

    public function test_submitting_the_mask_keeps_the_stored_secrets(): void
    {
        Setting::setEncrypted('hdb_password', 'stored-password');
        Setting::setEncrypted('hdb_totp_secret', 'JBSWY3DPEHPK3PXP');

        $this->actingAs($this->user)
            ->post(route('settings.integrations.t2t.update'), $this->payload([
                'hdb_password' => self::MASK,
                'hdb_totp_secret' => self::MASK,
            ]))
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('stored-password', Setting::getEncrypted('hdb_password'));
        $this->assertSame('JBSWY3DPEHPK3PXP', Setting::getEncrypted('hdb_totp_secret'));
    }

    public function test_it_strips_whitespace_from_a_grouped_totp_seed(): void
    {
        // Enrollment screens render the seed in spaced groups and it gets pasted
        // that way. base32 decoding rejects any non-alphabet character outright,
        // so an unstripped space is a silently dead seed.
        $this->actingAs($this->user)
            ->post(route('settings.integrations.t2t.update'), $this->payload([
                'hdb_totp_secret' => " jbsw y3dp ehpk\t3pxp \n",
            ]))
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('jbswy3dpehpk3pxp', Setting::getEncrypted('hdb_totp_secret'));
    }

    public function test_clearing_the_portal_url_falls_back_to_the_default_on_read(): void
    {
        Setting::setValue('hdb_base_url', 'https://portal.example.test');

        $this->actingAs($this->user)
            ->post(route('settings.integrations.t2t.update'), $this->payload([
                'hdb_base_url' => '',
            ]))
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('', Setting::getValue('hdb_base_url'));

        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('https://beta.helpdeskbuttons.com');
    }

    public function test_it_rejects_a_malformed_portal_url_and_email(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.integrations.t2t.update'), $this->payload([
                'hdb_base_url' => 'not a url',
                'hdb_email' => 'not an email',
            ]))
            ->assertSessionHasErrors(['hdb_base_url', 'hdb_email']);

        $this->assertNull(Setting::getValue('hdb_base_url'));
        $this->assertNull(Setting::getValue('hdb_email'));
    }

    public function test_it_rejects_a_portal_url_it_would_not_post_the_password_to(): void
    {
        // The write path is the other half of the destination guard. It runs on
        // an admin's save (the fields are admin-only), and an admin can name an
        // internal address or a cleartext one just as easily — so the same
        // predicate the read path applies is applied here too.
        foreach (['http://attacker.example', 'https://169.254.169.254', 'https://localhost'] as $hostile) {
            $this->actingAs($this->user)
                ->post(route('settings.integrations.t2t.update'), $this->payload([
                    'hdb_base_url' => $hostile,
                ]))
                ->assertSessionHasErrors(['hdb_base_url']);
        }

        // And the same destination named as a host that RESOLVES inside: the
        // string is unremarkable, only the answer is hostile, so refusing it is
        // something no amount of parsing can do.
        $this->app->instance(HdbPortalConfig::HOST_RESOLVER, fn (string $host) => ['10.0.0.5']);

        $this->actingAs($this->user)
            ->post(route('settings.integrations.t2t.update'), $this->payload([
                'hdb_base_url' => 'https://portal.example.test',
            ]))
            ->assertSessionHasErrors(['hdb_base_url']);

        $this->assertNull(Setting::getValue('hdb_base_url'));
    }

    public function test_a_non_admin_cannot_name_where_the_stored_credential_is_spent(): void
    {
        // The URL guard can only prove "https, public hostname" — a host a
        // tech-role user owns passes it on sight — so who may WRITE these fields
        // is the same gate as who may spend them: admin, as on Test Connection.
        Setting::setValue('hdb_base_url', 'https://portal.example.test');
        Setting::setValue('hdb_email', 'reports@example.test');
        Setting::setEncrypted('hdb_password', 'stored-password');

        $this->actingAs(User::factory()->tech()->create())
            ->post(route('settings.integrations.t2t.update'), $this->payload([
                'hdb_base_url' => 'https://hdb-portal.attacker.example',
                'hdb_email' => 'attacker@attacker.example',
                'hdb_password' => 'attacker-password',
                'hdb_totp_secret' => 'JBSWY3DPEHPK3PXP',
            ]))
            ->assertRedirect(route('settings.integrations'));

        // Not merely refused: every stored value is left exactly as it was, so a
        // tech saving the rest of this form cannot blank the portal host either —
        // clearing it selects the default host, which is a real action.
        $this->assertSame('https://portal.example.test', Setting::getValue('hdb_base_url'));
        $this->assertSame('reports@example.test', Setting::getValue('hdb_email'));
        $this->assertSame('stored-password', Setting::getEncrypted('hdb_password'));
        $this->assertSame('', (string) Setting::getEncrypted('hdb_totp_secret', ''));
    }

    public function test_the_hdb_fields_are_not_offered_to_a_non_admin(): void
    {
        $this->actingAs(User::factory()->tech()->create())
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('HDB Report Portal')
            ->assertDontSee('name="hdb_base_url"', false)
            ->assertDontSee('name="hdb_password"', false)
            ->assertDontSee('name="hdb_totp_secret"', false);
    }

    public function test_stored_secrets_are_never_rendered_back_into_the_form(): void
    {
        Setting::setEncrypted('hdb_password', 'stored-password');
        Setting::setEncrypted('hdb_totp_secret', 'JBSWY3DPEHPK3PXP');

        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertDontSee('stored-password')
            ->assertDontSee('JBSWY3DPEHPK3PXP');
    }

    public function test_saving_hdb_fields_leaves_the_existing_t2t_settings_alone(): void
    {
        Setting::setEncrypted('t2t_api_key', 'existing-private-key');

        $this->actingAs($this->user)
            ->post(route('settings.integrations.t2t.update'), $this->payload([
                'hdb_password' => 'new-password',
            ]))
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('existing-private-key', Setting::getEncrypted('t2t_api_key'));
        $this->assertSame('SoundPSA', Setting::getValue('t2t_company_id'));
    }
}
