<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\TechnicianActionLog;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class TacticalAutomationPolicyPhase4Test extends TestCase
{
    use RefreshDatabase;

    private const POLICY_TOOLS = [
        'tactical_list_automation_policies',
        'tactical_create_automation_policy',
        'tactical_get_automation_policy',
        'tactical_update_automation_policy',
        'tactical_delete_automation_policy',
        'tactical_get_automation_policy_related',
        'tactical_assign_automation_policy',
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
            'subject' => 'Policy assignment',
        ]);
        $ticket->assets()->attach($asset->id, ['is_primary' => true]);

        return compact('client', 'asset', 'tactical', 'ticket');
    }

    /** @return array<int, array<string, mixed>> */
    private function policies(): array
    {
        return [
            [
                'id' => 7,
                'name' => 'Workstations',
                'desc' => 'Default workstations SECRET_TOKEN=hidden',
                'active' => true,
                'enforced' => false,
                'alert_template' => 3,
                'excluded_clients' => [],
                'excluded_sites' => [],
                'excluded_agents' => [],
                'agents_count' => 12,
            ],
            [
                'id' => 8,
                'name' => 'Servers',
                'desc' => 'Server baseline',
                'active' => true,
                'enforced' => true,
                'alert_template' => null,
                'excluded_clients' => [],
                'excluded_sites' => [],
                'excluded_agents' => [],
                'agents_count' => 3,
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function tacticalClients(): array
    {
        return [
            [
                'id' => 55,
                'name' => 'Acme',
                'workstation_policy' => null,
                'server_policy' => null,
                'block_policy_inheritance' => false,
                'sites' => [
                    [
                        'id' => 77,
                        'name' => 'Main',
                        'workstation_policy' => null,
                        'server_policy' => null,
                        'block_policy_inheritance' => false,
                    ],
                ],
            ],
        ];
    }

    public function test_automation_policy_tools_are_sensitive_and_explicit_grant_only(): void
    {
        $this->configureTactical();

        $groups = McpToolRegistry::groups();
        $adminNames = array_column($groups['tactical_admin']['tools'], 'name');

        foreach (self::POLICY_TOOLS as $tool) {
            $this->assertContains($tool, $adminNames, "{$tool} should be a sensitive Tactical admin tool");
            $this->assertContains($tool, McpToolRegistry::allToolNames(), "{$tool} should be token-grantable");
        }

        $legacyNames = array_column($this->listTools($this->legacyToken()), 'name');
        foreach (self::POLICY_TOOLS as $tool) {
            $this->assertNotContains($tool, $legacyNames, "legacy full-surface token must not gain {$tool}");
        }

        $scoped = collect($this->listTools($this->token([
            'tactical_list_automation_policies',
            'tactical_delete_automation_policy',
            'tactical_assign_automation_policy',
        ])))->keyBy('name');

        $this->assertNotContains('client_id', $scoped['tactical_list_automation_policies']['inputSchema']['required'] ?? []);
        $this->assertContains('confirm_policy_name', $scoped['tactical_delete_automation_policy']['inputSchema']['required']);
        $this->assertContains('client_id', $scoped['tactical_assign_automation_policy']['inputSchema']['required']);
        $this->assertStringContainsString('NO assign endpoint exists', $scoped['tactical_assign_automation_policy']['description']);
    }

    public function test_policy_crud_resolves_ids_narrows_fields_and_uses_typed_delete_confirm(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $token = $this->token([
            'tactical_list_automation_policies',
            'tactical_create_automation_policy',
            'tactical_get_automation_policy',
            'tactical_update_automation_policy',
            'tactical_delete_automation_policy',
            'tactical_get_automation_policy_related',
        ]);

        $rejected = $this->callTool($token, 'tactical_update_automation_policy', [
            'policy' => 7,
            'policy_id' => 7,
            'reason' => 'Reject caller-supplied upstream alias.',
            'active' => true,
        ]);
        $this->assertTrue((bool) $rejected->json('result.isError'));
        $this->assertStringContainsString('upstream Tactical identifiers are not accepted', (string) $rejected->json('result.content.0.text'));

        $badField = $this->callTool($token, 'tactical_create_automation_policy', [
            'name' => 'Workstations',
            'reason' => 'Reject broad serializer field.',
            'clients' => [55],
        ]);
        $this->assertTrue((bool) $badField->json('result.isError'));
        $this->assertStringContainsString('Unsupported automation policy fields', (string) $badField->json('result.content.0.text'));

        $badUpdateField = $this->callTool($token, 'tactical_update_automation_policy', [
            'policy_id' => 7,
            'reason' => 'Reject create-only copy field on update.',
            'copy_id' => 8,
        ]);
        $this->assertTrue((bool) $badUpdateField->json('result.isError'));
        $this->assertStringContainsString('Unsupported automation policy fields: copy_id', (string) $badUpdateField->json('result.content.0.text'));

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getPolicies')->times(7)->andReturn($this->policies());
        $tactical->shouldReceive('createAutomationPolicy')->once()->with([
            'name' => 'Cloned Workstations',
            'desc' => 'Clone from workstation baseline',
            'active' => true,
            'enforced' => false,
            'copyId' => 7,
        ])->andReturn('ok');
        $tactical->shouldReceive('getAutomationPolicy')->once()->with(7)->andReturn([
            'id' => 7,
            'name' => 'Workstations',
            'desc' => 'Default workstations SECRET_TOKEN=hidden',
            'active' => true,
            'enforced' => false,
        ]);
        $tactical->shouldReceive('updateAutomationPolicy')->once()->with(7, [
            'desc' => 'Updated description',
            'active' => false,
        ])->andReturn('ok');
        $tactical->shouldReceive('getAutomationPolicyRelated')->once()->with(7)->andReturn([
            'pk' => 7,
            'name' => 'Workstations',
            'agents' => [['agent_id' => 'agent-1', 'hostname' => 'PC-01']],
        ]);
        $tactical->shouldReceive('deleteAutomationPolicy')->once()->with(8)->andReturn('ok');
        $this->app->instance(TacticalClient::class, $tactical);

        $listed = $this->callTool($token, 'tactical_list_automation_policies', [
            'reason' => 'Review policy catalog.',
        ]);
        $this->assertFalse((bool) $listed->json('result.isError'), (string) $listed->json('result.content.0.text'));
        $this->assertStringNotContainsString('SECRET_TOKEN', (string) $listed->json('result.content.0.text'));

        $created = $this->callTool($token, 'tactical_create_automation_policy', [
            'reason' => 'Create cloned workstation policy.',
            'name' => 'Cloned Workstations',
            'desc' => 'Clone from workstation baseline',
            'active' => true,
            'enforced' => false,
            'copy_id' => 7,
        ]);
        $this->assertFalse((bool) $created->json('result.isError'), (string) $created->json('result.content.0.text'));

        $detail = $this->callTool($token, 'tactical_get_automation_policy', [
            'reason' => 'Read policy details.',
            'policy_id' => 7,
        ]);
        $this->assertFalse((bool) $detail->json('result.isError'), (string) $detail->json('result.content.0.text'));
        $this->assertStringNotContainsString('SECRET_TOKEN', (string) $detail->json('result.content.0.text'));

        $updated = $this->callTool($token, 'tactical_update_automation_policy', [
            'reason' => 'Disable old workstation policy.',
            'policy_id' => 7,
            'desc' => 'Updated description',
            'active' => false,
        ]);
        $this->assertFalse((bool) $updated->json('result.isError'), (string) $updated->json('result.content.0.text'));

        $related = $this->callTool($token, 'tactical_get_automation_policy_related', [
            'reason' => 'Read affected agents.',
            'policy_id' => 7,
        ]);
        $this->assertFalse((bool) $related->json('result.isError'), (string) $related->json('result.content.0.text'));

        $badConfirm = $this->callTool($token, 'tactical_delete_automation_policy', [
            'reason' => 'Delete obsolete policy.',
            'policy_id' => 8,
            'confirm_policy_name' => 'wrong name',
        ]);
        $this->assertTrue((bool) $badConfirm->json('result.isError'));
        $this->assertStringContainsString('typed policy name', (string) $badConfirm->json('result.content.0.text'));

        $deleted = $this->callTool($token, 'tactical_delete_automation_policy', [
            'reason' => 'Delete obsolete policy.',
            'policy_id' => 8,
            'confirm_policy_name' => 'servers',
        ]);
        $this->assertFalse((bool) $deleted->json('result.isError'), (string) $deleted->json('result.content.0.text'));

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_delete_automation_policy',
            'result_status' => 'executed',
        ]);
        $summaries = TechnicianActionLog::query()
            ->whereIn('action_type', ['tactical_list_automation_policies', 'tactical_get_automation_policy'])
            ->pluck('summary')
            ->implode("\n");
        $this->assertStringNotContainsString('SECRET_TOKEN', $summaries);
    }

    public function test_policy_assignment_uses_client_site_or_agent_put_with_server_derived_scope(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->endpointFixture();
        $token = $this->token(['tactical_assign_automation_policy']);

        $rejected = $this->callTool($token, 'tactical_assign_automation_policy', [
            'client_id' => $fixture['client']->id,
            'reason' => 'Reject upstream ids.',
            'target_type' => 'agent',
            'agent_id' => 'attacker-agent',
            'policy_id' => 7,
        ]);
        $this->assertTrue((bool) $rejected->json('result.isError'));
        $this->assertStringContainsString('upstream Tactical identifiers are not accepted', (string) $rejected->json('result.content.0.text'));

        $badField = $this->callTool($token, 'tactical_assign_automation_policy', [
            'client_id' => $fixture['client']->id,
            'reason' => 'Reject ignored fields.',
            'target_type' => 'client',
            'policy_kind' => 'workstation',
            'policy_id' => 7,
            'name' => 'ignored',
        ]);
        $this->assertTrue((bool) $badField->json('result.isError'));
        $this->assertStringContainsString('Unsupported automation policy assignment fields: name', (string) $badField->json('result.content.0.text'));

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getPolicies')->times(3)->andReturn($this->policies());
        $tactical->shouldReceive('getClients')->twice()->andReturn($this->tacticalClients());
        $tactical->shouldReceive('updateClientPolicies')->once()->with(55, [
            'workstation_policy' => 7,
            'block_policy_inheritance' => false,
        ])->andReturn('{client} was updated');
        $tactical->shouldReceive('updateSitePolicies')->once()->with(77, [
            'server_policy' => 8,
            'block_policy_inheritance' => true,
        ])->andReturn('Site was edited');
        $tactical->shouldReceive('updateAgentPolicy')->once()->with('agent-1', [
            'policy' => 7,
            'block_policy_inheritance' => false,
        ])->andReturn('The agent was updated successfully');
        $this->app->instance(TacticalClient::class, $tactical);

        $clientAssign = $this->callTool($token, 'tactical_assign_automation_policy', [
            'client_id' => $fixture['client']->id,
            'reason' => 'Assign workstation default policy to Tactical client.',
            'target_type' => 'client',
            'policy_kind' => 'workstation',
            'policy_id' => 7,
            'block_policy_inheritance' => false,
        ]);
        $this->assertFalse((bool) $clientAssign->json('result.isError'), (string) $clientAssign->json('result.content.0.text'));

        $siteAssign = $this->callTool($token, 'tactical_assign_automation_policy', [
            'client_id' => $fixture['client']->id,
            'reason' => 'Assign server policy to mapped Tactical site.',
            'target_type' => 'site',
            'policy_kind' => 'server',
            'policy_id' => 8,
            'block_policy_inheritance' => true,
        ]);
        $this->assertFalse((bool) $siteAssign->json('result.isError'), (string) $siteAssign->json('result.content.0.text'));

        $agentAssign = $this->callTool($token, 'tactical_assign_automation_policy', [
            'client_id' => $fixture['client']->id,
            'reason' => 'Assign direct policy to one server-derived agent.',
            'target_type' => 'agent',
            'hostname' => 'PC-01',
            'policy_id' => 7,
            'block_policy_inheritance' => false,
        ]);
        $this->assertFalse((bool) $agentAssign->json('result.isError'), (string) $agentAssign->json('result.content.0.text'));

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_assign_automation_policy',
            'result_status' => 'executed',
            'client_id' => $fixture['client']->id,
        ]);
    }
}
