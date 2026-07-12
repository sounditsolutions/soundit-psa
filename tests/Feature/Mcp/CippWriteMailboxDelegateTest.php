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
}
