<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TacticalAsset;

/**
 * Synthetic Settings + asset fixtures for the Sources tab tests (G-13: no real
 * client, host or id). Credentials are throwaway strings; nothing is called.
 */
trait AssetSourcesFixtures
{
    private static int $syntheticSeq = 0;

    /** Configure every source in Settings; Ninja stays at its default OFF. */
    protected function configureAllSources(): void
    {
        Setting::setEncrypted('autoelevate_api_key', 'synthetic-ae-key');
        Setting::setValue('litsrmm_base_url', 'https://litsrmm.example.test');
        Setting::setEncrypted('litsrmm_api_key', 'synthetic-litsrmm-key');
        Setting::setValue('tactical_api_url', 'https://tactical-api.example.test');
        Setting::setEncrypted('tactical_api_key', 'synthetic-tactical-key');
        Setting::setEncrypted('level_api_key', 'synthetic-level-key');
        Setting::setValue('ninja_client_id', 'synthetic-ninja-id');
        Setting::setEncrypted('ninja_client_secret', 'synthetic-ninja-secret');
        Setting::setValue('comet_server_url', 'https://comet.example.test');
        Setting::setEncrypted('comet_admin_user', 'synthetic-admin');
        Setting::setEncrypted('comet_admin_password', 'synthetic-pass');
        Setting::setEncrypted('controld_api_key', 'synthetic-controld-key');
        Setting::setEncrypted('zorus_api_key', 'synthetic-zorus-key');
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'synthetic-tenant');
        Setting::setValue('cipp_client_id', 'synthetic-client');
        Setting::setEncrypted('cipp_client_secret', 'synthetic-secret');
        Setting::setValue('cipp_device_sync_enabled', '1');
        Setting::setValue('screenconnect_base_url', 'https://sc.example.test');
        Setting::setValue('screenconnect_webhook_secret', 'synthetic-sc-secret');
        Setting::setValue('screenconnect_enabled', '1');
        Setting::setEncrypted('servosity_api_token', 'synthetic-servosity-token');
    }

    /** An asset linked to every source, with clean stored telemetry. */
    protected function assetLinkedEverywhere(array $overrides = [], array $tactical = []): Asset
    {
        $n = ++self::$syntheticSeq;
        $client = Client::factory()->create(['name' => 'Example Dental Co.']);
        $asset = Asset::factory()->create(array_merge([
            'client_id' => $client->id,
            'hostname' => 'DESK-EXAMPLE-01',
            'autoelevate_computer_id' => 'ae-synthetic-'.$n,
            'autoelevate_elevation_mode' => 'live',
            'autoelevate_last_checked_in_at' => now()->subMinutes(5),
            'autoelevate_synced_at' => now()->subMinutes(30),
            'level_id' => 'level-synthetic-'.$n,
            'level_synced_at' => now()->subHours(2),
            'ninja_id' => (string) (111110 + $n),
            'ninja_synced_at' => now()->subDays(48),
            // comet_username left null: the page's existing Comet job read then
            // answers "no synced username" without asking Comet.
            'comet_device_id' => 'comet-synthetic-'.$n,
            'controld_device_id' => 'cd-synthetic-'.$n,
            'controld_agent_status' => 1,
            'controld_synced_at' => now()->subHours(3),
            'zorus_endpoint_id' => 'zorus-synthetic-'.$n,
            'zorus_agent_state' => 'Online',
            'zorus_filtering_enabled' => true,
            'zorus_synced_at' => now()->subHours(4),
            'm365_device_id' => 'm365-synthetic-'.$n,
            'm365_is_compliant' => true,
            'm365_synced_at' => now()->subHours(5),
            'screenconnect_session_id' => 'sc-synthetic-'.$n,
            'screenconnect_online' => true,
            'screenconnect_last_seen_at' => now()->subMinutes(3),
            'screenconnect_synced_at' => now()->subMinutes(3),
            'servosity_dr_backup_id' => 4240 + $n,
            'rmm_online' => true,
            'needs_reboot' => false,
        ], $overrides));
        $asset->forceFill(['litsrmm_device_id' => 'litsrmm-synthetic-'.$n, 'litsrmm_synced_at' => now()->subMinutes(4)])->saveQuietly();
        TacticalAsset::create(array_merge([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-SYNTH-'.$n,
            'hostname' => 'DESK-EXAMPLE-01',
            'status' => 'online',
            'last_seen_at' => now()->subMinute(),
            'checks_failing' => 0,
            'checks_passing' => 6,
            'checks_total' => 6,
            'needs_reboot' => false,
            'has_patches_pending' => false,
            'synced_at' => now()->subMinute(),
        ], $tactical));

        return $asset->refresh();
    }
}
