<?php

namespace Tests\Feature\Technician\Notify;

use App\Models\Setting;
use App\Models\User;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hardening (psa-uvuy): the operator-set Teams webhook URL is a credential — the
 * URL itself authorises posting into the operator chat. Bring it to parity with the
 * other operator secrets in IntegrationsController (level_api_key, stripe_secret_key,
 * …): encrypted at rest, masked in the form, and blank-submit-safe (a blank/mask
 * submit keeps the stored value rather than wiping it). TechnicianConfig::teamsWebhookUrl()
 * must still return the DECRYPTED URL so the SSRF pin operates on the real host.
 */
class TeamsWebhookSecretAtRestTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_webhook_stores_it_encrypted_not_plaintext(): void
    {
        $user = User::factory()->create();
        $url = 'https://93.184.216.34/hook';

        $this->actingAs($user)->from(route('settings.integrations'))->post(
            route('settings.integrations.technician.update'),
            [
                'technician_enabled' => '1',
                'technician_teams_webhook_url' => $url,
            ],
        )->assertSessionHasNoErrors();

        // The raw Setting row value must NOT be the plaintext URL (it is ciphertext).
        $raw = Setting::getValue('technician_teams_webhook_url');
        $this->assertNotSame($url, $raw, 'the webhook must be encrypted at rest, not stored plaintext');
        $this->assertNotEmpty($raw);

        // …but the decrypted accessor returns the real URL for the SSRF-pinned post().
        $this->assertSame($url, TechnicianConfig::teamsWebhookUrl());
    }

    public function test_blank_submit_does_not_clear_an_existing_stored_webhook(): void
    {
        $user = User::factory()->create();
        $url = 'https://93.184.216.34/hook';

        // Seed an encrypted webhook the normal way.
        Setting::setEncrypted('technician_teams_webhook_url', $url);

        // A blank submit (the operator did not retype the masked secret) must KEEP it.
        $this->actingAs($user)->from(route('settings.integrations'))->post(
            route('settings.integrations.technician.update'),
            [
                'technician_enabled' => '1',
                'technician_teams_webhook_url' => '',
            ],
        )->assertSessionHasNoErrors();

        $this->assertSame($url, TechnicianConfig::teamsWebhookUrl(), 'a blank submit must not wipe the stored webhook');
    }

    public function test_mask_placeholder_submit_does_not_clear_the_stored_webhook(): void
    {
        $user = User::factory()->create();
        $url = 'https://93.184.216.34/hook';
        Setting::setEncrypted('technician_teams_webhook_url', $url);

        // If the masked placeholder ever round-trips as the field value, treat it as "keep".
        $this->actingAs($user)->from(route('settings.integrations'))->post(
            route('settings.integrations.technician.update'),
            [
                'technician_enabled' => '1',
                'technician_teams_webhook_url' => '••••••••',
            ],
        )->assertSessionHasNoErrors();

        $this->assertSame($url, TechnicianConfig::teamsWebhookUrl());
    }

    public function test_a_new_non_blank_value_is_revalidated_for_ssrf(): void
    {
        $user = User::factory()->create();
        Setting::setEncrypted('technician_teams_webhook_url', 'https://93.184.216.34/hook');

        // A freshly submitted (non-blank, non-mask) value still runs SafeWebhookUrl.
        $this->actingAs($user)->from(route('settings.integrations'))->post(
            route('settings.integrations.technician.update'),
            [
                'technician_enabled' => '1',
                'technician_teams_webhook_url' => 'http://169.254.169.254/latest/meta-data',
            ],
        )->assertSessionHasErrors('technician_teams_webhook_url');

        // Rejected → the existing stored webhook is unchanged.
        $this->assertSame('https://93.184.216.34/hook', TechnicianConfig::teamsWebhookUrl());
    }

    public function test_teams_webhook_field_is_not_echoed_raw_in_the_form(): void
    {
        $user = User::factory()->create();
        Setting::setEncrypted('technician_teams_webhook_url', 'https://secret.webhook.office.com/abc123');

        $html = $this->actingAs($user)->get(route('settings.integrations'))->getContent();

        // The raw webhook URL must never appear in the rendered settings page.
        $this->assertStringNotContainsString('secret.webhook.office.com/abc123', $html);
    }

    public function test_config_reads_a_legacy_plaintext_value_too(): void
    {
        // Backward-compat: values written before this change (or by tests using
        // setValue) are plaintext. teamsWebhookUrl() must still return them.
        Setting::setValue('technician_teams_webhook_url', 'https://legacy.webhook.office.com/hook');

        $this->assertSame('https://legacy.webhook.office.com/hook', TechnicianConfig::teamsWebhookUrl());
    }
}
