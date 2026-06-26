<?php

namespace Tests\Feature\Teams;

use App\Models\Setting;
use App\Support\TeamsBotConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TeamsBotConfig (Teams bridge E1) — the per-tenant bot-credential store.
 * App ID + tenant ID are plain Settings; the Entra client secret is encrypted
 * at rest (mirrors McpConfig / the encrypted technician_teams_webhook_url).
 * Ships dormant: enabled() defaults false.
 */
class TeamsBotConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconfigured_by_default(): void
    {
        $this->assertNull(TeamsBotConfig::appId());
        $this->assertNull(TeamsBotConfig::tenantId());
        $this->assertNull(TeamsBotConfig::clientSecret());
        $this->assertFalse(TeamsBotConfig::configured());
        $this->assertFalse(TeamsBotConfig::enabled(), 'ships dormant — enabled() defaults false');
        $this->assertSame([], TeamsBotConfig::appIds());
    }

    public function test_app_id_and_tenant_id_round_trip_as_plain_settings(): void
    {
        Setting::setValue('teams_bot_app_id', '11111111-1111-1111-1111-111111111111');
        Setting::setValue('teams_bot_tenant_id', '22222222-2222-2222-2222-222222222222');

        $this->assertSame('11111111-1111-1111-1111-111111111111', TeamsBotConfig::appId());
        $this->assertSame('22222222-2222-2222-2222-222222222222', TeamsBotConfig::tenantId());
        $this->assertSame(['11111111-1111-1111-1111-111111111111'], TeamsBotConfig::appIds());
    }

    public function test_client_secret_is_encrypted_at_rest_and_reads_back_decrypted(): void
    {
        TeamsBotConfig::setClientSecret('super-secret-value');

        // Encrypted at rest: the raw stored column is NOT the plaintext.
        $raw = Setting::where('key', 'teams_bot_client_secret')->value('value');
        $this->assertNotSame('super-secret-value', $raw);
        $this->assertNotEmpty($raw);

        // Reads back decrypted.
        $this->assertSame('super-secret-value', TeamsBotConfig::clientSecret());
    }

    public function test_configured_requires_app_id_tenant_and_secret(): void
    {
        Setting::setValue('teams_bot_app_id', 'app');
        $this->assertFalse(TeamsBotConfig::configured(), 'app id alone is not configured');

        Setting::setValue('teams_bot_tenant_id', 'tenant');
        $this->assertFalse(TeamsBotConfig::configured(), 'still missing the secret');

        TeamsBotConfig::setClientSecret('secret');
        $this->assertTrue(TeamsBotConfig::configured());
    }

    public function test_enabled_flag_toggles(): void
    {
        $this->assertFalse(TeamsBotConfig::enabled());

        Setting::setValue('teams_bot_enabled', '1');
        $this->assertTrue(TeamsBotConfig::enabled());

        Setting::setValue('teams_bot_enabled', '0');
        $this->assertFalse(TeamsBotConfig::enabled());
    }
}
