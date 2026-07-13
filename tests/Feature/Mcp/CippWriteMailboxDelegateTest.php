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
 * Staged mailbox DELEGATE grant/remove (bead psa-hqk9): one capability
 * (cipp_set_mailbox_delegate) with a staged twin, covering FullAccess
 * (auto-map/no-auto-map), Send-As, and Send-on-Behalf across grant + remove.
 * Ships behind an explicit sensitive token grant; held-only in practice by
 * granting the :staged mode.
 *
 * Inactive-recipient gate (bead psa-pgnj, OneDrive-successor precedent): a
 * GRANT names the delegate as an access recipient, so an inactive delegate is
 * refused at staging AND declines fresh on the approval replay. A REMOVE is
 * the opposite flow — revoking access from an already-deactivated delegate is
 * routine offboarding cleanup and stays allowed. The mailbox OWNER may be
 * inactive on either operation (expected mid-offboarding).
 */
class CippWriteMailboxDelegateTest extends TestCase
{
    use RefreshDatabase;

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

    /** @return array{client: Client, person: Person, target: Person, ticket: Ticket} */
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

        $target = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Jordan',
            'last_name' => 'Acme',
            'email' => 'target@acme.example',
            'cipp_user_id' => 'target-456',
            'cipp_upn' => 'target@acme.example',
            'is_active' => true,
        ]);

        $ticket = Ticket::factory()->for($client)->create([
            'contact_id' => $person->id,
            'subject' => 'Mailbox delegation',
        ]);

        return compact('client', 'person', 'target', 'ticket');
    }

    public function test_delegate_tool_is_sensitive_explicit_grant_only_and_schema_is_safe(): void
    {
        $this->configureCipp();

        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('cipp_write', $groups);
        $this->assertTrue($groups['cipp_write']['sensitive']);

        $writeNames = array_column($groups['cipp_write']['tools'], 'name');
        // The staged twin is a retired alias: callable, but the catalog carries
        // only the canonical capability (granted via a :staged mode).
        $this->assertSame('cipp_set_mailbox_delegate', McpToolModes::canonicalForAlias('cipp_stage_set_mailbox_delegate'));
        $this->assertNotContains('cipp_stage_set_mailbox_delegate', $writeNames);
        $this->assertContains('cipp_set_mailbox_delegate', $writeNames);
        $this->assertContains('cipp_set_mailbox_delegate', McpToolRegistry::allToolNames());

        // A legacy full-surface token must not silently gain the new sensitive tool.
        $legacyNames = array_column($this->listTools($this->legacyToken()), 'name');
        $this->assertNotContains('cipp_set_mailbox_delegate', $legacyNames);
        $this->assertNotContains('cipp_stage_set_mailbox_delegate', $legacyNames);

        $scoped = collect($this->listTools($this->token(['cipp_set_mailbox_delegate'])))->keyBy('name');
        $this->assertFalse($scoped->has('cipp_stage_set_mailbox_delegate'));
        $tool = $scoped['cipp_set_mailbox_delegate'];

        $this->assertContains('client_id', $tool['inputSchema']['required']);
        foreach (['person_id', 'delegate_person_id', 'permission', 'operation', 'confirm_upn', 'reason'] as $req) {
            $this->assertContains($req, $tool['inputSchema']['required']);
        }
        // No upstream CIPP identities are ever accepted from the caller.
        $this->assertArrayNotHasKey('tenantFilter', $tool['inputSchema']['properties']);
        $this->assertArrayNotHasKey('UserID', $tool['inputSchema']['properties']);
        $this->assertArrayNotHasKey('ID', $tool['inputSchema']['properties']);

        $this->assertSame(['full_access', 'send_as', 'send_on_behalf'], $tool['inputSchema']['properties']['permission']['enum']);
        $this->assertSame(['grant', 'remove'], $tool['inputSchema']['properties']['operation']['enum']);
        // Unified surface: one tool, with the staged parameter folded in.
        $this->assertArrayHasKey('staged', $tool['inputSchema']['properties']);
        $this->assertStringContainsString('Supports staged=true', $tool['description']);
    }

    public function test_direct_delegate_grant_and_remove_use_server_derived_scope(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_set_mailbox_delegate']);

        // Caller-supplied upstream identifiers are rejected before any upstream call.
        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('setMailboxDelegate');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        $rejected = $this->callTool($token, 'cipp_set_mailbox_delegate', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'delegate_person_id' => $fixture['target']->id,
            'permission' => 'full_access',
            'operation' => 'grant',
            'UserID' => 'attacker@evil.example',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Reject caller-supplied upstream mailbox identity.',
        ]);
        $this->assertTrue((bool) $rejected->json('result.isError'));
        $this->assertStringContainsString('upstream CIPP identifiers are not accepted', (string) $rejected->json('result.content.0.text'));

        // confirm_upn must match the resolved mailbox owner.
        $mismatch = $this->callTool($token, 'cipp_set_mailbox_delegate', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'delegate_person_id' => $fixture['target']->id,
            'permission' => 'full_access',
            'operation' => 'grant',
            'confirm_upn' => 'someone-else@acme.example',
            'reason' => 'Wrong confirmation.',
        ]);
        $this->assertTrue((bool) $mismatch->json('result.isError'));
        $this->assertStringContainsString('confirm_upn does not match', (string) $mismatch->json('result.content.0.text'));

        // A second mailbox owner: the per-target cooldown is keyed on the owner,
        // so grant and remove are exercised against distinct mailboxes. (Every
        // permission/operation body shape is pinned in CippRestWriteClientTest.)
        $owner2 = Person::create([
            'client_id' => $fixture['client']->id,
            'person_type' => PersonType::User,
            'first_name' => 'Sam',
            'last_name' => 'Acme',
            'email' => 'sam@acme.example',
            'cipp_user_id' => 'user-789',
            'cipp_upn' => 'sam@acme.example',
            'is_active' => true,
        ]);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('setMailboxDelegate')
            ->once()
            ->with('acme.onmicrosoft.com', 'alex@acme.example', 'target@acme.example', 'full_access', 'grant', true)
            ->andReturn(['success' => true, 'status' => 200]);
        $client->shouldReceive('setMailboxDelegate')
            ->once()
            ->with('acme.onmicrosoft.com', 'sam@acme.example', 'target@acme.example', 'send_as', 'remove', true)
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $client);

        foreach ([
            [$fixture['person']->id, 'alex@acme.example', 'full_access', 'grant', ['auto_map' => true], 'Grant delegate full access with automap during coverage.'],
            [$owner2->id, 'sam@acme.example', 'send_as', 'remove', [], 'Remove stale Send-As after role change.'],
        ] as [$ownerId, $ownerUpn, $permission, $operation, $extra, $reason]) {
            $response = $this->callTool($token, 'cipp_set_mailbox_delegate', array_merge([
                'client_id' => $fixture['client']->id,
                'person_id' => $ownerId,
                'delegate_person_id' => $fixture['target']->id,
                'permission' => $permission,
                'operation' => $operation,
                'ticket_id' => $fixture['ticket']->id,
                'confirm_upn' => $ownerUpn,
                'reason' => $reason,
            ], $extra));

            $response->assertOk();
            $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
            $this->assertSame('CIPP action executed.', $this->decodedResult($response)['message']);
        }

        $this->assertSame(2, TechnicianActionLog::where('action_type', 'cipp_set_mailbox_delegate')
            ->where('result_status', 'executed')->count());

        // The audit summary references PSA person ids, never the upstream tenant/UPN identifiers.
        $summary = TechnicianActionLog::where('action_type', 'cipp_set_mailbox_delegate')
            ->where('result_status', 'executed')->latest('id')->firstOrFail()->summary;
        $this->assertStringContainsString('person #'.$owner2->id, $summary);
        $this->assertStringNotContainsString('acme.onmicrosoft.com', $summary);
    }

    public function test_staged_delegate_grant_holds_for_approval_then_executes(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_set_mailbox_delegate']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('setMailboxDelegate');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_stage_set_mailbox_delegate', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'delegate_person_id' => $fixture['target']->id,
            'permission' => 'send_on_behalf',
            'operation' => 'grant',
            'ticket_id' => $fixture['ticket']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Grant Send-on-Behalf to the delegate for calendar coverage.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame('cipp_stage_set_mailbox_delegate', $run->action_type);

        // Delegate identity is stored by PSA id, and the permission/operation are recorded.
        $stored = json_encode($run->proposed_meta);
        $this->assertStringContainsString('"delegate_person_id":'.$fixture['target']->id, $stored);
        $this->assertStringContainsString('send_on_behalf', $stored);

        // The operator-facing proposal names BOTH parties by UPN so the human gate can
        // verify who gains access to whose mailbox; the stored meta/payload stay id-only.
        $this->assertStringContainsString('target@acme.example', $run->proposed_content);
        $this->assertStringContainsString('alex@acme.example', $run->proposed_content);
        $this->assertStringNotContainsString('target@acme.example', $stored);

        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldReceive('setMailboxDelegate')
            ->once()
            ->with('acme.onmicrosoft.com', 'alex@acme.example', 'target@acme.example', 'send_on_behalf', 'grant', true)
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $this->actingAs($actor)
            ->post(route('cockpit.approve', $run))
            ->assertRedirect(route('cockpit.index'));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'cipp_stage_set_mailbox_delegate',
            'result_status' => 'executed',
            'ticket_id' => $fixture['ticket']->id,
            'client_id' => $fixture['client']->id,
            'run_id' => $run->id,
            'approver_user_id' => $actor->id,
        ]);
    }

    public function test_delegate_rejects_unknown_permission_and_operation(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_set_mailbox_delegate']);

        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('setMailboxDelegate');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        $badPermission = $this->callTool($token, 'cipp_set_mailbox_delegate', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'delegate_person_id' => $fixture['target']->id,
            'permission' => 'owner',
            'operation' => 'grant',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Unsupported permission kind.',
        ]);
        $this->assertTrue((bool) $badPermission->json('result.isError'));
        $this->assertStringContainsString('permission must be one of', (string) $badPermission->json('result.content.0.text'));

        $badOperation = $this->callTool($token, 'cipp_set_mailbox_delegate', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'delegate_person_id' => $fixture['target']->id,
            'permission' => 'full_access',
            'operation' => 'transfer',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Unsupported operation.',
        ]);
        $this->assertTrue((bool) $badOperation->json('result.isError'));
        $this->assertStringContainsString('operation must be one of', (string) $badOperation->json('result.content.0.text'));
    }

    public function test_delegate_rejects_self_delegation_and_cross_client_delegate(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_set_mailbox_delegate']);

        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('setMailboxDelegate');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        // Self-delegation (delegate == owner) is a no-op and rejected.
        $selfDelegate = $this->callTool($token, 'cipp_set_mailbox_delegate', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'delegate_person_id' => $fixture['person']->id,
            'permission' => 'full_access',
            'operation' => 'grant',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Self-delegation should be rejected.',
        ]);
        $this->assertTrue((bool) $selfDelegate->json('result.isError'));
        $this->assertStringContainsString('different person than the mailbox owner', (string) $selfDelegate->json('result.content.0.text'));

        // A delegate in a DIFFERENT client cannot be targeted (server-side scope).
        $otherClient = Client::factory()->create(['name' => 'Other', 'cipp_tenant_domain' => 'other.onmicrosoft.com']);
        $foreignDelegate = Person::create([
            'client_id' => $otherClient->id,
            'person_type' => PersonType::User,
            'first_name' => 'Eve',
            'last_name' => 'Other',
            'email' => 'eve@other.example',
            'cipp_user_id' => 'other-999',
            'cipp_upn' => 'eve@other.example',
            'is_active' => true,
        ]);

        $crossClient = $this->callTool($token, 'cipp_set_mailbox_delegate', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'delegate_person_id' => $foreignDelegate->id,
            'permission' => 'full_access',
            'operation' => 'grant',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Cross-client delegate must be rejected.',
        ]);
        $this->assertTrue((bool) $crossClient->json('result.isError'));
    }

    public function test_delegate_grant_rejects_an_inactive_delegate_at_staging(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_set_mailbox_delegate', 'cipp_stage_set_mailbox_delegate']);

        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('setMailboxDelegate');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        // Same gap class as the OneDrive successor (psa-zjpd .6/.7): a
        // deactivated person routinely keeps their CIPP mapping, but a GRANT
        // would hand mailbox access to a former employee — refused before
        // anything is staged.
        $fixture['target']->update(['is_active' => false]);

        $staged = $this->callTool($token, 'cipp_stage_set_mailbox_delegate', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'delegate_person_id' => $fixture['target']->id,
            'permission' => 'full_access',
            'operation' => 'grant',
            'ticket_id' => $fixture['ticket']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Coverage grant to a deactivated delegate must be refused.',
        ]);
        $this->assertTrue((bool) $staged->json('result.isError'));
        $this->assertStringContainsString('Delegate is inactive', (string) $staged->json('result.content.0.text'));
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'cipp_stage_set_mailbox_delegate',
            'result_status' => 'rejected',
            'client_id' => $fixture['client']->id,
        ]);

        // The direct path runs the same params gate.
        $direct = $this->callTool($token, 'cipp_set_mailbox_delegate', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'delegate_person_id' => $fixture['target']->id,
            'permission' => 'send_as',
            'operation' => 'grant',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Direct grant to a deactivated delegate must be refused.',
        ]);
        $this->assertTrue((bool) $direct->json('result.isError'));
        $this->assertStringContainsString('Delegate is inactive', (string) $direct->json('result.content.0.text'));

        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_delegate_approval_declines_when_the_delegate_was_deactivated_after_staging(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_set_mailbox_delegate']);

        $stageClient = Mockery::mock(CippRestWriteClient::class);
        $stageClient->shouldNotReceive('setMailboxDelegate');
        $this->app->instance(CippRestWriteClient::class, $stageClient);

        $response = $this->callTool($token, 'cipp_stage_set_mailbox_delegate', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'delegate_person_id' => $fixture['target']->id,
            'permission' => 'full_access',
            'operation' => 'grant',
            'ticket_id' => $fixture['ticket']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Grant full access for coverage; the delegate offboards before approval.',
        ]);
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);

        // The delegate was offboarded between staging and approval: the replay
        // re-resolves them fresh and must fail closed with the specific reason
        // — no access handed over, no generic dead end for the operator.
        $fixture['target']->update(['is_active' => false]);

        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldNotReceive('setMailboxDelegate');
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $declined = $this->actingAs($actor)->postJson(route('cockpit.approve', $run));

        $declined->assertOk();
        $this->assertFalse((bool) $declined->json('ok'));
        $this->assertSame('gate_declined', $declined->json('status'));
        $this->assertStringContainsString('Delegate is inactive', (string) $declined->json('message'));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    public function test_delegate_remove_still_works_for_an_inactive_delegate(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_set_mailbox_delegate']);

        // The inverse flow of the grant gate: REMOVING access from an already-
        // deactivated delegate is routine offboarding cleanup (nothing is
        // granted to anyone), so the recipient gate must not block it — or the
        // operator would have to reactivate a former employee to revoke them.
        $fixture['target']->update(['is_active' => false]);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('setMailboxDelegate')
            ->once()
            ->with('acme.onmicrosoft.com', 'alex@acme.example', 'target@acme.example', 'full_access', 'remove', true)
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_set_mailbox_delegate', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'delegate_person_id' => $fixture['target']->id,
            'permission' => 'full_access',
            'operation' => 'remove',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Offboarding cleanup: revoke the departed delegate\'s mailbox access.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertSame('CIPP action executed.', $this->decodedResult($response)['message']);
    }

    public function test_delegate_grant_still_executes_when_the_mailbox_owner_is_inactive(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_set_mailbox_delegate']);

        $stageClient = Mockery::mock(CippRestWriteClient::class);
        $stageClient->shouldNotReceive('setMailboxDelegate');
        $this->app->instance(CippRestWriteClient::class, $stageClient);

        // Mid-offboarding the mailbox OWNER is often already deactivated
        // (contact sync mirrors accountEnabled). Only the RECIPIENT must be
        // active — delegating the departed user's mailbox to an active
        // colleague is exactly the coverage flow this tool exists for.
        $fixture['person']->update(['is_active' => false]);

        $response = $this->callTool($token, 'cipp_stage_set_mailbox_delegate', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'delegate_person_id' => $fixture['target']->id,
            'permission' => 'full_access',
            'operation' => 'grant',
            'ticket_id' => $fixture['ticket']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Offboarding coverage: delegate the departed user\'s mailbox to a colleague.',
        ]);
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);

        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldReceive('setMailboxDelegate')
            ->once()
            ->with('acme.onmicrosoft.com', 'alex@acme.example', 'target@acme.example', 'full_access', 'grant', true)
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $this->actingAs($actor)
            ->post(route('cockpit.approve', $run))
            ->assertRedirect(route('cockpit.index'));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
    }

    public function test_direct_full_access_grant_without_automap_routes_no_automap_bucket(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_set_mailbox_delegate']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('setMailboxDelegate')
            ->once()
            ->with('acme.onmicrosoft.com', 'alex@acme.example', 'target@acme.example', 'full_access', 'grant', false)
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_set_mailbox_delegate', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'delegate_person_id' => $fixture['target']->id,
            'permission' => 'full_access',
            'operation' => 'grant',
            'auto_map' => false,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Grant full access without automap for a shared mailbox.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertSame('CIPP action executed.', $this->decodedResult($response)['message']);
    }
}
