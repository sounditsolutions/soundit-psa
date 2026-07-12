<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Cipp\CippRestWriteClient;
use App\Support\McpConfig;
use App\Support\McpToolModes;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * Staged Entra DIRECTORY-ROLE removal (bead psa-5qrd): strip a stale admin
 * role from one server-derived user WITHOUT touching licensing. One capability
 * (cipp_remove_directory_role) with a staged twin, following the psa-hqk9
 * delegate pattern — except this capability is STRUCTURALLY HELD-ONLY: direct
 * execution is refused for every grant mode (external-forwarding precedent),
 * so the upstream removal can only ever run through a cockpit approval, which
 * re-resolves the tenant's activated role by role_template_id and re-verifies
 * the role display name and the user's current membership before executing.
 */
class CippWriteDirectoryRoleTest extends TestCase
{
    use RefreshDatabase;

    private const EXCHANGE_ADMIN_TEMPLATE_ID = '29232cdf-9323-42fd-ade2-1d097af3e4de';

    private function configureCipp(): void
    {
        Setting::setValue('cipp_enabled', '1');
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_client_id', 'client-1');
        Setting::setEncrypted('cipp_client_secret', 'secret');
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

    /** @return array{client: Client, person: Person, ticket: Ticket} */
    private function cippFixture(string $prefix = 'acme'): array
    {
        $client = Client::factory()->create([
            'name' => ucfirst($prefix),
            'cipp_tenant_domain' => $prefix.'.onmicrosoft.com',
        ]);

        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Alex',
            'last_name' => ucfirst($prefix),
            'email' => 'alex@'.$prefix.'.example',
            'cipp_user_id' => 'user-'.$prefix,
            'cipp_upn' => 'alex@'.$prefix.'.example',
            'is_active' => true,
        ]);

        $ticket = Ticket::factory()->for($client)->create([
            'contact_id' => $person->id,
            'subject' => 'Offboarding: strip stale admin role',
        ]);

