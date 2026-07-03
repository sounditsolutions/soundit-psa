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
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use App\Services\Technician\TechnicianApprovalService;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class TacticalPatchManagementPhase4Test extends TestCase
{
    use RefreshDatabase;

    private const PATCH_ACTION_TOOLS = [
        'tactical_scan_patches',
        'tactical_set_patch_action',
        'tactical_install_approved_patches',
        'tactical_stage_install_approved_patches',
    ];

    private const PATCH_POLICY_TOOLS = [
        'tactical_create_patch_policy',
        'tactical_update_patch_policy',
        'tactical_delete_patch_policy',
        'tactical_reset_patch_policies',
        'tactical_stage_reset_patch_policies',
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
        $client = Client::factory()->create(['name' => 'Acme', 'tactical_site_id' => 'Acme|Main']);
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
            'subject' => 'Patch maintenance',
        ]);
        $ticket->assets()->attach($asset->id, ['is_primary' => true]);

        return compact('client', 'asset', 'tactical', 'ticket');
    }

    /** @return array<int, array<string, mixed>> */
    private function patches(): array
    {
        return [
            [
                'id' => 44,
                'guid' => 'guid-44',
                'kb' => 'KB5000001',
                'title' => 'Security Update',
                'installed' => false,
                'action' => 'manual',
            ],
            [
                'id' => 45,
                'guid' => 'guid-45',
                'kb' => 'KB5000002',
                'title' => 'Driver Update',
                'installed' => false,
                'action' => 'ignore',
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function policies(): array
    {
        return [
            ['id' => 7, 'name' => 'Workstations', 'winupdatepolicy' => null],
            ['id' => 8, 'name' => 'Servers', 'winupdatepolicy' => ['id' => 501]],
        ];
    }

    public function test_patch_management_tools_are_sensitive_and_explicit_grant_only(): void
    {
        $this->configureTactical();

        $groups = McpToolRegistry::groups();
        $actionNames = array_column($groups['tactical_action']['tools'], 'name');
        $adminNames = array_column($groups['tactical_admin']['tools'], 'name');

        foreach (self::PATCH_ACTION_TOOLS as $tool) {
            $this->assertContains($tool, $actionNames, "{$tool} should be a sensitive Tactical action tool");
            $this->assertContains($tool, McpToolRegistry::allToolNames(), "{$tool} should be token-grantable");
        }
        foreach (self::PATCH_POLICY_TOOLS as $tool) {
            $this->assertContains($tool, $adminNames, "{$tool} should be a sensitive Tactical admin tool");
            $this->assertContains($tool, McpToolRegistry::allToolNames(), "{$tool} should be token-grantable");
        }

        $legacyNames = array_column($this->listTools($this->legacyToken()), 'name');
        foreach (array_merge(self::PATCH_ACTION_TOOLS, self::PATCH_POLICY_TOOLS) as $tool) {
            $this->assertNotContains($tool, $legacyNames, "legacy full-surface token must not gain {$tool}");
        }

        $scoped = collect($this->listTools($this->token([
            'tactical_install_approved_patches',
            'tactical_stage_reset_patch_policies',
        ])))->keyBy('name');

        $this->assertStringContainsString('Approved Windows updates can reboot', $scoped['tactical_install_approved_patches']['description']);
        $this->assertContains('confirm_hostname', $scoped['tactical_install_approved_patches']['inputSchema']['required']);
        $this->assertStringContainsString('bulk reset', $scoped['tactical_stage_reset_patch_policies']['description']);
        $this->assertContains('ticket_id', $scoped['tactical_stage_reset_patch_policies']['inputSchema']['required']);
    }

    public function test_direct_patch_scan_and_action_use_server_derived_agent_and_visible_patch_scope(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $token = $this->token(['tactical_scan_patches', 'tactical_set_patch_action']);

        $rejected = $this->callTool($token, 'tactical_set_patch_action', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'winupdate_id' => 44,
            'action' => 'approve',
            'reason' => 'Caller supplied upstream update id.',
        ]);

        $this->assertTrue((bool) $rejected->json('result.isError'));
        $this->assertStringContainsString('upstream Tactical identifiers are not accepted', (string) $rejected->json('result.content.0.text'));
        $this->assertSame(0, TacticalActionLog::count());

        $invalid = $this->callTool($token, 'tactical_set_patch_action', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'patch_id' => 44,
            'action' => 'install',
            'reason' => 'Invalid action should fail before Tactical.',
        ]);
        $this->assertTrue((bool) $invalid->json('result.isError'));
        $this->assertStringContainsString('action must be one of', (string) $invalid->json('result.content.0.text'));

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('scanPatches')->once()->with('agent-1')->andReturn('scan queued');
        $tactical->shouldReceive('getPatches')->once()->with('agent-1')->andReturn($this->patches());
        $tactical->shouldReceive('setPatchAction')->once()->with(44, 'approve')->andReturn('Windows update KB5000001 was changed to approve');
        $this->app->instance(TacticalClient::class, $tactical);

        $scan = $this->callTool($token, 'tactical_scan_patches', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'pc-01',
            'reason' => 'Scan before maintenance window.',
        ]);
        $this->assertFalse((bool) $scan->json('result.isError'), (string) $scan->json('result.content.0.text'));

        $approve = $this->callTool($token, 'tactical_set_patch_action', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'patch_id' => 44,
            'action' => 'APPROVE',
            'ticket_id' => $fixture['ticket']->id,
            'reason' => 'Approve the security update for this ticket.',
        ]);
        $this->assertFalse((bool) $approve->json('result.isError'), (string) $approve->json('result.content.0.text'));

        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.patch_action',
            'agent_id' => 'agent-1',
            'asset_id' => $fixture['asset']->id,
            'ticket_id' => $fixture['ticket']->id,
            'result_status' => 'ok',
        ]);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_set_patch_action',
            'result_status' => 'executed',
            'client_id' => $fixture['client']->id,
        ]);
        $this->assertDatabaseHas('mcp_audit_logs', [
            'tool_name' => 'tactical_set_patch_action',
            'status' => 'success',
        ]);
    }

    public function test_install_approved_patches_direct_confirm_and_staged_approval(): void
    {
        $this->configureTactical();
        $approver = $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $token = $this->token(['tactical_install_approved_patches', 'tactical_stage_install_approved_patches']);

        $missingConfirm = $this->callTool($token, 'tactical_install_approved_patches', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'reason' => 'Install approved patches.',
        ]);
        $this->assertTrue((bool) $missingConfirm->json('result.isError'));
        $this->assertStringContainsString('typed hostname', (string) $missingConfirm->json('result.content.0.text'));

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('installApprovedPatches')->twice()->with('agent-1')->andReturn('Approved patches will now be installed on PC-01');
        $this->app->instance(TacticalClient::class, $tactical);

        $direct = $this->callTool($token, 'tactical_install_approved_patches', [
            'client_id' => $fixture['client']->id,
            'hostname' => 'PC-01',
            'confirm_hostname' => 'pc-01',
            'confirm_install' => 'install approved patches',
            'reason' => 'Install approved updates during the maintenance window.',
        ]);
        $this->assertFalse((bool) $direct->json('result.isError'), (string) $direct->json('result.content.0.text'));

        $staged = $this->callTool($token, 'tactical_stage_install_approved_patches', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'hostname' => 'PC-01',
            'reason' => 'Hold patch install for cockpit approval.',
        ]);
        $this->assertFalse((bool) $staged->json('result.isError'), (string) $staged->json('result.content.0.text'));

        $run = TechnicianRun::where('action_type', 'tactical_stage_install_approved_patches')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertStringContainsString('Install approved Windows patches', $run->proposed_content);

        $this->travel(11)->minutes();
        $result = app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id);

        $this->assertSame('executed', $result->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame(2, TacticalActionLog::where('action_key', 'tactical.patch_install')->count());
    }

    public function test_patchpolicy_crud_resolves_policy_scope_and_narrows_fields(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $token = $this->token([
            'tactical_create_patch_policy',
            'tactical_update_patch_policy',
            'tactical_delete_patch_policy',
        ]);

        $rejected = $this->callTool($token, 'tactical_create_patch_policy', [
            'client_id' => $fixture['client']->id,
            'reason' => 'Reject upstream ids.',
            'policy' => 7,
            'critical' => 'approve',
        ]);
        $this->assertTrue((bool) $rejected->json('result.isError'));
        $this->assertStringContainsString('upstream Tactical identifiers are not accepted', (string) $rejected->json('result.content.0.text'));

        $badField = $this->callTool($token, 'tactical_create_patch_policy', [
            'client_id' => $fixture['client']->id,
            'reason' => 'Reject broad serializer fields.',
            'policy_id' => 7,
            'excluded_agents' => [123],
        ]);
        $this->assertTrue((bool) $badField->json('result.isError'));
        $this->assertStringContainsString('Unsupported patch policy fields', (string) $badField->json('result.content.0.text'));

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getPolicies')->times(3)->andReturn($this->policies());
        $tactical->shouldReceive('createPatchPolicy')->once()->with([
            'policy' => 7,
            'critical' => 'approve',
            'important' => 'manual',
            'reboot_after_install' => 'never',
        ])->andReturn('ok');
        $tactical->shouldReceive('updatePatchPolicy')->once()->with(501, [
            'critical' => 'ignore',
        ])->andReturn('ok');
        $tactical->shouldReceive('deletePatchPolicy')->once()->with(501)->andReturn('ok');
        $this->app->instance(TacticalClient::class, $tactical);

        $created = $this->callTool($token, 'tactical_create_patch_policy', [
            'client_id' => $fixture['client']->id,
            'reason' => 'Create workstation patch policy.',
            'policy_id' => 7,
            'critical' => 'APPROVE',
            'important' => 'manual',
            'reboot_after_install' => 'never',
        ]);
        $this->assertFalse((bool) $created->json('result.isError'), (string) $created->json('result.content.0.text'));

        $updated = $this->callTool($token, 'tactical_update_patch_policy', [
            'client_id' => $fixture['client']->id,
            'reason' => 'Narrow server critical update policy.',
            'policy_id' => 8,
            'critical' => 'ignore',
        ]);
        $this->assertFalse((bool) $updated->json('result.isError'), (string) $updated->json('result.content.0.text'));

        $deleted = $this->callTool($token, 'tactical_delete_patch_policy', [
            'client_id' => $fixture['client']->id,
            'reason' => 'Remove obsolete patch policy.',
            'policy_id' => 8,
            'confirm_policy_name' => 'Servers',
        ]);
        $this->assertFalse((bool) $deleted->json('result.isError'), (string) $deleted->json('result.content.0.text'));

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_delete_patch_policy',
            'result_status' => 'executed',
            'client_id' => $fixture['client']->id,
        ]);
    }

    public function test_patchpolicy_reset_is_held_and_rederives_site_scope_on_approval(): void
    {
        $this->configureTactical();
        $approver = $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $token = $this->token(['tactical_reset_patch_policies', 'tactical_stage_reset_patch_policies']);

        $missingConfirm = $this->callTool($token, 'tactical_reset_patch_policies', [
            'client_id' => $fixture['client']->id,
            'reason' => 'Reset site patch policies.',
            'scope' => 'site',
        ]);
        $this->assertTrue((bool) $missingConfirm->json('result.isError'));
        $this->assertStringContainsString('typed client name', (string) $missingConfirm->json('result.content.0.text'));

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getClients')->twice()->andReturn([
            ['id' => 55, 'name' => 'Acme', 'sites' => [['id' => 77, 'name' => 'Main']]],
        ]);
        $tactical->shouldReceive('resetPatchPolicies')->once()->with(['site' => 77])->andReturn('ok');
        $this->app->instance(TacticalClient::class, $tactical);

        $staged = $this->callTool($token, 'tactical_stage_reset_patch_policies', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'reason' => 'Hold bulk site reset for cockpit approval.',
            'scope' => 'site',
        ]);
        $this->assertFalse((bool) $staged->json('result.isError'), (string) $staged->json('result.content.0.text'));

        $run = TechnicianRun::where('action_type', 'tactical_stage_reset_patch_policies')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertStringContainsString('bulk reset Tactical patch policies', $run->proposed_content);

        $result = app(TechnicianApprovalService::class)->approveStagedTacticalAdminAction($run, $approver->id);

        $this->assertSame('executed', $result->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_stage_reset_patch_policies',
            'result_status' => 'executed',
            'client_id' => $fixture['client']->id,
            'approver_user_id' => $approver->id,
        ]);
    }
}
