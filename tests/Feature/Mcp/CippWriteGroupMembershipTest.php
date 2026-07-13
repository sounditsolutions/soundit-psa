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
 * Staged group-membership add/remove (bead psa-pbvy.3): one capability
 * (cipp_set_group_membership) with a staged twin, adding one server-derived
 * CIPP user to — or removing them from — one M365 group. The group is
 * caller-identified by GUID but SERVER-VERIFIED against the resolved
 * tenant's live CIPP group listing (quarantine-release precedent): existence,
 * display-name confirmation, and the group TYPE all come from the verified
 * row, and dynamic-membership / on-prem-synced / unrecognized-type groups
 * are refused before anything is staged or executed.
 *
 * Inactive-recipient gate (bead psa-pgnj, delegate/OneDrive precedent): an
 * ADD names the user as an access recipient (the group's data, resources,
 * and mail), so an inactive user is refused at staging AND declines fresh on
 * the approval replay. A REMOVE is revocation — routine offboarding cleanup
 * for already-deactivated users — and stays on the loose resolver.
 */
class CippWriteGroupMembershipTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP_ID = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';

    private const GROUP_ID_2 = '9b2a4c31-77aa-42dd-8be2-11d2aa8bc102';

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
    private function cippFixture(): array
    {
        $client = Client::factory()->create([
            'name' => 'Acme',
            'cipp_tenant_domain' => 'acme.onmicrosoft.com',
        ]);

        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Alex',
            'last_name' => 'Acme',
            'email' => 'alex@acme.example',
            'cipp_user_id' => 'user-123',
            'cipp_upn' => 'alex@acme.example',
            'is_active' => true,
        ]);

        $ticket = Ticket::factory()->for($client)->create([
            'contact_id' => $person->id,
            'subject' => 'Group membership change',
        ]);

        return compact('client', 'person', 'ticket');
    }

    /**
     * One row of the tenant group listing exactly as CIPP emits it. Shape
     * copied from CIPP-API Invoke-ListGroups.ps1 (list view): the Graph group
     * $select fields pass through camelCase, plus the projection's computed
     * keys (groupType, calculatedGroupType, dynamicGroupBool, teamsEnabled,
     * primDomain, SID).
     *
     * @return array<string, mixed>
     */
    private function groupRow(array $overrides = []): array
    {
        return array_merge([
            'id' => self::GROUP_ID,
            'createdDateTime' => '2024-02-06T18:04:05Z',
            'displayName' => 'Sales Team',
            'description' => 'Sales staff collaboration group',
            'mail' => 'sales@acme.example',
            'mailEnabled' => true,
            'mailNickname' => 'sales',
            'resourceProvisioningOptions' => [],
            'securityEnabled' => false,
            'visibility' => 'Private',
            'organizationId' => 'c9d6f9a0-0000-4000-8000-000000000000',
            'onPremisesSamAccountName' => null,
            'membershipRule' => null,
            'groupTypes' => ['Unified'],
            'onPremisesSyncEnabled' => null,
            'assignedLicenses' => [],
            'userPrincipalName' => null,
            'licenseProcessingState' => null,
            'primDomain' => 'acme.example',
            'membersCsv' => '',
            'teamsEnabled' => false,
            'groupType' => 'Microsoft 365',
            'calculatedGroupType' => 'm365',
            'dynamicGroupBool' => false,
            'SID' => 'S-1-12-1-1111111111-1111111111-1111111111-1111111111',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function securityGroupRow(array $overrides = []): array
    {
        return $this->groupRow(array_merge([
            'id' => self::GROUP_ID_2,
            'displayName' => 'VPN Users',
            'description' => 'Grants VPN access',
            'mail' => null,
            'mailEnabled' => false,
            'mailNickname' => null,
            'securityEnabled' => true,
            'groupTypes' => [],
            'primDomain' => '',
            'groupType' => 'Security',
            'calculatedGroupType' => 'generic',
        ], $overrides));
    }

    public function test_group_membership_tool_is_sensitive_explicit_grant_only_and_schema_is_safe(): void
    {
        $this->configureCipp();

        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('cipp_write', $groups);
        $this->assertTrue($groups['cipp_write']['sensitive']);

        $writeNames = array_column($groups['cipp_write']['tools'], 'name');
        // The staged twin is a retired alias: callable, but the catalog carries
        // only the canonical capability (granted via a :staged mode).
        $this->assertSame('cipp_set_group_membership', McpToolModes::canonicalForAlias('cipp_stage_set_group_membership'));
        $this->assertNotContains('cipp_stage_set_group_membership', $writeNames);
        $this->assertContains('cipp_set_group_membership', $writeNames);
        $this->assertContains('cipp_set_group_membership', McpToolRegistry::allToolNames());

        // A legacy full-surface token must not silently gain the new sensitive tool.
        $legacyNames = array_column($this->listTools($this->legacyToken()), 'name');
        $this->assertNotContains('cipp_set_group_membership', $legacyNames);
        $this->assertNotContains('cipp_stage_set_group_membership', $legacyNames);

        $scoped = collect($this->listTools($this->token(['cipp_set_group_membership'])))->keyBy('name');
        $this->assertFalse($scoped->has('cipp_stage_set_group_membership'));
        $tool = $scoped['cipp_set_group_membership'];

        $this->assertContains('client_id', $tool['inputSchema']['required']);
        foreach (['person_id', 'group_id', 'operation', 'confirm_group_name', 'confirm_upn', 'reason'] as $req) {
            $this->assertContains($req, $tool['inputSchema']['required']);
        }
        // No upstream CIPP identities or raw EditGroup body keys are ever accepted from the caller.
        $this->assertArrayNotHasKey('tenantFilter', $tool['inputSchema']['properties']);
        $this->assertArrayNotHasKey('groupId', $tool['inputSchema']['properties']);
        $this->assertArrayNotHasKey('groupType', $tool['inputSchema']['properties']);
        $this->assertArrayNotHasKey('AddMember', $tool['inputSchema']['properties']);
        $this->assertArrayNotHasKey('RemoveMember', $tool['inputSchema']['properties']);

        $this->assertSame(['add', 'remove'], $tool['inputSchema']['properties']['operation']['enum']);
        // Unified surface: one tool, with the staged parameter folded in.
        $this->assertArrayHasKey('staged', $tool['inputSchema']['properties']);
        $this->assertStringContainsString('Supports staged=true', $tool['description']);
    }

    public function test_direct_add_and_remove_use_server_derived_scope_and_live_group_verification(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_set_group_membership']);

        // Caller-supplied upstream identifiers (raw EditGroup body keys) are
        // rejected before any upstream call — including the verification read.
        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('listGroups');
        $blocked->shouldNotReceive('setGroupMembership');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        $rejected = $this->callTool($token, 'cipp_set_group_membership', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'group_id' => self::GROUP_ID,
            'operation' => 'add',
            'AddMember' => [['value' => 'attacker-id']],
            'confirm_group_name' => 'Sales Team',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Reject caller-supplied upstream group body keys.',
        ]);
        $this->assertTrue((bool) $rejected->json('result.isError'));
        $this->assertStringContainsString('upstream CIPP identifiers are not accepted', (string) $rejected->json('result.content.0.text'));

        // confirm_upn must match the resolved target user.
        $mismatch = $this->callTool($token, 'cipp_set_group_membership', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'group_id' => self::GROUP_ID,
            'operation' => 'add',
            'confirm_group_name' => 'Sales Team',
            'confirm_upn' => 'someone-else@acme.example',
            'reason' => 'Wrong confirmation.',
        ]);
        $this->assertTrue((bool) $mismatch->json('result.isError'));
        $this->assertStringContainsString('confirm_upn does not match', (string) $mismatch->json('result.content.0.text'));

        // confirm_group_name is checked against the SERVER-verified row's
        // displayName — a wrong typed name cancels after the verification read.
        $nameClient = Mockery::mock(CippRestWriteClient::class);
        $nameClient->shouldReceive('listGroups')->once()
            ->with('acme.onmicrosoft.com')
            ->andReturn([$this->groupRow()]);
        $nameClient->shouldNotReceive('setGroupMembership');
        $this->app->instance(CippRestWriteClient::class, $nameClient);

        $badName = $this->callTool($token, 'cipp_set_group_membership', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'group_id' => self::GROUP_ID,
            'operation' => 'add',
            'confirm_group_name' => 'Finance Team',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Typed group name does not match the verified group.',
        ]);
        $this->assertTrue((bool) $badName->json('result.isError'));
        $this->assertStringContainsString('confirm_group_name does not match', (string) $badName->json('result.content.0.text'));

        // Happy path ADD: the group is verified against the live listing and
        // the upstream call carries the VERIFIED name/type, never caller input.
        $addClient = Mockery::mock(CippRestWriteClient::class);
        $addClient->shouldReceive('listGroups')->once()
            ->with('acme.onmicrosoft.com')
            ->andReturn([$this->groupRow(), $this->securityGroupRow()]);
        $addClient->shouldReceive('setGroupMembership')->once()
            ->with('acme.onmicrosoft.com', self::GROUP_ID, 'Sales Team', 'Microsoft 365', 'user-123', 'alex@acme.example', 'add')
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $addClient);

        $added = $this->callTool($token, 'cipp_set_group_membership', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'group_id' => self::GROUP_ID,
            'operation' => 'add',
            'ticket_id' => $fixture['ticket']->id,
            'confirm_group_name' => 'Sales Team',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Add the new hire to the sales collaboration group.',
        ]);
        $added->assertOk();
        $this->assertFalse((bool) $added->json('result.isError'), (string) $added->json('result.content.0.text'));
        $this->assertSame('CIPP group membership change executed.', $this->decodedResult($added)['message']);

        // Happy path REMOVE on a security group (distinct target: the per-target
        // cooldown keys on person+group+operation, so this is not blocked).
        $removeClient = Mockery::mock(CippRestWriteClient::class);
        $removeClient->shouldReceive('listGroups')->once()
            ->with('acme.onmicrosoft.com')
            ->andReturn([$this->groupRow(), $this->securityGroupRow()]);
        $removeClient->shouldReceive('setGroupMembership')->once()
            ->with('acme.onmicrosoft.com', self::GROUP_ID_2, 'VPN Users', 'Security', 'user-123', 'alex@acme.example', 'remove')
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $removeClient);

        $removed = $this->callTool($token, 'cipp_set_group_membership', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'group_id' => self::GROUP_ID_2,
            'operation' => 'remove',
            'confirm_group_name' => 'VPN Users',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Revoke VPN access after role change.',
        ]);
        $removed->assertOk();
        $this->assertFalse((bool) $removed->json('result.isError'), (string) $removed->json('result.content.0.text'));

        $this->assertSame(2, TechnicianActionLog::where('action_type', 'cipp_set_group_membership')
            ->where('result_status', 'executed')->count());

        // The audit names the PSA person and the verified group (a group change
        // is unreviewable without the group), never the upstream tenant or UPN.
        $summary = TechnicianActionLog::where('action_type', 'cipp_set_group_membership')
            ->where('result_status', 'executed')->latest('id')->firstOrFail()->summary;
        $this->assertStringContainsString('person #'.$fixture['person']->id, $summary);
        $this->assertStringContainsString('VPN Users', $summary);
        $this->assertStringNotContainsString('acme.onmicrosoft.com', $summary);
        $this->assertStringNotContainsString('alex@acme.example', $summary);
    }

    public function test_group_verification_refuses_missing_dynamic_onprem_and_unknown_type_groups(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_set_group_membership']);

        $cases = [
            // [listing rows, expected message fragment]
            'missing' => [
                [$this->securityGroupRow()],
                'not found in this client tenant',
            ],
            'dynamic' => [
                [$this->groupRow(['groupTypes' => ['Unified', 'DynamicMembership'], 'dynamicGroupBool' => true, 'membershipRule' => 'user.department -eq "Sales"'])],
                'dynamic membership',
            ],
            'onprem' => [
                [$this->groupRow(['onPremisesSyncEnabled' => true, 'onPremisesSamAccountName' => 'sales-team'])],
                'on-premises Active Directory',
            ],
            'unknown-type' => [
                [$this->groupRow(['groupTypes' => ['Custom'], 'securityEnabled' => false, 'mailEnabled' => true, 'groupType' => null, 'calculatedGroupType' => null, 'dynamicGroupBool' => false])],
                'could not be determined',
            ],
        ];

        foreach ($cases as $label => [$rows, $fragment]) {
            $client = Mockery::mock(CippRestWriteClient::class);
            $client->shouldReceive('listGroups')->once()->andReturn($rows);
            $client->shouldNotReceive('setGroupMembership');
            $this->app->instance(CippRestWriteClient::class, $client);

            $response = $this->callTool($token, 'cipp_set_group_membership', [
                'client_id' => $fixture['client']->id,
                'person_id' => $fixture['person']->id,
                'group_id' => self::GROUP_ID,
                'operation' => 'add',
                'confirm_group_name' => 'Sales Team',
                'confirm_upn' => 'alex@acme.example',
                'reason' => "Guard case: {$label}.",
            ]);

            $this->assertTrue((bool) $response->json('result.isError'), "{$label} should refuse");
            $this->assertStringContainsString($fragment, (string) $response->json('result.content.0.text'), $label);
        }

        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_staged_add_holds_for_approval_then_executes_with_fresh_verification(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_set_group_membership']);

        // Staging verifies the group against the live listing (so the proposal
        // shows server-verified facts) but never calls the write itself.
        $stageClient = Mockery::mock(CippRestWriteClient::class);
        $stageClient->shouldReceive('listGroups')->once()
            ->with('acme.onmicrosoft.com')
            ->andReturn([$this->groupRow()]);
        $stageClient->shouldNotReceive('setGroupMembership');
        $this->app->instance(CippRestWriteClient::class, $stageClient);

        $response = $this->callTool($token, 'cipp_stage_set_group_membership', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'group_id' => self::GROUP_ID,
            'operation' => 'add',
            'ticket_id' => $fixture['ticket']->id,
            'confirm_group_name' => 'Sales Team',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Add the new hire to the sales collaboration group.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame('cipp_stage_set_group_membership', $run->action_type);

        // The held payload stores only safe scalars: the group id, operation,
        // and the verified name/type snapshot for drift detection at approval.
        $stored = json_encode($run->proposed_meta);
        $this->assertStringContainsString(self::GROUP_ID, $stored);
        $this->assertStringContainsString('"operation":"add"', $stored);
        $this->assertStringContainsString('Sales Team', $stored);
        $this->assertStringNotContainsString('alex@acme.example', $stored);

        // The operator-facing proposal names the user by UPN and the group by
        // its VERIFIED display name and type so the human gate reviews the
        // real thing, not the caller's description.
        $this->assertStringContainsString('alex@acme.example', $run->proposed_content);
        $this->assertStringContainsString('Sales Team', $run->proposed_content);
        $this->assertStringContainsString('Microsoft 365', $run->proposed_content);

        // Approval re-verifies the group fresh, then executes with the
        // verified identity — one listing read, one write.
        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldReceive('listGroups')->once()
            ->with('acme.onmicrosoft.com')
            ->andReturn([$this->groupRow()]);
        $approveClient->shouldReceive('setGroupMembership')->once()
            ->with('acme.onmicrosoft.com', self::GROUP_ID, 'Sales Team', 'Microsoft 365', 'user-123', 'alex@acme.example', 'add')
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $this->actingAs($actor)
            ->post(route('cockpit.approve', $run))
            ->assertRedirect(route('cockpit.index'));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'cipp_stage_set_group_membership',
            'result_status' => 'executed',
            'ticket_id' => $fixture['ticket']->id,
            'client_id' => $fixture['client']->id,
            'run_id' => $run->id,
            'approver_user_id' => $actor->id,
        ]);
    }

    public function test_add_rejects_an_inactive_user_at_staging_and_direct(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_set_group_membership', 'cipp_stage_set_group_membership']);

        // Same gap class as the delegate GRANT and the OneDrive successor
        // (psa-pgnj): a deactivated person routinely keeps their CIPP mapping,
        // but ADDING them to a group would hand group access (data, resources,
        // mail) to a former employee — refused before the verification read.
        $fixture['person']->update(['is_active' => false]);

        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('listGroups');
        $blocked->shouldNotReceive('setGroupMembership');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        $staged = $this->callTool($token, 'cipp_stage_set_group_membership', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'group_id' => self::GROUP_ID,
            'operation' => 'add',
            'ticket_id' => $fixture['ticket']->id,
            'confirm_group_name' => 'Sales Team',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Adding a deactivated user to a group must be refused.',
        ]);
        $this->assertTrue((bool) $staged->json('result.isError'));
        $this->assertStringContainsString('is inactive in the PSA', (string) $staged->json('result.content.0.text'));
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'cipp_stage_set_group_membership',
            'result_status' => 'rejected',
            'client_id' => $fixture['client']->id,
        ]);

        $direct = $this->callTool($token, 'cipp_set_group_membership', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'group_id' => self::GROUP_ID,
            'operation' => 'add',
            'confirm_group_name' => 'Sales Team',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Direct add of a deactivated user must be refused.',
        ]);
        $this->assertTrue((bool) $direct->json('result.isError'));
        $this->assertStringContainsString('is inactive in the PSA', (string) $direct->json('result.content.0.text'));

        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_add_approval_declines_when_the_user_was_deactivated_after_staging(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_set_group_membership']);

        $stageClient = Mockery::mock(CippRestWriteClient::class);
        $stageClient->shouldReceive('listGroups')->once()->andReturn([$this->groupRow()]);
        $stageClient->shouldNotReceive('setGroupMembership');
        $this->app->instance(CippRestWriteClient::class, $stageClient);

        $response = $this->callTool($token, 'cipp_stage_set_group_membership', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'group_id' => self::GROUP_ID,
            'operation' => 'add',
            'ticket_id' => $fixture['ticket']->id,
            'confirm_group_name' => 'Sales Team',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Add to group; the user offboards before approval.',
        ]);
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);

        // The user was offboarded between staging and approval: the replay
        // re-resolves them fresh and must fail closed with the specific reason.
        $fixture['person']->update(['is_active' => false]);

        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldNotReceive('listGroups');
        $approveClient->shouldNotReceive('setGroupMembership');
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $declined = $this->actingAs($actor)->postJson(route('cockpit.approve', $run));

        $declined->assertOk();
        $this->assertFalse((bool) $declined->json('ok'));
        $this->assertSame('gate_declined', $declined->json('status'));
        $this->assertStringContainsString('is inactive in the PSA', (string) $declined->json('message'));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    public function test_remove_still_works_for_an_inactive_user(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_set_group_membership']);

        // The inverse flow of the add gate: REMOVING an already-deactivated
        // user from a group grants nothing to anyone and is routine offboarding
        // cleanup — the recipient gate must not block it, or the operator would
        // have to reactivate a former employee just to revoke their access.
        $fixture['person']->update(['is_active' => false]);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listGroups')->once()
            ->with('acme.onmicrosoft.com')
            ->andReturn([$this->securityGroupRow()]);
        $client->shouldReceive('setGroupMembership')->once()
            ->with('acme.onmicrosoft.com', self::GROUP_ID_2, 'VPN Users', 'Security', 'user-123', 'alex@acme.example', 'remove')
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_set_group_membership', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'group_id' => self::GROUP_ID_2,
            'operation' => 'remove',
            'confirm_group_name' => 'VPN Users',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Offboarding cleanup: revoke the departed user\'s VPN group access.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertSame('CIPP group membership change executed.', $this->decodedResult($response)['message']);
    }

    public function test_approval_declines_on_group_drift_and_executes_once_the_listing_matches(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_set_group_membership']);

        $stageClient = Mockery::mock(CippRestWriteClient::class);
        $stageClient->shouldReceive('listGroups')->once()->andReturn([$this->groupRow()]);
        $stageClient->shouldNotReceive('setGroupMembership');
        $this->app->instance(CippRestWriteClient::class, $stageClient);

        $response = $this->callTool($token, 'cipp_stage_set_group_membership', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'group_id' => self::GROUP_ID,
            'operation' => 'add',
            'ticket_id' => $fixture['ticket']->id,
            'confirm_group_name' => 'Sales Team',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Add to group; the group drifts before approval.',
        ]);
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);

        $approveExpecting = function (array $rows) use ($actor, $run): TestResponse {
            $client = Mockery::mock(CippRestWriteClient::class);
            $client->shouldReceive('listGroups')->once()->andReturn($rows);
            $client->shouldNotReceive('setGroupMembership');
            $this->app->instance(CippRestWriteClient::class, $client);

            return $this->actingAs($actor)->postJson(route('cockpit.approve', $run));
        };

        // The group vanished from the tenant listing.
        $gone = $approveExpecting([$this->securityGroupRow()]);
        $this->assertSame('gate_declined', $gone->json('status'));
        $this->assertStringContainsString('not found in this client tenant', (string) $gone->json('message'));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);

        // The group was renamed after staging — the operator approved a
        // proposal naming a different group; refuse and re-stage.
        $renamed = $approveExpecting([$this->groupRow(['displayName' => 'Sales Team EMEA'])]);
        $this->assertSame('gate_declined', $renamed->json('status'));
        $this->assertStringContainsString('display name changed', (string) $renamed->json('message'));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);

        // The group's TYPE changed after staging (membership semantics change).
        $retyped = $approveExpecting([$this->groupRow(['groupTypes' => [], 'securityEnabled' => true, 'mailEnabled' => false, 'mail' => null, 'groupType' => 'Security', 'calculatedGroupType' => 'generic'])]);
        $this->assertSame('gate_declined', $retyped->json('status'));
        $this->assertStringContainsString('group type changed', (string) $retyped->json('message'));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);

        // Listing matches the staged snapshot again — the approval executes.
        $goodClient = Mockery::mock(CippRestWriteClient::class);
        $goodClient->shouldReceive('listGroups')->once()->andReturn([$this->groupRow()]);
        $goodClient->shouldReceive('setGroupMembership')->once()
            ->with('acme.onmicrosoft.com', self::GROUP_ID, 'Sales Team', 'Microsoft 365', 'user-123', 'alex@acme.example', 'add')
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $goodClient);

        $this->actingAs($actor)
            ->post(route('cockpit.approve', $run))
            ->assertRedirect(route('cockpit.index'));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
    }

    public function test_group_id_must_be_a_well_formed_guid(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_set_group_membership']);

        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('listGroups');
        $blocked->shouldNotReceive('setGroupMembership');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        // A mail address or display name is not accepted as the group identity —
        // only the GUID from the CIPP group reads, so the verification read can
        // never be fed an ambiguous Exchange identity.
        $response = $this->callTool($token, 'cipp_set_group_membership', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'group_id' => 'sales@acme.example',
            'operation' => 'add',
            'confirm_group_name' => 'Sales Team',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Group addressed by mail instead of GUID.',
        ]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('group_id must be', (string) $response->json('result.content.0.text'));
    }
}