        return compact('client', 'person', 'ticket');
    }

    /** @return array<string, mixed> */
    private function roleArguments(array $fixture, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'role_template_id' => self::EXCHANGE_ADMIN_TEMPLATE_ID,
            'role_name' => 'Exchange Administrator',
            'ticket_id' => $fixture['ticket']->id,
            'confirm_upn' => $fixture['person']->cipp_upn,
            'reason' => 'Offboarding: strip the stale Exchange admin role without touching licensing.',
        ], $overrides);
    }

    /** @return array<int, array<string, mixed>> */
    private function tenantRoles(): array
    {
        return [
            [
                'Id' => 'role-object-1',
                'roleTemplateId' => strtoupper(self::EXCHANGE_ADMIN_TEMPLATE_ID),
                'DisplayName' => 'Exchange Administrator',
                'Description' => 'Can manage all aspects of the Exchange product.',
                'Members' => [
                    ['displayName' => 'Alex Acme', 'userPrincipalName' => 'alex@acme.example', 'id' => 'user-acme'],
                    ['displayName' => 'Other Admin', 'userPrincipalName' => 'other@acme.example', 'id' => 'user-999'],
                ],
            ],
            [
                'Id' => 'role-object-2',
                'roleTemplateId' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
                'DisplayName' => 'Helpdesk Administrator',
                'Description' => 'Can reset passwords for non-administrators.',
                'Members' => [],
            ],
        ];
    }

    public function test_directory_role_tool_is_sensitive_explicit_grant_only_and_schema_is_safe(): void
    {
        $this->configureCipp();

        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('cipp_write', $groups);
        $this->assertTrue($groups['cipp_write']['sensitive']);

        $writeNames = array_column($groups['cipp_write']['tools'], 'name');
        // The staged twin is a retired alias: callable, but the catalog carries
        // only the canonical capability (granted via a :staged mode).
        $this->assertSame('cipp_remove_directory_role', McpToolModes::canonicalForAlias('cipp_stage_remove_directory_role'));
        $this->assertNotContains('cipp_stage_remove_directory_role', $writeNames);
        $this->assertContains('cipp_remove_directory_role', $writeNames);
        $this->assertContains('cipp_remove_directory_role', McpToolRegistry::allToolNames());

        // A legacy full-surface token must not silently gain the new sensitive tool.
        $legacyNames = array_column($this->listTools($this->legacyToken()), 'name');
        $this->assertNotContains('cipp_remove_directory_role', $legacyNames);
        $this->assertNotContains('cipp_stage_remove_directory_role', $legacyNames);

        $scoped = collect($this->listTools($this->token(['cipp_remove_directory_role'])))->keyBy('name');
        $this->assertFalse($scoped->has('cipp_stage_remove_directory_role'));
        $tool = $scoped['cipp_remove_directory_role'];

        $this->assertContains('client_id', $tool['inputSchema']['required']);
        foreach (['person_id', 'role_template_id', 'role_name', 'confirm_upn', 'reason'] as $req) {
            $this->assertContains($req, $tool['inputSchema']['required']);
        }
        // No upstream CIPP identities are ever accepted from the caller.
        $this->assertArrayNotHasKey('tenantFilter', $tool['inputSchema']['properties']);
        $this->assertArrayNotHasKey('RoleId', $tool['inputSchema']['properties']);
        $this->assertArrayNotHasKey('Users', $tool['inputSchema']['properties']);
        $this->assertArrayNotHasKey('ID', $tool['inputSchema']['properties']);

        // Unified surface: one tool, with the staged parameter folded in, and the
        // held-only contract stated on the advertised description.
        $this->assertArrayHasKey('staged', $tool['inputSchema']['properties']);
        $this->assertStringContainsStringIgnoringCase('held-only', $tool['description']);

        // A staged-only token sees the staged variant's schema: ticket_id required.
        $stagedScoped = collect($this->listTools($this->token(['cipp_stage_remove_directory_role'])))->keyBy('name');
        $this->assertContains('ticket_id', $stagedScoped['cipp_remove_directory_role']['inputSchema']['required']);
    }

    public function test_direct_execution_is_structurally_refused_even_with_immediate_grant(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        // Bare grant = legacy immediate mode: the mode gate would allow
        // staged=false, so the refusal must come from the executor itself.
        $token = $this->token(['cipp_remove_directory_role']);

        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('removeDirectoryRoleMember');
        $blocked->shouldNotReceive('listDirectoryRoles');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        $response = $this->callTool($token, 'cipp_remove_directory_role', $this->roleArguments($fixture));

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsStringIgnoringCase('held-only', (string) $response->json('result.content.0.text'));
        $this->assertStringContainsString('staged=true', (string) $response->json('result.content.0.text'));

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'cipp_remove_directory_role',
            'result_status' => 'rejected',
            'client_id' => $fixture['client']->id,
        ]);
        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_staged_grant_auto_downgrades_immediate_calls_to_a_staged_proposal(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        // Alias grant = staged-only mode; staged=false must downgrade, not fail.
        $token = $this->token(['cipp_stage_remove_directory_role']);

        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('removeDirectoryRoleMember');
        $blocked->shouldNotReceive('listDirectoryRoles');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        $response = $this->callTool($token, 'cipp_remove_directory_role', $this->roleArguments($fixture, ['staged' => false]));

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame('cipp_stage_remove_directory_role', $run->action_type);
    }

    public function test_staged_removal_holds_for_approval_then_resolves_and_executes(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_remove_directory_role']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('removeDirectoryRoleMember');
        $client->shouldNotReceive('listDirectoryRoles');
        $this->app->instance(CippRestWriteClient::class, $client);

        // Stage with an UPPERCASED template GUID to prove canonicalization.
        $response = $this->callTool($token, 'cipp_stage_remove_directory_role', $this->roleArguments($fixture, [
            'role_template_id' => strtoupper(self::EXCHANGE_ADMIN_TEMPLATE_ID),
        ]));

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame('cipp_stage_remove_directory_role', $run->action_type);

        // The stored proposal keeps only safe local scalars: the template GUID is
        // canonicalized to lowercase, the typed role name and PSA person id ride
        // along, and no upstream role OBJECT id exists yet (resolved at approval).
        $stored = json_encode($run->proposed_meta);
        $this->assertStringContainsString(self::EXCHANGE_ADMIN_TEMPLATE_ID, $stored);
        $this->assertStringNotContainsString(strtoupper(self::EXCHANGE_ADMIN_TEMPLATE_ID), $stored);
        $this->assertStringContainsString('Exchange Administrator', $stored);
        $this->assertStringNotContainsString('role-object-1', $stored);

        // The operator-facing proposal names the target by UPN and the role by
        // name so the human gate can verify who loses which admin role.
        $this->assertStringContainsString('alex@acme.example', $run->proposed_content);
        $this->assertStringContainsString('Exchange Administrator', $run->proposed_content);

        // Approval re-resolves the tenant's activated role from the template id
        // (case-insensitively) and executes against the RESOLVED object id with
        // the server-derived user identity.
        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldReceive('listDirectoryRoles')
            ->once()
            ->with('acme.onmicrosoft.com')
            ->andReturn($this->tenantRoles());
        $approveClient->shouldReceive('removeDirectoryRoleMember')
            ->once()
            ->with('acme.onmicrosoft.com', 'role-object-1', 'Exchange Administrator', 'user-acme', 'alex@acme.example')
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $this->actingAs($actor)
            ->post(route('cockpit.approve', $run))
            ->assertRedirect(route('cockpit.index'));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'cipp_stage_remove_directory_role',
            'result_status' => 'executed',
            'ticket_id' => $fixture['ticket']->id,
            'client_id' => $fixture['client']->id,
            'run_id' => $run->id,
            'approver_user_id' => $actor->id,
        ]);

        // The audit summary references the PSA person id, never the tenant domain.
        $summary = TechnicianActionLog::where('action_type', 'cipp_stage_remove_directory_role')
            ->where('result_status', 'executed')->latest('id')->firstOrFail()->summary;
        $this->assertStringContainsString('person #'.$fixture['person']->id, $summary);
        $this->assertStringNotContainsString('acme.onmicrosoft.com', $summary);
    }

    public function test_approval_declines_when_role_missing_name_mismatched_or_membership_absent(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();

        $scenarios = [
            'role_not_active' => [
                'roles' => [$this->tenantRoles()[1]], // Exchange Administrator absent entirely
                'role_name' => 'Exchange Administrator',
            ],
            'display_name_mismatch' => [
                'roles' => [array_merge($this->tenantRoles()[0], ['DisplayName' => 'Intune Administrator'])],
                'role_name' => 'Exchange Administrator',
            ],
            'not_a_member' => [
                'roles' => [array_merge($this->tenantRoles()[0], ['Members' => [
                    ['displayName' => 'Other Admin', 'userPrincipalName' => 'other@acme.example', 'id' => 'user-999'],
                ]])],
                'role_name' => 'Exchange Administrator',
            ],
        ];

        $prefixes = ['role_not_active' => 'alpha', 'display_name_mismatch' => 'bravo', 'not_a_member' => 'carol'];
        foreach ($scenarios as $label => $scenario) {
            $fixture = $this->cippFixture($prefixes[$label]);
            $token = $this->token(['cipp_stage_remove_directory_role']);

            $stageClient = Mockery::mock(CippRestWriteClient::class);
            $stageClient->shouldNotReceive('removeDirectoryRoleMember');
            $stageClient->shouldNotReceive('listDirectoryRoles');
            $this->app->instance(CippRestWriteClient::class, $stageClient);

            $response = $this->callTool($token, 'cipp_stage_remove_directory_role', $this->roleArguments($fixture, [
                'role_name' => $scenario['role_name'],
            ]));
            $this->assertFalse((bool) $response->json('result.isError'), $label.': '.(string) $response->json('result.content.0.text'));
            $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);

            $approveClient = Mockery::mock(CippRestWriteClient::class);
            $approveClient->shouldReceive('listDirectoryRoles')
                ->once()
                ->with($fixture['client']->cipp_tenant_domain)
                ->andReturn($scenario['roles']);
            $approveClient->shouldNotReceive('removeDirectoryRoleMember');
            $this->app->instance(CippRestWriteClient::class, $approveClient);

            $this->actingAs($actor)->post(route('cockpit.approve', $run));

            // Gate declined: the run returns to the queue and an error row is audited;
            // the upstream removal was never called.
            $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state, $label);
            $this->assertDatabaseHas('technician_action_logs', [
                'action_type' => 'cipp_stage_remove_directory_role',
                'result_status' => 'error',
                'run_id' => $run->id,
            ]);
        }
    }

    public function test_rejects_bad_inputs_and_caller_supplied_upstream_identifiers(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_remove_directory_role']);

        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('removeDirectoryRoleMember');
        $blocked->shouldNotReceive('listDirectoryRoles');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        // role_template_id must be a well-formed GUID.
        $badGuid = $this->callTool($token, 'cipp_stage_remove_directory_role', $this->roleArguments($fixture, [
            'role_template_id' => 'Global Administrator',
        ]));
        $this->assertTrue((bool) $badGuid->json('result.isError'));
        $this->assertStringContainsString('role_template_id', (string) $badGuid->json('result.content.0.text'));

        // role_name typed confirmation is required.
        $missingName = $this->callTool($token, 'cipp_stage_remove_directory_role',
            collect($this->roleArguments($fixture))->except('role_name')->all());
        $this->assertTrue((bool) $missingName->json('result.isError'));
        $this->assertStringContainsString('role_name', (string) $missingName->json('result.content.0.text'));

        // The upstream endpoint's own body keys are never accepted from the caller.
        foreach (['RoleId' => 'role-object-1', 'Users' => [['value' => 'attacker']], 'RoleName' => 'Global Administrator'] as $key => $value) {
            $rejected = $this->callTool($token, 'cipp_stage_remove_directory_role', $this->roleArguments($fixture, [$key => $value]));
            $this->assertTrue((bool) $rejected->json('result.isError'), $key);
            $this->assertStringContainsString('upstream CIPP identifiers are not accepted', (string) $rejected->json('result.content.0.text'));
        }

        // confirm_upn must match the resolved target user.
        $mismatch = $this->callTool($token, 'cipp_stage_remove_directory_role', $this->roleArguments($fixture, [
            'confirm_upn' => 'someone-else@acme.example',
        ]));
        $this->assertTrue((bool) $mismatch->json('result.isError'));
        $this->assertStringContainsString('confirm_upn does not match', (string) $mismatch->json('result.content.0.text'));

        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_staged_removal_is_idempotent_while_awaiting_approval(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_remove_directory_role']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('removeDirectoryRoleMember');
        $client->shouldNotReceive('listDirectoryRoles');
        $this->app->instance(CippRestWriteClient::class, $client);

        $first = $this->callTool($token, 'cipp_stage_remove_directory_role', $this->roleArguments($fixture));
        $this->assertFalse((bool) $first->json('result.isError'), (string) $first->json('result.content.0.text'));
        $runId = $this->decodedResult($first)['run_id'];

        $second = $this->callTool($token, 'cipp_stage_remove_directory_role', $this->roleArguments($fixture));
        $this->assertFalse((bool) $second->json('result.isError'), (string) $second->json('result.content.0.text'));
        $decoded = $this->decodedResult($second);
        $this->assertTrue((bool) ($decoded['idempotent'] ?? false));
        $this->assertSame($runId, $decoded['run_id']);
        $this->assertSame(1, TechnicianRun::count());
    }
}
