<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\TechnicianActionLog;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Portal\InstallerInfo;
use App\Services\SyncResult;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalDeviceSyncService;
use App\Services\Tactical\TacticalProvisioningService;
use App\Services\Tactical\TacticalScriptSyncService;
use App\Support\CometConfig;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class TacticalAdminToolsPhase3Test extends TestCase
{
    use RefreshDatabase;

    private const PHASE_THREE_TOOLS = [
        'tactical_create_client_site',
        'tactical_provision_client_site',
        'tactical_set_agent_custom_field',
        'tactical_upsert_url_action',
        'tactical_upsert_alert_template',
        'tactical_set_default_alert_template',
        'tactical_get_or_create_installer',
        'tactical_generate_installer',
        'tactical_sync_devices_now',
        'tactical_sync_scripts_now',
        'tactical_provision_alert_ticketing',
    ];

    private function configureTactical(): void
    {
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'secret');
    }

    private function configureAiActor(): User
    {
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        return $actor;
    }

    private function token(array $tools): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: 'opsbot');
    }

    private function legacyToken(): string
    {
        return McpConfig::rotateStaffToken();
    }

    private function callTool(string $token, string $name, array $arguments = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function listTools(string $token): array
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ])
            ->json('result.tools') ?? [];
    }

    /** @return array<string, mixed> */
    private function decodedResult(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    /** @return array{client: Client, asset: Asset, tactical: TacticalAsset, ticket: Ticket} */
    private function endpointFixture(): array
    {
        $client = Client::factory()->create(['name' => 'Acme']);
        $contact = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Client',
            'last_name' => 'Contact',
            'email' => 'client@example.test',
            'is_active' => true,
        ]);
        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'PC-01',
            'name' => 'PC-01',
        ]);
        $tactical = TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'agent-1',
            'hostname' => 'PC-01',
            'status' => 'online',
            'synced_at' => now(),
        ]);
        $ticket = Ticket::factory()->for($client)->create([
            'contact_id' => $contact->id,
            'subject' => 'Workstation issue',
        ]);
        $ticket->assets()->attach($asset->id, ['is_primary' => true]);

        return compact('client', 'asset', 'tactical', 'ticket');
    }

    public function test_phase_three_tactical_admin_tools_are_sensitive_and_explicit_grant_only(): void
    {
        $this->configureTactical();

        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('tactical_admin', $groups);
        $this->assertTrue($groups['tactical_admin']['sensitive']);

        $adminNames = array_column($groups['tactical_admin']['tools'], 'name');
        foreach (self::PHASE_THREE_TOOLS as $tool) {
            $this->assertContains($tool, $adminNames, "{$tool} should be in the sensitive Tactical admin group");
            $this->assertContains($tool, McpToolRegistry::allToolNames(), "{$tool} should be token-grantable");
        }

        $legacyNames = array_column($this->listTools($this->legacyToken()), 'name');
        foreach (self::PHASE_THREE_TOOLS as $tool) {
            $this->assertNotContains($tool, $legacyNames, "legacy full-surface token must not gain {$tool}");
        }

        $scopedTools = collect($this->listTools($this->token([
            'tactical_create_client_site',
            'tactical_get_or_create_installer',
            'tactical_sync_scripts_now',
            'tactical_set_default_alert_template',
        ])))->keyBy('name');

        $this->assertContains('client_id', $scopedTools['tactical_create_client_site']['inputSchema']['required']);
        $this->assertContains('client_id', $scopedTools['tactical_get_or_create_installer']['inputSchema']['required']);
        $this->assertNotContains('client_id', $scopedTools['tactical_sync_scripts_now']['inputSchema']['required'] ?? []);

        $defaultDescription = $scopedTools['tactical_set_default_alert_template']['description'];
        $this->assertStringContainsString('affects Tactical alert behavior across devices', $defaultDescription);
        $this->assertStringContainsString('refuses to clobber', $defaultDescription);

        $installerDescription = $scopedTools['tactical_get_or_create_installer']['description'];
        $this->assertStringContainsString('signed installer URL', $installerDescription);
        $this->assertStringContainsString('not retained', $installerDescription);
    }

    public function test_create_client_site_rejects_upstream_ids_verifies_policies_and_is_idempotent(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $client = Client::factory()->create(['name' => 'Acme Co', 'tactical_site_id' => null]);
        $token = $this->token(['tactical_create_client_site']);

        $rejected = $this->callTool($token, 'tactical_create_client_site', [
            'client_id' => $client->id,
            'tactical_client_id' => 123,
            'reason' => 'Should not accept upstream Tactical IDs.',
        ]);

        $rejected->assertOk();
        $this->assertTrue((bool) $rejected->json('result.isError'));
        $this->assertStringContainsString('upstream Tactical identifiers are not accepted', (string) $rejected->json('result.content.0.text'));

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getPolicies')
            ->once()
            ->andReturn([['id' => 10, 'name' => 'Workstations'], ['id' => 11, 'name' => 'Servers']]);
        $tactical->shouldReceive('getClients')
            ->once()
            ->andReturn([]);
        $tactical->shouldReceive('createClient')
            ->once()
            ->with('Acme Co', 'Main', 10, 11)
            ->andReturn(['client_name' => 'Acme Co', 'site_name' => 'Main']);
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($token, 'tactical_create_client_site', [
            'client_id' => $client->id,
            'reason' => 'Create and map the Tactical client site for onboarding.',
            'workstation_policy_id' => 10,
            'server_policy_id' => 11,
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $result = $this->decodedResult($response);
        $this->assertTrue($result['success']);
        $this->assertSame('Acme Co|Main', $result['tactical_site_id']);
        $this->assertSame('Acme Co|Main', $client->fresh()->tactical_site_id);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_create_client_site',
            'result_status' => 'executed',
            'client_id' => $client->id,
        ]);

        $noCall = Mockery::mock(TacticalClient::class);
        $noCall->shouldNotReceive('createClient');
        $this->app->instance(TacticalClient::class, $noCall);

        $again = $this->decodedResult($this->callTool($token, 'tactical_create_client_site', [
            'client_id' => $client->id,
            'reason' => 'Retry should be idempotent and not call Tactical.',
        ]));
        $this->assertTrue($again['success']);
        $this->assertTrue($again['idempotent']);
    }

    public function test_set_agent_custom_field_uses_allowlisted_keys_and_redacts_value_in_mcp_audit(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $token = $this->token(['tactical_set_agent_custom_field']);

        $badField = $this->callTool($token, 'tactical_set_agent_custom_field', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'reason' => 'Try arbitrary field.',
            'field_id' => 999,
            'value' => 'secret',
        ]);
        $this->assertTrue((bool) $badField->json('result.isError'));
        $this->assertStringContainsString('upstream Tactical identifiers are not accepted', (string) $badField->json('result.content.0.text'));

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('setAgentCustomField')
            ->once()
            ->with('agent-1', CometConfig::TACTICAL_TOKEN_FIELD_ID, 'install-token-secret')
            ->andReturnNull();
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($token, 'tactical_set_agent_custom_field', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'reason' => 'Push a PSA-owned deployment token field.',
            'field_key' => 'comet_install_token',
            'value' => 'install-token-secret',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_set_agent_custom_field',
            'result_status' => 'executed',
            'client_id' => $fixture['client']->id,
        ]);

        $arguments = McpAuditLog::where('tool_name', 'tactical_set_agent_custom_field')->latest('id')->value('arguments');
        $this->assertIsArray($arguments);
        $this->assertSame('[custom field value withheld]', $arguments['value']);
        $this->assertStringNotContainsString('install-token-secret', json_encode(TechnicianActionLog::pluck('summary')->all()));
    }

    public function test_url_action_alert_template_default_and_provisioning_are_no_clobber_and_secret_free(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        Setting::setEncrypted('tactical_webhook_key', 'webhook-secret-value');
        $token = $this->token([
            'tactical_upsert_url_action',
            'tactical_upsert_alert_template',
            'tactical_set_default_alert_template',
            'tactical_provision_alert_ticketing',
        ]);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('createUrlAction')
            ->once()
            ->with(Mockery::on(fn (array $body): bool => ($body['name'] ?? null) === 'PSA Ticket Webhook'
                && str_contains((string) ($body['rest_headers'] ?? ''), 'webhook-secret-value')))
            ->andReturn('ok');
        $tactical->shouldReceive('getUrlActions')
            ->once()
            ->andReturn([['id' => 7, 'name' => 'PSA Ticket Webhook']]);
        $this->app->instance(TacticalClient::class, $tactical);

        $urlAction = $this->decodedResult($this->callTool($token, 'tactical_upsert_url_action', [
            'reason' => 'Create the PSA-owned Tactical URL action.',
        ]));
        $this->assertTrue($urlAction['success']);
        $this->assertSame(7, $urlAction['url_action_id']);
        $this->assertSame('7', Setting::getValue('tactical_url_action_id'));

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('createAlertTemplate')
            ->once()
            ->with(Mockery::on(fn (array $body): bool => ($body['name'] ?? null) === 'PSA Auto-Ticket'
                && ($body['action_rest'] ?? null) === 7
                && ($body['resolved_action_rest'] ?? null) === 7))
            ->andReturn('ok');
        $tactical->shouldReceive('getAlertTemplates')
            ->once()
            ->andReturn([['id' => 42, 'name' => 'PSA Auto-Ticket']]);
        $this->app->instance(TacticalClient::class, $tactical);

        $template = $this->decodedResult($this->callTool($token, 'tactical_upsert_alert_template', [
            'reason' => 'Create the PSA-owned alert template.',
        ]));
        $this->assertTrue($template['success']);
        $this->assertSame(42, $template['alert_template_id']);
        $this->assertSame('42', Setting::getValue('tactical_alert_template_id'));

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getCoreSettings')
            ->once()
            ->andReturn(['alert_template' => 99]);
        $tactical->shouldNotReceive('setDefaultAlertTemplate');
        $this->app->instance(TacticalClient::class, $tactical);

        $blocked = $this->callTool($token, 'tactical_set_default_alert_template', [
            'reason' => 'Try to set the global default.',
        ]);
        $this->assertTrue((bool) $blocked->json('result.isError'));
        $this->assertStringContainsString('refused to clobber', (string) $blocked->json('result.content.0.text'));

        $provision = Mockery::mock(TacticalProvisioningService::class);
        $provision->shouldReceive('provision')
            ->once()
            ->with(Mockery::type('int'))
            ->andReturn(['success' => true, 'message' => 'Tactical alert pipeline provisioned.']);
        $this->app->instance(TacticalProvisioningService::class, $provision);

        $provisioned = $this->decodedResult($this->callTool($token, 'tactical_provision_alert_ticketing', [
            'reason' => 'Run the existing no-clobber Tactical alert provisioning wrapper.',
        ]));
        $this->assertTrue($provisioned['success']);

        $allAudit = json_encode([
            TechnicianActionLog::pluck('summary')->all(),
            McpAuditLog::whereIn('tool_name', ['tactical_upsert_url_action', 'tactical_upsert_alert_template', 'tactical_provision_alert_ticketing'])->pluck('arguments')->all(),
        ]);
        $this->assertStringNotContainsString('webhook-secret-value', $allAudit);
        $this->assertStringNotContainsString('rest_headers', $allAudit);
        $this->assertStringNotContainsString('rest_body', $allAudit);
    }

    public function test_installer_generation_is_client_scoped_no_store_and_never_audits_returned_url(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $client = Client::factory()->create([
            'name' => 'Acme',
            'tactical_site_id' => 'Acme|Main',
        ]);
        $token = $this->token(['tactical_get_or_create_installer']);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getInstallerInfo')
            ->once()
            ->with('Acme|Main', 'windows')
            ->andReturn(new InstallerInfo(downloadUrl: 'https://downloads.example.test/agent.exe?token=signed-secret'));
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($token, 'tactical_get_or_create_installer', [
            'client_id' => $client->id,
            'platform' => 'windows',
            'reason' => 'Generate a short-lived onboarding installer link.',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $result = $this->decodedResult($response);
        $this->assertSame('https://downloads.example.test/agent.exe?token=signed-secret', $result['download_url']);

        $auditJson = json_encode([
            TechnicianActionLog::pluck('summary')->all(),
            McpAuditLog::where('tool_name', 'tactical_get_or_create_installer')->pluck('arguments')->all(),
        ]);
        $this->assertStringNotContainsString('signed-secret', $auditJson);
        $this->assertStringNotContainsString('downloads.example.test', $auditJson);
    }

    public function test_sync_admin_tools_require_reason_and_execute_existing_sync_wrappers(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $client = Client::factory()->create(['tactical_site_id' => 'Acme|Main']);
        $token = $this->token(['tactical_sync_devices_now', 'tactical_sync_scripts_now']);

        $missingReason = $this->callTool($token, 'tactical_sync_devices_now', [
            'client_id' => $client->id,
        ]);
        $this->assertTrue((bool) $missingReason->json('result.isError'));
        $this->assertStringContainsString('reason is required', (string) $missingReason->json('result.content.0.text'));

        $syncResult = new SyncResult;
        $syncResult->created = 1;
        $syncResult->updated = 2;

        $deviceSync = Mockery::mock(TacticalDeviceSyncService::class);
        $deviceSync->shouldReceive('syncDevices')
            ->once()
            ->with($client->id)
            ->andReturn($syncResult);
        $this->app->instance(TacticalDeviceSyncService::class, $deviceSync);

        $devices = $this->decodedResult($this->callTool($token, 'tactical_sync_devices_now', [
            'client_id' => $client->id,
            'reason' => 'Refresh local Tactical device inventory for this PSA client.',
        ]));
        $this->assertTrue($devices['success']);
        $this->assertSame('1 created, 2 updated', $devices['summary']);

        $scriptSync = Mockery::mock(TacticalScriptSyncService::class);
        $scriptSync->shouldReceive('syncScripts')
            ->once()
            ->andReturn(['synced' => 3, 'created' => 1, 'removed' => 0]);
        $this->app->instance(TacticalScriptSyncService::class, $scriptSync);

        $scripts = $this->decodedResult($this->callTool($token, 'tactical_sync_scripts_now', [
            'reason' => 'Refresh the local visible Tactical script catalog.',
        ]));
        $this->assertTrue($scripts['success']);
        $this->assertSame(3, $scripts['stats']['synced']);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_sync_scripts_now',
            'result_status' => 'executed',
        ]);
    }
}
