<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Models\Asset;
use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TacticalActionLog;
use App\Models\TacticalAsset;
use App\Models\TacticalScript;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tactical\DetailSyncResult;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalDeviceSyncService;
use App\Services\Technician\TechnicianApprovalService;
use App\Support\McpConfig;
use App\Support\McpToolModes;
use App\Support\McpToolRegistry;
use App\Support\McpToolSurface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class TacticalActionToolsPhase2Test extends TestCase
{
    use RefreshDatabase;

    private const PHASE_TWO_TOOLS = [
        'tactical_run_script',
        'tactical_stage_script',
        'tactical_run_command',
        'tactical_stage_command',
        'tactical_reboot_device',
        'tactical_stage_reboot',
        'tactical_shutdown_device',
        'tactical_stage_shutdown',
        'tactical_recover_mesh',
        'tactical_stage_recover_mesh',
        'tactical_set_maintenance',
        'tactical_stage_maintenance',
        'tactical_open_remote_control',
        'tactical_refresh_device_snapshot',
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

    public function test_phase_two_tactical_actions_are_sensitive_and_explicit_grant_only(): void
    {
        $this->configureTactical();

        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('tactical_action', $groups);
        $this->assertTrue($groups['tactical_action']['sensitive']);

        $actionNames = array_column($groups['tactical_action']['tools'], 'name');
        foreach (self::PHASE_TWO_TOOLS as $tool) {
            if (($canonical = McpToolModes::canonicalForAlias($tool)) !== null) {
                // Retired staged alias: callable, but the catalog carries only
                // the canonical capability (with a staged mode grant).
                $this->assertNotContains($tool, $actionNames, "{$tool} is a retired staged alias");
                $this->assertContains($canonical, $actionNames);

                continue;
            }
            $this->assertContains($tool, $actionNames, "{$tool} should be in the sensitive Tactical action group");
            $this->assertContains($tool, McpToolRegistry::allToolNames(), "{$tool} should be token-grantable");
        }

        $legacyNames = array_column($this->listTools($this->legacyToken()), 'name');
        foreach (self::PHASE_TWO_TOOLS as $tool) {
            $this->assertNotContains($tool, $legacyNames, "legacy full-surface token must not gain {$tool}");
        }

        $scopedTools = collect($this->listTools($this->token(['tactical_run_command', 'tactical_stage_command'])))
            ->keyBy('name');

        // Unified surface: one command tool with a `staged` parameter; the
        // legacy alias grant folds into the immediate mode grant.
        $this->assertTrue($scopedTools->has('tactical_run_command'));
        $this->assertFalse($scopedTools->has('tactical_stage_command'));
        $this->assertContains('client_id', $scopedTools['tactical_run_command']['inputSchema']['required']);
        $this->assertArrayHasKey('staged', $scopedTools['tactical_run_command']['inputSchema']['properties']);

        $commandDescription = $scopedTools['tactical_run_command']['description'];
        $this->assertStringContainsString('arbitrary remote code execution', $commandDescription);
        $this->assertStringContainsString('Requires an explicit token grant', $commandDescription);

        $shutdown = collect(McpToolRegistry::groups()['tactical_action']['tools'])
            ->firstWhere('name', 'tactical_shutdown_device');
        $this->assertStringContainsString('cannot be powered back on remotely', $shutdown['description']);
    }

    public function test_legacy_token_cannot_call_unpublished_tactical_diagnostic_or_new_actions(): void
    {
        $this->configureTactical();
        $fixture = $this->endpointFixture();
        $token = $this->legacyToken();

        foreach (['tactical_run_diagnostic', 'tactical_run_command'] as $tool) {
            $response = $this->callTool($token, $tool, [
                'client_id' => $fixture['client']->id,
                'hostname' => 'PC-01',
                'reason' => 'Legacy-token fence test.',
            ]);

            $response->assertOk();
            $this->assertTrue((bool) $response->json('result.isError'), "{$tool} should fail.");
            $this->assertStringContainsString('not allowed for this token', (string) $response->json('result.content.0.text'));

            // psa-vydpz — THE TWO TOOLS IN THIS LOOP ARE NOW REFUSED BY DIFFERENT GUARDS,
            // and the shared message above cannot distinguish them. Pinned explicitly so a
            // later reader is not misled into thinking both still exercise the fence:
            //
            //   tactical_run_diagnostic — never published, so the LIVENESS conjunct refuses
            //     it before the legacy-token fence is reached. This half would now stay
            //     green even if the fence were deleted; the fence is pinned elsewhere.
            //   tactical_run_command    — IS live (Tactical is configured above), so
            //     liveness passes and the LEGACY-TOKEN FENCE is what refuses it. This half
            //     still tests exactly what it always did.
            $expectedLive = $tool === 'tactical_run_command';

            $this->assertSame(
                $expectedLive,
                in_array($tool, McpToolSurface::liveToolNames(), true),
                $expectedLive
                    ? "{$tool} must be live, so the refusal above proves the legacy-token fence"
                    : "{$tool} must be unpublished, which is why liveness refuses it first"
            );
        }
    }

    public function test_direct_run_script_rejects_upstream_ids_then_executes_through_tactical_bus(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $script = TacticalScript::create([
            'tactical_script_id' => 201,
            'name' => 'Disk Health',
            'shell' => 'powershell',
            'hidden' => false,
            'synced_at' => now(),
        ]);
        $token = $this->token(['tactical_run_script']);

        $rejected = $this->callTool($token, 'tactical_run_script', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'script_id' => $script->id,
            'agent_id' => 'attacker-agent',
            'reason' => 'Should not be allowed to inject an upstream agent id.',
        ]);

        $rejected->assertOk();
        $this->assertTrue((bool) $rejected->json('result.isError'));
        $this->assertStringContainsString('upstream Tactical identifiers are not accepted', (string) $rejected->json('result.content.0.text'));
        $this->assertSame(0, TacticalActionLog::count());
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_run_script',
            'result_status' => 'rejected',
            'client_id' => $fixture['client']->id,
        ]);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('runScript')
            ->once()
            ->with('agent-1', 201, ['-Check', 'Disk'], 120)
            ->andReturn(['stdout' => 'Healthy', 'retcode' => 0]);
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($token, 'tactical_run_script', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'pc-01',
            'script_id' => $script->id,
            'args' => '-Check Disk',
            'timeout' => 120,
            'ticket_id' => $fixture['ticket']->id,
            'reason' => 'Run a scripted disk health check for this ticket.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $result = $this->decodedResult($response);
        $this->assertTrue($result['success']);
        $this->assertSame('ok', $result['tactical_status']);
        $this->assertSame('Healthy', $result['stdout']);

        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.run_script',
            'agent_id' => 'agent-1',
            'asset_id' => $fixture['asset']->id,
            'ticket_id' => $fixture['ticket']->id,
            'result_status' => 'ok',
        ]);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_run_script',
            'result_status' => 'executed',
            'ticket_id' => $fixture['ticket']->id,
            'client_id' => $fixture['client']->id,
            'actor_label' => 'mcp-staff:opsbot',
        ]);
    }

    public function test_direct_command_requires_reason_confirm_hostname_and_enforces_cooldown_before_upstream_call(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $token = $this->token(['tactical_run_command']);

        $missingReason = $this->callTool($token, 'tactical_run_command', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'confirm_hostname' => 'PC-01',
            'shell' => 'powershell',
            'cmd' => 'hostname',
            'timeout' => 30,
        ]);
        $this->assertTrue((bool) $missingReason->json('result.isError'));
        $this->assertStringContainsString('reason is required', (string) $missingReason->json('result.content.0.text'));

        $wrongHost = $this->callTool($token, 'tactical_run_command', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'confirm_hostname' => 'OTHER-PC',
            'shell' => 'powershell',
            'cmd' => 'hostname',
            'timeout' => 30,
            'reason' => 'Verify the device hostname.',
        ]);
        $this->assertTrue((bool) $wrongHost->json('result.isError'));
        $this->assertStringContainsString('typed hostname does not match', (string) $wrongHost->json('result.content.0.text'));

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('cmd')
            ->once()
            ->with('agent-1', 'hostname', 'powershell', 30)
            ->andReturn('PC-01');
        $this->app->instance(TacticalClient::class, $tactical);

        $first = $this->callTool($token, 'tactical_run_command', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'confirm_hostname' => 'pc-01',
            'shell' => 'powershell',
            'cmd' => 'hostname',
            'timeout' => 30,
            'reason' => 'Verify the device hostname.',
        ]);
        $first->assertOk();
        $this->assertFalse((bool) $first->json('result.isError'), (string) $first->json('result.content.0.text'));

        $second = $this->callTool($token, 'tactical_run_command', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'confirm_hostname' => 'PC-01',
            'shell' => 'powershell',
            'cmd' => 'whoami',
            'timeout' => 30,
            'reason' => 'Rapid second command should be blocked by cooldown.',
        ]);
        $this->assertTrue((bool) $second->json('result.isError'));
        $this->assertStringContainsString('cooldown', (string) $second->json('result.content.0.text'));

        $this->assertSame(1, TacticalActionLog::where('action_key', 'tactical.run_command')->count());
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'tactical_run_command')->where('result_status', 'executed')->count());
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'tactical_run_command')->where('result_status', 'blocked')->count());
    }

    public function test_other_direct_endpoint_actions_use_tactical_action_service_bus(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $token = $this->token([
            'tactical_reboot_device',
            'tactical_shutdown_device',
            'tactical_recover_mesh',
            'tactical_set_maintenance',
        ]);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('reboot')->once()->with('agent-1')->andReturn('ok');
        $tactical->shouldReceive('shutdown')->once()->with('agent-1')->andReturn('ok');
        $tactical->shouldReceive('recover')->once()->with('agent-1', 'mesh')->andReturn('recovered');
        $tactical->shouldReceive('setMaintenance')->once()->with('agent-1', true)->andReturn('ok');
        $this->app->instance(TacticalClient::class, $tactical);

        $calls = [
            ['tactical_reboot_device', ['confirm_hostname' => 'PC-01', 'reason' => 'Reboot after approved maintenance window.']],
            ['tactical_shutdown_device', ['confirm_hostname' => 'PC-01', 'reason' => 'Power down a retired workstation after approval.']],
            ['tactical_recover_mesh', ['reason' => 'Recover the Mesh agent before remote support.']],
            ['tactical_set_maintenance', ['enabled' => true, 'reason' => 'Suppress alerts during maintenance.']],
        ];

        foreach ($calls as [$tool, $arguments]) {
            $response = $this->callTool($token, $tool, [
                'client_id' => $fixture['client']->id,
                'hostname' => 'PC-01',
                ...$arguments,
            ]);

            $response->assertOk();
            $this->assertFalse((bool) $response->json('result.isError'), "{$tool}: ".(string) $response->json('result.content.0.text'));
        }

        foreach (['tactical.reboot', 'tactical.shutdown', 'tactical.recover', 'tactical.set_maintenance'] as $actionKey) {
            $this->assertDatabaseHas('tactical_action_logs', [
                'action_key' => $actionKey,
                'agent_id' => 'agent-1',
                'asset_id' => $fixture['asset']->id,
                'result_status' => 'ok',
            ]);
        }

        foreach (array_column($calls, 0) as $tool) {
            $this->assertDatabaseHas('technician_action_logs', [
                'action_type' => $tool,
                'result_status' => 'executed',
                'client_id' => $fixture['client']->id,
            ]);
        }
    }

    public function test_staged_command_is_held_with_encrypted_payload_and_approval_dispatches_the_bus(): void
    {
        $this->configureTactical();
        $approver = $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $token = $this->token(['tactical_stage_command']);
        $command = 'net user admin SuperSecret123';

        $response = $this->callTool($token, 'tactical_stage_command', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'hostname' => 'PC-01',
            'shell' => 'cmd',
            'cmd' => $command,
            'timeout' => 30,
            'reason' => 'Need a human to approve this endpoint command.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TacticalActionLog::count());

        $run = TechnicianRun::where('action_type', 'tactical_stage_command')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertStringContainsString('[REDACTED:credential]', $run->proposed_content);
        $this->assertStringNotContainsString('SuperSecret123', $run->proposed_content);
        $this->assertStringNotContainsString('SuperSecret123', json_encode($run->proposed_meta));
        $this->assertNotEmpty($run->proposed_meta['encrypted_payload'] ?? null);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_stage_command',
            'result_status' => 'awaiting_approval',
            'ticket_id' => $fixture['ticket']->id,
            'client_id' => $fixture['client']->id,
        ]);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('cmd')
            ->once()
            ->with('agent-1', $command, 'cmd', 30)
            ->andReturn('done');
        $this->app->instance(TacticalClient::class, $tactical);

        $result = app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id);

        $this->assertSame('executed', $result->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.run_command',
            'agent_id' => 'agent-1',
            'asset_id' => $fixture['asset']->id,
            'ticket_id' => $fixture['ticket']->id,
            'result_status' => 'ok',
        ]);
        $this->assertSame(
            'net user admin [REDACTED:credential]',
            TacticalActionLog::where('action_key', 'tactical.run_command')->latest('id')->firstOrFail()->params['cmd'],
        );
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_stage_command',
            'result_status' => 'executed',
            'ticket_id' => $fixture['ticket']->id,
            'approver_user_id' => $approver->id,
        ]);
    }

    public function test_remote_control_returns_no_store_url_and_never_audits_the_url(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $token = $this->token(['tactical_open_remote_control']);
        $url = 'https://mesh.example.test/control/session-token';

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getMeshCentralLinks')
            ->once()
            ->with('agent-1')
            ->andReturn(['control' => $url]);
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($token, 'tactical_open_remote_control', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'type' => 'control',
            'reason' => 'Open an operator remote support session.',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertSame($url, $this->decodedResult($response)['url']);

        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.remote_control',
            'agent_id' => 'agent-1',
            'asset_id' => $fixture['asset']->id,
            'result_status' => 'ok',
        ]);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_open_remote_control',
            'result_status' => 'executed',
            'client_id' => $fixture['client']->id,
        ]);

        $this->assertStringNotContainsString($url, (string) json_encode(TacticalActionLog::firstOrFail()->toArray()));
        $this->assertStringNotContainsString($url, (string) json_encode(TechnicianActionLog::firstOrFail()->toArray()));
        $this->assertStringNotContainsString($url, (string) json_encode(McpAuditLog::where('tool_name', 'tactical_open_remote_control')->firstOrFail()->arguments));
    }

    public function test_refresh_snapshot_requires_reason_and_cooldown_but_does_not_use_action_bus(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $token = $this->token(['tactical_refresh_device_snapshot']);

        $sync = Mockery::mock(TacticalDeviceSyncService::class);
        $sync->shouldReceive('syncDeviceDetail')
            ->once()
            ->with(Mockery::on(fn (Asset $asset): bool => $asset->is($fixture['asset'])))
            ->andReturn(DetailSyncResult::success('online', now()));
        $this->app->instance(TacticalDeviceSyncService::class, $sync);

        $first = $this->callTool($token, 'tactical_refresh_device_snapshot', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'reason' => 'Refresh the local endpoint snapshot before advising.',
        ]);
        $first->assertOk();
        $this->assertFalse((bool) $first->json('result.isError'), (string) $first->json('result.content.0.text'));

        $second = $this->callTool($token, 'tactical_refresh_device_snapshot', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'reason' => 'Second refresh should be blocked by cooldown.',
        ]);
        $this->assertTrue((bool) $second->json('result.isError'));
        $this->assertStringContainsString('cooldown', (string) $second->json('result.content.0.text'));

        $this->assertSame(0, TacticalActionLog::count());
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'tactical_refresh_device_snapshot')->where('result_status', 'executed')->count());
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'tactical_refresh_device_snapshot')->where('result_status', 'blocked')->count());
    }
}
