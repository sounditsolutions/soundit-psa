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
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
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

    public function test_direct_command_requires_reason_confirm_hostname_and_has_no_dispatch_cooldown(): void
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
        $tactical->shouldReceive('cmd')
            ->once()
            ->with('agent-1', 'whoami', 'powershell', 30)
            ->andReturn('corp\\svc');
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

        // No dispatch cooldown (Charlie's ruling, T-22782): a rapid second DISTINCT
        // command reaches upstream immediately.
        $second = $this->callTool($token, 'tactical_run_command', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'confirm_hostname' => 'PC-01',
            'shell' => 'powershell',
            'cmd' => 'whoami',
            'timeout' => 30,
            'reason' => 'Rapid second distinct command dispatches without a cooldown.',
        ]);
        $second->assertOk();
        $this->assertFalse((bool) $second->json('result.isError'), (string) $second->json('result.content.0.text'));

        // Identical-content dedup is NOT the cooldown and must survive its removal:
        // re-sending the exact first command is answered idempotent, no upstream call
        // (the mock's once() on 'hostname' enforces that no second call was made).
        $identical = $this->callTool($token, 'tactical_run_command', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'confirm_hostname' => 'PC-01',
            'shell' => 'powershell',
            'cmd' => 'hostname',
            'timeout' => 30,
            'reason' => 'Verify the device hostname.',
        ]);
        $identical->assertOk();
        $this->assertFalse((bool) $identical->json('result.isError'), (string) $identical->json('result.content.0.text'));
        $this->assertStringContainsString('Already executed', (string) $identical->json('result.content.0.text'));

        $this->assertSame(2, TacticalActionLog::where('action_key', 'tactical.run_command')->count());
        $this->assertSame(2, TechnicianActionLog::where('action_type', 'tactical_run_command')->where('result_status', 'executed')->count());
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

    // ── psa-5s4r2: stage-gate tactical_open_remote_control (Charlie GO / so-1jq4) ──

    public function test_open_remote_control_is_stageable_and_alias_round_trips(): void
    {
        $this->assertTrue(McpToolModes::isStageable('tactical_open_remote_control'));
        $this->assertSame('tactical_open_remote_control', McpToolModes::canonicalForAlias('tactical_stage_open_remote_control'));
        $this->assertSame('tactical_stage_open_remote_control', McpToolModes::stagedInternalFor('tactical_open_remote_control'));
    }

    /**
     * THE fail-closed proof: a staged-only grant that asks to open a session
     * immediately is held for approval — the MeshCentral link is NEVER minted
     * without a human, i.e. no live remote session opens un-approved.
     */
    public function test_staged_only_grant_downgrades_an_immediate_remote_control_call(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $token = $this->token(['tactical_open_remote_control:staged']);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldNotReceive('getMeshCentralLinks'); // no link minted without approval
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($token, 'tactical_open_remote_control', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'hostname' => 'PC-01',
            'type' => 'control',
            'reason' => 'Open a remote session now.',
            'staged' => false,
        ]);

        $response->assertOk();
        $result = $this->decodedResult($response);
        $this->assertTrue((bool) ($result['downgraded_to_staged'] ?? false), 'immediate remote-control call without the immediate grant must downgrade to staged');

        $run = TechnicianRun::where('action_type', 'tactical_stage_open_remote_control')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
    }

    public function test_staged_remote_control_is_held_then_approval_mints_the_link(): void
    {
        $this->configureTactical();
        $approver = $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $token = $this->token(['tactical_stage_open_remote_control']);
        $url = 'https://mesh.example.test/control/session-token';

        $tactical = Mockery::mock(TacticalClient::class);
        // Minted ONCE, at APPROVAL time (fresh URL) — never at proposal time.
        $tactical->shouldReceive('getMeshCentralLinks')->once()->with('agent-1')->andReturn(['control' => $url]);
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($token, 'tactical_stage_open_remote_control', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'hostname' => 'PC-01',
            'type' => 'control',
            'reason' => 'Open a remote session after cockpit approval.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $run = TechnicianRun::where('action_type', 'tactical_stage_open_remote_control')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertStringContainsString('Open control remote-control session', (string) $run->proposed_content);

        $result = app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id);
        $this->assertSame('executed', $result->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        // Increment 2: the freshly-minted URL rides back to the approver on the
        // transient one-time secret channel (never a persisted field).
        $this->assertSame($url, $result->secret);
    }

    /**
     * Increment 2 — the payoff + the leak fence. Approving a staged remote-control
     * run must hand the freshly-minted MeshCentral URL to the operator ONCE (JSON
     * `secret`, Cache-Control:no-store, never flashed to the session) AND that URL
     * must appear in NO persistent sink — including tactical_action_logs.output,
     * which the immediate path avoids by being off-bus but the staged path routes
     * through TacticalActionService::audit(). Mirrors the CIPP create-user
     * one-time-password contract and the immediate path's no-audit assertion.
     */
    public function test_staged_remote_control_approval_delivers_url_no_store_and_never_persists_it(): void
    {
        $this->configureTactical();
        $approver = $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $token = $this->token(['tactical_stage_open_remote_control']);
        $url = 'https://mesh.example.test/control/session-token';

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getMeshCentralLinks')->once()->with('agent-1')->andReturn(['control' => $url]);
        $this->app->instance(TacticalClient::class, $tactical);

        $staged = $this->callTool($token, 'tactical_stage_open_remote_control', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'hostname' => 'PC-01',
            'type' => 'control',
            'reason' => 'Open a remote session after cockpit approval.',
        ]);
        $staged->assertOk();
        $this->assertFalse((bool) $staged->json('result.isError'), (string) $staged->json('result.content.0.text'));

        $run = TechnicianRun::where('action_type', 'tactical_stage_open_remote_control')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        $logged = [];
        Log::listen(function (MessageLogged $m) use (&$logged) {
            $logged[] = $m->message.' '.json_encode($m->context);
        });

        $approval = $this->actingAs($approver)->postJson(route('cockpit.approve', $run));

        $approval->assertOk();
        $this->assertTrue((bool) $approval->json('ok'));
        $this->assertSame('executed', $approval->json('status'));
        // Delivered exactly once, in this response only, on a no-store payload.
        $this->assertSame($url, $approval->json('secret'));
        $this->assertStringContainsString('no-store', (string) $approval->headers->get('Cache-Control'));
        $this->assertStringNotContainsString($url, (string) $approval->json('message'));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_stage_open_remote_control',
            'result_status' => 'executed',
            'ticket_id' => $fixture['ticket']->id,
            'client_id' => $fixture['client']->id,
            'approver_user_id' => $approver->id,
        ]);

        // The live URL exists in NO persistent sink and NO log line. tactical_action_logs
        // is the one the on-bus staged path could leak into (output = redacted stdout).
        $this->assertStringNotContainsString($url, json_encode(TacticalActionLog::all()->toArray()));
        $this->assertStringNotContainsString($url, json_encode(TechnicianActionLog::all()->toArray()));
        $this->assertStringNotContainsString($url, json_encode(McpAuditLog::all()->toArray()));
        $this->assertStringNotContainsString($url, json_encode(TechnicianRun::all()->toArray()));
        foreach ($logged as $line) {
            $this->assertStringNotContainsString($url, $line, 'remote-control URL leaked into a log line');
        }
    }

    /**
     * ARCH re-gate blocker (psa-reuko): staged + immediate remote-control must share
     * ONE canonical TacticalActionLog key (tactical.remote_control) so cooldown, audit
     * history, and reporting do not split. Inc1's RemoteControlAction keyed itself
     * tactical.open_remote_control, so the bus wrote THAT on a staged approval — the
     * immediate path's cooldown (which looks up tactical.remote_control) never saw it,
     * and the next immediate open bypassed the cooldown.
     */
    public function test_staged_and_immediate_remote_control_share_the_canonical_cooldown_key(): void
    {
        $this->configureTactical();
        $approver = $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $url = 'https://mesh.example.test/control/session-token';

        // Minted exactly ONCE — at the staged approval. The immediate call that
        // follows must be refused by the cooldown BEFORE any second upstream call.
        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getMeshCentralLinks')->once()->with('agent-1')->andReturn(['control' => $url]);
        $this->app->instance(TacticalClient::class, $tactical);

        $staged = $this->callTool($this->token(['tactical_stage_open_remote_control']), 'tactical_stage_open_remote_control', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'hostname' => 'PC-01',
            'type' => 'control',
            'reason' => 'Stage a remote session for approval.',
        ]);
        $staged->assertOk();
        $run = TechnicianRun::where('action_type', 'tactical_stage_open_remote_control')->firstOrFail();
        app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id);

        // (1) The staged approval logged under the CANONICAL key — the same key the
        // immediate path (auditRemoteControl) uses — not the tool-name-derived one.
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.remote_control',
            'asset_id' => $fixture['asset']->id,
            'result_status' => 'ok',
        ]);
        $this->assertDatabaseMissing('tactical_action_logs', ['action_key' => 'tactical.open_remote_control']);

        // (2) A subsequent IMMEDIATE open on the same asset is cooldown-blocked by that
        // staged log — proving both paths share one cooldown key. getMeshCentralLinks is
        // NOT called again: the refusal lands before any upstream call.
        $immediate = $this->callTool($this->token(['tactical_open_remote_control']), 'tactical_open_remote_control', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'type' => 'control',
            'reason' => 'Immediate open right after the staged session.',
        ]);
        $immediate->assertOk();
        $this->assertTrue((bool) $immediate->json('result.isError'), (string) $immediate->json('result.content.0.text'));
        $this->assertStringContainsString('cooldown', (string) $immediate->json('result.content.0.text'));
    }
}
