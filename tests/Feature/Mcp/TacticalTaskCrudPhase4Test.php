<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Models\Asset;
use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\TacticalScript;
use App\Models\TechnicianActionLog;
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

class TacticalTaskCrudPhase4Test extends TestCase
{
    use RefreshDatabase;

    private const TASK_TOOLS = [
        'tactical_list_tasks',
        'tactical_list_agent_tasks',
        'tactical_list_policy_tasks',
        'tactical_create_agent_task',
        'tactical_create_policy_task',
        'tactical_get_task',
        'tactical_update_task',
        'tactical_delete_task',
        'tactical_run_agent_task',
        'tactical_run_policy_task_on_agent',
        'tactical_run_policy_task_all',
        'tactical_stage_run_policy_task_all',
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
            'subject' => 'Task maintenance',
        ]);
        $ticket->assets()->attach($asset->id, ['is_primary' => true]);

        return compact('client', 'asset', 'tactical', 'ticket');
    }

    /** @return array<int, array<string, mixed>> */
    private function policies(): array
    {
        return [
            ['id' => 7, 'name' => 'Workstations'],
            ['id' => 8, 'name' => 'Servers'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function scripts(): array
    {
        return [
            [
                'id' => 102,
                'name' => 'Deploy App',
                'script_type' => 'userdefined',
                'shell' => 'powershell',
                'args' => ['-Mode'],
                'env_vars' => [],
                'supported_platforms' => ['windows'],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function tasks(): array
    {
        return [
            [
                'id' => 21,
                'name' => 'Daily cleanup',
                'agent' => 55,
                'policy' => null,
                'enabled' => true,
                'task_type' => 'daily',
                'schedule' => 'Daily at 03:00AM',
                'actions' => [['type' => 'cmd', 'shell' => 'powershell', 'command' => 'Write-Host SECRET_TOKEN=hidden', 'timeout' => 30]],
            ],
            [
                'id' => 22,
                'name' => 'Policy cleanup',
                'agent' => null,
                'policy' => 7,
                'enabled' => true,
                'task_type' => 'manual',
                'schedule' => 'Manual',
                'actions' => [['type' => 'script', 'script' => 102, 'name' => 'Deploy App', 'script_args' => ['SECRET_ARG=hidden'], 'timeout' => 90]],
            ],
        ];
    }

    public function test_task_tools_are_sensitive_explicit_grant_only_and_scoped_correctly(): void
    {
        $this->configureTactical();

        $groups = McpToolRegistry::groups();
        $adminNames = array_column($groups['tactical_admin']['tools'], 'name');

        foreach (self::TASK_TOOLS as $tool) {
            $this->assertContains($tool, $adminNames, "{$tool} should be a sensitive Tactical admin tool");
            $this->assertContains($tool, McpToolRegistry::allToolNames(), "{$tool} should be token-grantable");
        }

        $legacyNames = array_column($this->listTools($this->legacyToken()), 'name');
        foreach (self::TASK_TOOLS as $tool) {
            $this->assertNotContains($tool, $legacyNames, "legacy full-surface token must not gain {$tool}");
        }

        $scoped = collect($this->listTools($this->token([
            'tactical_list_tasks',
            'tactical_list_agent_tasks',
            'tactical_create_agent_task',
            'tactical_create_policy_task',
            'tactical_run_policy_task_all',
            'tactical_stage_run_policy_task_all',
        ])))->keyBy('name');

        $this->assertNotContains('client_id', $scoped['tactical_list_tasks']['inputSchema']['required'] ?? []);
        $this->assertContains('client_id', $scoped['tactical_list_agent_tasks']['inputSchema']['required']);
        $this->assertContains('client_id', $scoped['tactical_create_agent_task']['inputSchema']['required']);
        $this->assertNotContains('client_id', $scoped['tactical_create_policy_task']['inputSchema']['required'] ?? []);
        $this->assertArrayHasKey('assigned_check', $scoped['tactical_create_policy_task']['inputSchema']['properties']);
        $this->assertContains('confirm_run_all', $scoped['tactical_run_policy_task_all']['inputSchema']['required']);
        $this->assertContains('ticket_id', $scoped['tactical_stage_run_policy_task_all']['inputSchema']['required']);
        $this->assertStringContainsString('ALL affected agents', $scoped['tactical_run_policy_task_all']['description']);
    }

    public function test_create_task_narrows_serializer_resolves_script_and_agent_scope_and_redacts_audit(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->endpointFixture();
        TacticalScript::create([
            'tactical_script_id' => 102,
            'name' => 'Deploy App',
            'description' => 'Deploy app',
            'shell' => 'powershell',
            'category' => 'Ops',
            'synced_at' => now(),
        ]);
        $token = $this->token(['tactical_create_agent_task', 'tactical_create_policy_task']);

        $rejected = $this->callTool($token, 'tactical_create_agent_task', [
            'client_id' => $fixture['client']->id,
            'agent_id' => 'attacker-agent',
            'reason' => 'Reject caller-supplied upstream agent id.',
            'hostname' => 'PC-01',
            'name' => 'Daily cleanup',
            'task_type' => 'manual',
            'actions' => [['type' => 'cmd', 'shell' => 'powershell', 'command' => 'whoami', 'timeout' => 30]],
        ]);
        $this->assertTrue((bool) $rejected->json('result.isError'));
        $this->assertStringContainsString('upstream Tactical identifiers are not accepted', (string) $rejected->json('result.content.0.text'));

        $badNestedScript = $this->callTool($token, 'tactical_create_agent_task', [
            'client_id' => $fixture['client']->id,
            'reason' => 'Reject nested upstream script id.',
            'hostname' => 'PC-01',
            'name' => 'Daily cleanup',
            'task_type' => 'manual',
            'actions' => [['type' => 'script', 'script' => 102, 'script_args' => [], 'timeout' => 90]],
        ]);
        $this->assertTrue((bool) $badNestedScript->json('result.isError'));
        $this->assertStringContainsString('script action must use script_id or script_name', (string) $badNestedScript->json('result.content.0.text'));

        $badAgentCheckFailure = $this->callTool($token, 'tactical_create_agent_task', [
            'client_id' => $fixture['client']->id,
            'reason' => 'Reject check-failure agent task with caller-supplied upstream check id.',
            'hostname' => 'PC-01',
            'name' => 'Check task',
            'task_type' => 'checkfailure',
            'assigned_check' => 99,
            'actions' => [['type' => 'cmd', 'shell' => 'powershell', 'command' => 'whoami', 'timeout' => 30]],
        ]);
        $this->assertTrue((bool) $badAgentCheckFailure->json('result.isError'));
        $this->assertStringContainsString('upstream Tactical identifiers are not accepted', (string) $badAgentCheckFailure->json('result.content.0.text'));

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getScripts')->twice()->with(true, true)->andReturn($this->scripts());
        $tactical->shouldReceive('getPolicies')->once()->andReturn($this->policies());
        $tactical->shouldReceive('getPolicyChecks')->once()->with(7)->andReturn([
            ['id' => 99, 'name' => 'HelpDesk Buttons detector', 'check_type' => 'script', 'script' => 102],
        ]);
        $tactical->shouldReceive('createTask')->once()->with([
            'agent' => 'agent-1',
            'name' => 'Daily cleanup',
            'enabled' => true,
            'task_type' => 'daily',
            'run_time_date' => '2026-07-04T03:00:00Z',
            'daily_interval' => 1,
            'actions' => [
                ['type' => 'cmd', 'shell' => 'powershell', 'command' => 'Write-Host SECRET_TOKEN=hidden', 'timeout' => 30],
                ['type' => 'script', 'script' => 102, 'name' => 'Deploy App', 'script_args' => ['SECRET_ARG=hidden'], 'timeout' => 90],
            ],
        ])->andReturn('The task has been created. It will show up on the agent on next checkin');
        $tactical->shouldReceive('createTask')->once()->with([
            'policy' => 7,
            'name' => 'Policy self-heal',
            'enabled' => true,
            'task_type' => 'checkfailure',
            'assigned_check' => 99,
            'actions' => [
                ['type' => 'script', 'script' => 102, 'name' => 'Deploy App', 'script_args' => [], 'timeout' => 90],
            ],
        ])->andReturn('The task has been created. It will show up on the agent on next checkin');
        $this->app->instance(TacticalClient::class, $tactical);

        $agentTask = $this->callTool($token, 'tactical_create_agent_task', [
            'client_id' => $fixture['client']->id,
            'reason' => 'Create daily cleanup task.',
            'hostname' => 'PC-01',
            'name' => 'Daily cleanup',
            'enabled' => true,
            'task_type' => 'daily',
            'run_time_date' => '2026-07-04T03:00:00Z',
            'daily_interval' => 1,
            'actions' => [
                ['type' => 'cmd', 'shell' => 'powershell', 'command' => 'Write-Host SECRET_TOKEN=hidden', 'timeout' => 30],
                ['type' => 'script', 'script_id' => 1, 'script_args' => ['SECRET_ARG=hidden'], 'timeout' => 90],
            ],
        ]);
        $this->assertFalse((bool) $agentTask->json('result.isError'), (string) $agentTask->json('result.content.0.text'));

        $policyTask = $this->callTool($token, 'tactical_create_policy_task', [
            'reason' => 'Create check-failure policy remediation task.',
            'policy_id' => 7,
            'name' => 'Policy self-heal',
            'enabled' => true,
            'task_type' => 'checkfailure',
            'assigned_check' => 99,
            'actions' => [
                ['type' => 'script', 'script_name' => 'Deploy App', 'script_args' => [], 'timeout' => 90],
            ],
        ]);
        $this->assertFalse((bool) $policyTask->json('result.isError'), (string) $policyTask->json('result.content.0.text'));

        $auditJson = McpAuditLog::query()
            ->where('tool_name', 'tactical_create_agent_task')
            ->get()
            ->map(fn (McpAuditLog $log): string => json_encode($log->arguments, JSON_THROW_ON_ERROR))
            ->implode("\n");
        $this->assertStringNotContainsString('SECRET_TOKEN', $auditJson);
        $this->assertStringNotContainsString('SECRET_ARG', $auditJson);
        $this->assertStringContainsString('actions_count', $auditJson);

        $policyAuditJson = McpAuditLog::query()
            ->where('tool_name', 'tactical_create_policy_task')
            ->get()
            ->map(fn (McpAuditLog $log): string => json_encode($log->arguments, JSON_THROW_ON_ERROR))
            ->implode("\n");
        $this->assertStringContainsString('"assigned_check":99', $policyAuditJson);
        $this->assertStringContainsString('actions_count', $policyAuditJson);
        $this->assertStringNotContainsString('script_args', $policyAuditJson);

        $summaries = TechnicianActionLog::query()
            ->where('action_type', 'tactical_create_agent_task')
            ->pluck('summary')
            ->implode("\n");
        $this->assertStringNotContainsString('SECRET_TOKEN', $summaries);
        $this->assertStringNotContainsString('SECRET_ARG', $summaries);
    }

    public function test_read_update_and_delete_validate_task_ids_and_preserve_schedule_unless_task_type_is_supplied(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $token = $this->token(['tactical_list_tasks', 'tactical_get_task', 'tactical_update_task', 'tactical_delete_task']);

        $badUpdate = $this->callTool($token, 'tactical_update_task', [
            'reason' => 'Reject schedule fields without task_type.',
            'task_id' => 21,
            'run_time_date' => '2026-07-04T03:00:00Z',
        ]);
        $this->assertTrue((bool) $badUpdate->json('result.isError'));
        $this->assertStringContainsString('include task_type when changing schedule fields', (string) $badUpdate->json('result.content.0.text'));

        $badAlias = $this->callTool($token, 'tactical_update_task', [
            'reason' => 'Reject upstream task alias.',
            'task_pk' => 21,
            'task_id' => 21,
            'enabled' => false,
        ]);
        $this->assertTrue((bool) $badAlias->json('result.isError'));
        $this->assertStringContainsString('upstream Tactical identifiers are not accepted', (string) $badAlias->json('result.content.0.text'));

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getTasks')->times(5)->andReturn($this->tasks());
        $tactical->shouldReceive('getTask')->once()->with(21)->andReturn($this->tasks()[0]);
        $tactical->shouldReceive('updateTask')->once()->with(21, ['enabled' => false])->andReturn('The task was updated');
        $tactical->shouldReceive('deleteTask')->once()->with(21)->andReturn('Daily cleanup will be deleted shortly');
        $this->app->instance(TacticalClient::class, $tactical);

        $listed = $this->callTool($token, 'tactical_list_tasks', ['reason' => 'List tasks.']);
        $this->assertFalse((bool) $listed->json('result.isError'), (string) $listed->json('result.content.0.text'));
        $this->assertStringNotContainsString('SECRET_TOKEN', (string) $listed->json('result.content.0.text'));

        $detail = $this->callTool($token, 'tactical_get_task', [
            'reason' => 'Read task detail.',
            'task_id' => 21,
        ]);
        $this->assertFalse((bool) $detail->json('result.isError'), (string) $detail->json('result.content.0.text'));
        $this->assertStringNotContainsString('SECRET_TOKEN', (string) $detail->json('result.content.0.text'));

        $updated = $this->callTool($token, 'tactical_update_task', [
            'reason' => 'Disable task without touching schedule.',
            'task_id' => 21,
            'enabled' => false,
        ]);
        $this->assertFalse((bool) $updated->json('result.isError'), (string) $updated->json('result.content.0.text'));

        $badConfirm = $this->callTool($token, 'tactical_delete_task', [
            'reason' => 'Delete obsolete task.',
            'task_id' => 21,
            'confirm_task_name' => 'wrong',
        ]);
        $this->assertTrue((bool) $badConfirm->json('result.isError'));
        $this->assertStringContainsString('typed task name', (string) $badConfirm->json('result.content.0.text'));

        $deleted = $this->callTool($token, 'tactical_delete_task', [
            'reason' => 'Delete obsolete task.',
            'task_id' => 21,
            'confirm_task_name' => 'daily cleanup',
        ]);
        $this->assertFalse((bool) $deleted->json('result.isError'), (string) $deleted->json('result.content.0.text'));
    }

    public function test_agent_and_policy_task_runs_validate_scope_and_use_source_confirmed_run_routes(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $token = $this->token(['tactical_run_agent_task', 'tactical_run_policy_task_on_agent', 'tactical_run_policy_task_all']);

        $badAgent = $this->callTool($token, 'tactical_run_policy_task_on_agent', [
            'client_id' => $fixture['client']->id,
            'reason' => 'Reject upstream agent.',
            'policy_id' => 7,
            'task_id' => 22,
            'agent_id' => 'attacker-agent',
        ]);
        $this->assertTrue((bool) $badAgent->json('result.isError'));
        $this->assertStringContainsString('upstream Tactical identifiers are not accepted', (string) $badAgent->json('result.content.0.text'));

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getAgentTasks')->once()->with('agent-1')->andReturn([$this->tasks()[0]]);
        $tactical->shouldReceive('runTask')->once()->with(21)->andReturn('Daily cleanup will now be run.');
        $tactical->shouldReceive('getPolicies')->twice()->andReturn($this->policies());
        $tactical->shouldReceive('getPolicyTasks')->twice()->with(7)->andReturn([$this->tasks()[1]]);
        $tactical->shouldReceive('runTask')->once()->with(22, 'agent-1')->andReturn('Policy cleanup will now be run.');
        $tactical->shouldReceive('runPolicyTask')->once()->with(22)->andReturn('Affected agent tasks will run shortly');
        $this->app->instance(TacticalClient::class, $tactical);

        $agentRun = $this->callTool($token, 'tactical_run_agent_task', [
            'client_id' => $fixture['client']->id,
            'reason' => 'Run visible agent task once.',
            'hostname' => 'PC-01',
            'task_id' => 21,
            'confirm_hostname' => 'PC-01',
            'confirm_task_name' => 'daily cleanup',
        ]);
        $this->assertFalse((bool) $agentRun->json('result.isError'), (string) $agentRun->json('result.content.0.text'));

        $policyOne = $this->callTool($token, 'tactical_run_policy_task_on_agent', [
            'client_id' => $fixture['client']->id,
            'reason' => 'Run policy task on one PSA-derived agent.',
            'hostname' => 'PC-01',
            'policy_id' => 7,
            'task_id' => 22,
            'confirm_hostname' => 'PC-01',
            'confirm_task_name' => 'policy cleanup',
        ]);
        $this->assertFalse((bool) $policyOne->json('result.isError'), (string) $policyOne->json('result.content.0.text'));

        $policyAll = $this->callTool($token, 'tactical_run_policy_task_all', [
            'reason' => 'Run policy task for all affected agents.',
            'policy_id' => 7,
            'task_id' => 22,
            'confirm_policy_name' => 'workstations',
            'confirm_task_name' => 'policy cleanup',
            'confirm_run_all' => 'run policy task for all affected agents',
        ]);
        $this->assertFalse((bool) $policyAll->json('result.isError'), (string) $policyAll->json('result.content.0.text'));
    }

    public function test_broad_policy_task_run_can_be_staged_and_revalidated_on_approval(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $token = $this->token(['tactical_stage_run_policy_task_all']);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getPolicies')->twice()->andReturn($this->policies());
        $tactical->shouldReceive('getPolicyTasks')->twice()->with(7)->andReturn([$this->tasks()[1]]);
        $tactical->shouldReceive('runPolicyTask')->once()->with(22)->andReturn('Affected agent tasks will run shortly');
        $this->app->instance(TacticalClient::class, $tactical);

        $staged = $this->callTool($token, 'tactical_stage_run_policy_task_all', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'reason' => 'Stage broad policy task run.',
            'policy_id' => 7,
            'task_id' => 22,
            'confirm_policy_name' => 'workstations',
            'confirm_task_name' => 'policy cleanup',
            'confirm_run_all' => 'run policy task for all affected agents',
        ]);
        $this->assertFalse((bool) $staged->json('result.isError'), (string) $staged->json('result.content.0.text'));

        $run = TechnicianRun::query()->where('action_type', 'tactical_stage_run_policy_task_all')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertStringNotContainsString('agent-1', json_encode($run->proposed_meta, JSON_THROW_ON_ERROR));

        $result = app(TechnicianApprovalService::class)->approveStagedTacticalAdminAction($run, (int) User::factory()->create()->id);
        $this->assertSame('executed', $result->status);
        $this->assertSame(TechnicianRunState::Done, $run->refresh()->state);

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_run_policy_task_all',
            'result_status' => 'executed',
            'run_id' => $run->id,
        ]);
    }
}
