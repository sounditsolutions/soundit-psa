<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TacticalActionLog;
use App\Models\TacticalAsset;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use App\Services\Technician\TechnicianApprovalService;
use App\Support\McpConfig;
use App\Support\McpToolModes;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class TacticalServiceControlPhase4Test extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_TOOLS = [
        'tactical_start_service',
        'tactical_stop_service',
        'tactical_stage_stop_service',
        'tactical_restart_service',
        'tactical_stage_restart_service',
        'tactical_set_service_start_type',
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

    /** @return array<int, array<string, mixed>> */
    private function services(): array
    {
        return [
            [
                'name' => 'Spooler',
                'display_name' => 'Print Spooler',
                'status' => 'running',
                'start_type' => 'auto',
            ],
            [
                'name' => 'BITS',
                'display_name' => 'Background Intelligent Transfer Service',
                'status' => 'stopped',
                'start_type' => 'manual',
            ],
        ];
    }

    public function test_service_control_tools_are_sensitive_and_explicit_grant_only(): void
    {
        $this->configureTactical();

        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('tactical_action', $groups);
        $this->assertTrue($groups['tactical_action']['sensitive']);

        $actionNames = array_column($groups['tactical_action']['tools'], 'name');
        foreach (self::SERVICE_TOOLS as $tool) {
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
        foreach (self::SERVICE_TOOLS as $tool) {
            $this->assertNotContains($tool, $legacyNames, "legacy full-surface token must not gain {$tool}");
        }

        $scopedTools = collect($this->listTools($this->token(['tactical_stop_service', 'tactical_stage_restart_service'])))
            ->keyBy('name');

        // Immediate grant: the unified tool keeps the direct schema (typed
        // confirmation friction) plus the staged parameter.
        $this->assertTrue($scopedTools->has('tactical_stop_service'));
        $this->assertContains('client_id', $scopedTools['tactical_stop_service']['inputSchema']['required']);
        $this->assertContains('confirm_hostname', $scopedTools['tactical_stop_service']['inputSchema']['required']);
        $this->assertContains('confirm_service_name', $scopedTools['tactical_stop_service']['inputSchema']['required']);
        $this->assertStringContainsString('interrupt applications or dependent services', $scopedTools['tactical_stop_service']['description']);

        // Staged-only grant (via the legacy alias): the capability is
        // advertised under its canonical name with the staged variant's
        // schema — ticket_id required, no typed-confirmation friction.
        $this->assertFalse($scopedTools->has('tactical_stage_restart_service'));
        $restart = $scopedTools['tactical_restart_service'];
        $this->assertContains('ticket_id', $restart['inputSchema']['required']);
        $this->assertArrayNotHasKey('confirm_hostname', $restart['inputSchema']['properties']);
        $this->assertArrayHasKey('staged', $restart['inputSchema']['properties']);
        $this->assertStringContainsString('staged mode only', $restart['description']);
    }

    public function test_direct_start_service_rejects_upstream_ids_then_executes_with_resolved_service(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $token = $this->token(['tactical_start_service']);

        $rejected = $this->callTool($token, 'tactical_start_service', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'service_name' => 'Spooler',
            'agent_id' => 'attacker-agent',
            'reason' => 'Should not accept upstream Tactical agent IDs.',
        ]);

        $rejected->assertOk();
        $this->assertTrue((bool) $rejected->json('result.isError'));
        $this->assertStringContainsString('upstream Tactical identifiers are not accepted', (string) $rejected->json('result.content.0.text'));
        $this->assertSame(0, TacticalActionLog::count());
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_start_service',
            'result_status' => 'rejected',
            'client_id' => $fixture['client']->id,
        ]);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getServices')->once()->with('agent-1')->andReturn($this->services());
        $tactical->shouldReceive('controlService')->once()->with('agent-1', 'Spooler', 'start')->andReturn('The service was started successfully');
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($token, 'tactical_start_service', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'pc-01',
            'service_name' => 'spooler',
            'ticket_id' => $fixture['ticket']->id,
            'reason' => 'Start the print spooler for this ticket.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $result = $this->decodedResult($response);
        $this->assertTrue($result['success']);
        $this->assertSame('ok', $result['tactical_status']);

        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.service_start',
            'agent_id' => 'agent-1',
            'asset_id' => $fixture['asset']->id,
            'ticket_id' => $fixture['ticket']->id,
            'result_status' => 'ok',
        ]);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_start_service',
            'result_status' => 'executed',
            'ticket_id' => $fixture['ticket']->id,
            'client_id' => $fixture['client']->id,
        ]);
        $this->assertDatabaseHas('mcp_audit_logs', [
            'tool_name' => 'tactical_start_service',
            'status' => 'success',
        ]);
    }

    public function test_direct_stop_service_requires_confirm_friction_and_cooldown_before_mutating_call(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $token = $this->token(['tactical_stop_service']);

        $missingConfirm = $this->callTool($token, 'tactical_stop_service', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'service_name' => 'Spooler',
            'reason' => 'Stop a stuck service.',
        ]);
        $this->assertTrue((bool) $missingConfirm->json('result.isError'));
        $this->assertStringContainsString('typed hostname', (string) $missingConfirm->json('result.content.0.text'));

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getServices')->twice()->with('agent-1')->andReturn($this->services());
        $tactical->shouldReceive('controlService')->once()->with('agent-1', 'Spooler', 'stop')->andReturn('The service was stopped successfully');
        $this->app->instance(TacticalClient::class, $tactical);

        $first = $this->callTool($token, 'tactical_stop_service', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'service_name' => 'Spooler',
            'confirm_hostname' => 'pc-01',
            'confirm_service_name' => 'spooler',
            'reason' => 'Stop a stuck print spooler after user confirmation.',
        ]);
        $first->assertOk();
        $this->assertFalse((bool) $first->json('result.isError'), (string) $first->json('result.content.0.text'));

        $second = $this->callTool($token, 'tactical_stop_service', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'service_name' => 'BITS',
            'confirm_hostname' => 'PC-01',
            'confirm_service_name' => 'BITS',
            'reason' => 'Rapid second service stop should be blocked.',
        ]);
        $this->assertTrue((bool) $second->json('result.isError'));
        $this->assertStringContainsString('cooldown', (string) $second->json('result.content.0.text'));

        $this->assertSame(1, TacticalActionLog::where('action_key', 'tactical.service_stop')->count());
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'tactical_stop_service')->where('result_status', 'executed')->count());
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'tactical_stop_service')->where('result_status', 'blocked')->count());
    }

    public function test_staged_restart_service_is_held_then_approval_dispatches_service_action(): void
    {
        $this->configureTactical();
        $approver = $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $token = $this->token(['tactical_stage_restart_service']);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getServices')->twice()->with('agent-1')->andReturn($this->services());
        $tactical->shouldReceive('controlService')->once()->with('agent-1', 'Spooler', 'restart')->andReturn('The service was restarted successfully');
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($token, 'tactical_stage_restart_service', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'hostname' => 'PC-01',
            'service_name' => 'Print Spooler',
            'reason' => 'Restart spooler only after cockpit approval.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TacticalActionLog::count());

        $run = TechnicianRun::where('action_type', 'tactical_stage_restart_service')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertStringContainsString('Restart service Spooler', $run->proposed_content);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_stage_restart_service',
            'result_status' => 'awaiting_approval',
            'ticket_id' => $fixture['ticket']->id,
            'client_id' => $fixture['client']->id,
        ]);

        $result = app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id);

        $this->assertSame('executed', $result->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.service_restart',
            'agent_id' => 'agent-1',
            'asset_id' => $fixture['asset']->id,
            'ticket_id' => $fixture['ticket']->id,
            'result_status' => 'ok',
        ]);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_stage_restart_service',
            'result_status' => 'executed',
            'ticket_id' => $fixture['ticket']->id,
            'approver_user_id' => $approver->id,
        ]);
    }

    // ── psa-aji2o: stage-gate tactical_start_service (Charlie GO / so-1jq4) ──

    /** Registering the staged twin flips start_service into the fail-closed mode gate. */
    public function test_start_service_is_stageable_and_alias_round_trips(): void
    {
        $this->assertTrue(McpToolModes::isStageable('tactical_start_service'));
        $this->assertSame('tactical_start_service', McpToolModes::canonicalForAlias('tactical_stage_start_service'));
        $this->assertSame('tactical_stage_start_service', McpToolModes::stagedInternalFor('tactical_start_service'));
    }

    public function test_staged_start_service_is_held_then_approval_dispatches_start(): void
    {
        $this->configureTactical();
        $approver = $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $token = $this->token(['tactical_stage_start_service']);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getServices')->twice()->with('agent-1')->andReturn($this->services());
        $tactical->shouldReceive('controlService')->once()->with('agent-1', 'Spooler', 'start')->andReturn('The service was started successfully');
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($token, 'tactical_stage_start_service', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'hostname' => 'PC-01',
            'service_name' => 'Print Spooler',
            'reason' => 'Start spooler only after cockpit approval.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TacticalActionLog::count(), 'Nothing executes while the proposal is held.');

        $run = TechnicianRun::where('action_type', 'tactical_stage_start_service')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        // The staged approval card must name the EXACT service (product + UX review, PR #311 R1)
        // — an approver deciding "start a service" has to see which one.
        $this->assertStringContainsString('Start service Spooler', (string) $run->proposed_content);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_stage_start_service',
            'result_status' => 'awaiting_approval',
            'ticket_id' => $fixture['ticket']->id,
            'client_id' => $fixture['client']->id,
        ]);

        $result = app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id);

        $this->assertSame('executed', $result->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.service_start',
            'agent_id' => 'agent-1',
            'asset_id' => $fixture['asset']->id,
            'ticket_id' => $fixture['ticket']->id,
            'result_status' => 'ok',
        ]);
    }

    /**
     * THE fail-closed proof (Charlie's "checked = safe-staged"): a staged-only grant
     * that asks for immediate execution is auto-downgraded to a held proposal — the
     * upstream start call is NEVER made without human approval.
     */
    public function test_staged_only_grant_downgrades_an_immediate_start_call(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $token = $this->token(['tactical_start_service:staged']);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getServices')->atLeast()->once()->with('agent-1')->andReturn($this->services());
        $tactical->shouldNotReceive('controlService'); // no immediate mutation
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($token, 'tactical_start_service', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'hostname' => 'PC-01',
            'service_name' => 'Print Spooler',
            'reason' => 'Start the spooler now.',
            'staged' => false,
        ]);

        $response->assertOk();
        $result = $this->decodedResult($response);
        $this->assertTrue((bool) ($result['downgraded_to_staged'] ?? false), 'immediate call without the immediate grant must downgrade to staged');
        $this->assertSame(0, TacticalActionLog::count());

        $run = TechnicianRun::where('action_type', 'tactical_stage_start_service')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
    }

    /** An explicit immediate grant still executes now — the capability is preserved, just gated. */
    public function test_immediate_grant_still_executes_start_now(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $token = $this->token(['tactical_start_service:immediate']);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getServices')->once()->with('agent-1')->andReturn($this->services());
        $tactical->shouldReceive('controlService')->once()->with('agent-1', 'Spooler', 'start')->andReturn('The service was started successfully');
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($token, 'tactical_start_service', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'service_name' => 'Spooler',
            'ticket_id' => $fixture['ticket']->id,
            'reason' => 'Start the print spooler now.',
            'staged' => false,
        ]);

        $response->assertOk();
        $result = $this->decodedResult($response);
        $this->assertTrue((bool) ($result['success'] ?? false), (string) $response->json('result.content.0.text'));
        $this->assertArrayNotHasKey('downgraded_to_staged', $result);
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.service_start',
            'result_status' => 'ok',
        ]);
    }

    public function test_set_service_start_type_uses_allowlist_and_resolved_service(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $token = $this->token(['tactical_set_service_start_type']);

        $invalid = $this->callTool($token, 'tactical_set_service_start_type', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'service_name' => 'BITS',
            'start_type' => 'boot',
            'reason' => 'Invalid start type should be rejected.',
        ]);
        $this->assertTrue((bool) $invalid->json('result.isError'));
        $this->assertStringContainsString('start_type must be one of', (string) $invalid->json('result.content.0.text'));
        $this->assertSame(0, TacticalActionLog::count());

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getServices')->once()->with('agent-1')->andReturn($this->services());
        $tactical->shouldReceive('setServiceStartType')->once()->with('agent-1', 'BITS', 'autodelay')->andReturn('The service start type was updated successfully');
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($token, 'tactical_set_service_start_type', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'service_name' => 'Background Intelligent Transfer Service',
            'start_type' => 'autodelay',
            'reason' => 'Delay BITS startup after approved maintenance.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.service_start_type',
            'agent_id' => 'agent-1',
            'asset_id' => $fixture['asset']->id,
            'result_status' => 'ok',
        ]);
    }
}
