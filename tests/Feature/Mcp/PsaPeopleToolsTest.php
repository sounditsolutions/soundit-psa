<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TicketStatus;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Contract;
use App\Models\McpAuditLog;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\Ticket;
use App\Models\User;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Feature coverage for the P2b PSA write-surface People/Contacts CRUD MCP tools
 * (create_contact, update_contact, set_primary_contact, move_contact_to_client,
 * delete_contact) in the dormant psa_records group. Mirrors PsaRecordsToolsTest.
 */
class PsaPeopleToolsTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $tools, string $label = 'opsbot'): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: $label);
    }

    private function legacyToken(): string
    {
        return McpConfig::rotateStaffToken();
    }

    private function configureAiActor(): User
    {
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        return $actor;
    }

    private function callTool(string $token, string $name, array $arguments): TestResponse
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
    private function tools(string $token): array
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

    private function contact(Client $client, array $overrides = []): Person
    {
        return Person::create(array_merge([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Existing',
            'last_name' => 'Contact',
            'email' => 'existing@example.test',
            'is_active' => true,
        ], $overrides));
    }

    public function test_registry_lists_people_tools_in_psa_records_and_requires_grants(): void
    {
        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('psa_records', $groups);
        $this->assertTrue($groups['psa_records']['sensitive']);

        $names = array_column($groups['psa_records']['tools'], 'name');
        foreach (['create_contact', 'update_contact', 'set_primary_contact', 'move_contact_to_client', 'delete_contact'] as $name) {
            $this->assertContains($name, $names);
        }
        // The P2a client tools remain in the same group.
        $this->assertContains('create_client', $names);

        // Dormant by default.
        $legacyNames = collect($this->tools($this->legacyToken()))->pluck('name')->all();
        foreach (['create_contact', 'update_contact', 'set_primary_contact', 'move_contact_to_client', 'delete_contact'] as $name) {
            $this->assertNotContains($name, $legacyNames);
        }

        $scoped = collect($this->tools($this->token(['create_contact', 'update_contact'], 'chet')))->keyBy('name');
        $this->assertTrue($scoped->has('create_contact'));
        $this->assertTrue($scoped->has('update_contact'));
        $this->assertFalse($scoped->has('delete_contact'));

        // create_contact is parent-scoped — client_id is required.
        $createSchema = $scoped['create_contact']['inputSchema'];
        $this->assertArrayHasKey('client_id', $createSchema['properties']);
        $this->assertContains('client_id', $createSchema['required']);
        // Danger + non-FormRequest fields never appear in the schema.
        foreach (['portal_enabled', 'password', 'company_wide_access', 'cipp_upn', 'mailbox_size_bytes', 'department', 'office_location'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $createSchema['properties']);
        }

        // update_contact is contact-scoped — contact_id required, NO client_id.
        $updateSchema = $scoped['update_contact']['inputSchema'];
        $this->assertArrayHasKey('contact_id', $updateSchema['properties']);
        $this->assertContains('contact_id', $updateSchema['required']);
        $this->assertArrayNotHasKey('client_id', $updateSchema['properties']);
    }

    public function test_ungranted_and_legacy_tokens_cannot_call_people_tools(): void
    {
        $client = Client::factory()->create();
        $person = $this->contact($client);

        $calls = [
            ['create_contact', ['client_id' => $client->id, 'first_name' => 'Nope']],
            ['update_contact', ['contact_id' => $person->id, 'first_name' => 'Nope']],
            ['set_primary_contact', ['contact_id' => $person->id]],
            ['move_contact_to_client', ['contact_id' => $person->id, 'new_client_id' => $client->id, 'confirm_client_name' => $client->name, 'reason' => 'x']],
            ['delete_contact', ['contact_id' => $person->id, 'confirm_contact_name' => $person->full_name, 'reason' => 'x']],
        ];

        foreach ([$this->token(['create_ticket'], 'chet'), $this->legacyToken()] as $token) {
            foreach ($calls as [$tool, $arguments]) {
                $response = $this->callTool($token, $tool, $arguments);
                $response->assertOk();
                $this->assertTrue((bool) $response->json('result.isError'), "{$tool} should be denied.");
                $this->assertStringContainsString('not allowed for this token', (string) $response->json('result.content.0.text'));
            }
        }

        $this->assertSame(1, Person::query()->count());
        $this->assertNull($person->fresh()->deleted_at);
        $this->assertSame(0, TechnicianActionLog::query()->count());
    }

    public function test_create_contact_requires_client_id_and_creates_with_audit(): void
    {
        $actor = $this->configureAiActor();
        $client = Client::factory()->create();
        $token = $this->token(['create_contact'], 'chet');

        // Missing parent client_id → rejected.
        $missing = $this->callTool($token, 'create_contact', ['first_name' => 'Orphan']);
        $missing->assertOk();
        $this->assertTrue((bool) $missing->json('result.isError'));
        $this->assertStringContainsString('client_id is required', (string) $missing->json('result.content.0.text'));

        $notes = 'Primary technical contact for the account.';
        $response = $this->callTool($token, 'create_contact', [
            'client_id' => $client->id,
            'first_name' => 'Dana',
            'last_name' => 'Ops',
            'email' => 'dana@acme.test',
            'job_title' => 'IT Manager',
            'notes' => $notes,
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $result = $this->decodedResult($response);
        $person = Person::findOrFail($result['contact_id']);
        $this->assertSame($client->id, $person->client_id);
        $this->assertSame('Dana', $person->first_name);
        $this->assertSame('dana@acme.test', $person->email);

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'create_contact',
            'result_status' => 'executed',
            'ticket_id' => null,
            'client_id' => $client->id,
            'actor_id' => $actor->id,
            'actor_label' => 'mcp-staff:chet',
        ]);

        $log = TechnicianActionLog::where('action_type', 'create_contact')->firstOrFail();
        $this->assertStringContainsString('[person#'.$person->id.']', (string) $log->summary);

        // Two create_contact calls were made (the missing-client_id rejection,
        // then this success) — scope to the successful audit row.
        $audit = McpAuditLog::where('tool_name', 'create_contact')->where('status', 'success')->firstOrFail();
        $this->assertSame('success', $audit->status);
        $this->assertSame(mb_strlen($notes), $audit->arguments['notes_length']);
        $this->assertArrayNotHasKey('notes', $audit->arguments);
        $this->assertStringNotContainsString($notes, (string) json_encode($audit->arguments));
    }

    public function test_create_contact_rejects_portal_password_and_non_formrequest_fields(): void
    {
        $client = Client::factory()->create();
        $token = $this->token(['create_contact'], 'chet');

        foreach ([
            ['portal_enabled' => true],
            ['password' => 'hunter2'],
            ['company_wide_access' => true],
            ['cipp_upn' => 'x@y.test'],
            ['mailbox_size_bytes' => 123],
            ['department' => 'IT'],
            ['office_location' => 'HQ'],
        ] as $danger) {
            $response = $this->callTool($token, 'create_contact', array_merge([
                'client_id' => $client->id,
                'first_name' => 'Guard',
            ], $danger));
            $response->assertOk();
            $this->assertTrue((bool) $response->json('result.isError'), 'field '.array_key_first($danger).' must be rejected');
        }

        $this->assertSame(0, Person::query()->count());
    }

    public function test_update_contact_is_contact_scoped_and_forbids_client_id(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create();
        $other = Client::factory()->create();
        $person = $this->contact($client, ['first_name' => 'Old', 'last_name' => 'Title']);
        $token = $this->token(['update_contact'], 'chet');

        // Happy path.
        $ok = $this->callTool($token, 'update_contact', [
            'contact_id' => $person->id,
            'first_name' => 'New',
            'job_title' => 'Director',
        ]);
        $ok->assertOk();
        $this->assertFalse((bool) $ok->json('result.isError'), (string) $ok->json('result.content.0.text'));
        $person->refresh();
        $this->assertSame('New', $person->first_name);
        $this->assertSame('Director', $person->job_title);

        // A supplied client_id is forbidden (scope is derived from contact_id).
        $stray = $this->callTool($token, 'update_contact', [
            'contact_id' => $person->id,
            'client_id' => $other->id,
            'first_name' => 'Nope',
        ]);
        $stray->assertOk();
        $this->assertTrue((bool) $stray->json('result.isError'));
        $this->assertStringContainsString('client_id must be omitted', (string) $stray->json('result.content.0.text'));
        $this->assertSame($client->id, $person->fresh()->client_id);

        // Unknown contact → rejected.
        $unknown = $this->callTool($token, 'update_contact', ['contact_id' => 999999, 'first_name' => 'Ghost']);
        $unknown->assertOk();
        $this->assertTrue((bool) $unknown->json('result.isError'));
        $this->assertStringContainsString('existing contact', (string) $unknown->json('result.content.0.text'));

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'update_contact',
            'ticket_id' => null,
            'client_id' => $client->id,
        ]);
    }

    public function test_set_primary_contact_demotes_prior_primary(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create();
        $current = $this->contact($client, ['first_name' => 'Current', 'email' => 'current@example.test', 'is_primary' => true]);
        $target = $this->contact($client, ['first_name' => 'Target', 'email' => 'target@example.test', 'is_primary' => false]);
        $token = $this->token(['set_primary_contact'], 'chet');

        $response = $this->callTool($token, 'set_primary_contact', ['contact_id' => $target->id]);
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $this->assertTrue((bool) $target->fresh()->is_primary);
        $this->assertFalse((bool) $current->fresh()->is_primary);

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'set_primary_contact',
            'ticket_id' => null,
            'client_id' => $client->id,
        ]);
    }

    public function test_move_contact_to_client_requires_typed_confirm_and_reparents(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create();
        $target = Client::factory()->create(['name' => 'Target Co']);
        $person = $this->contact($client);
        $token = $this->token(['move_contact_to_client'], 'chet');

        // Wrong confirm → refused.
        $bad = $this->callTool($token, 'move_contact_to_client', [
            'contact_id' => $person->id,
            'new_client_id' => $target->id,
            'confirm_client_name' => 'Wrong Name',
            'reason' => 'Account merger.',
        ]);
        $bad->assertOk();
        $this->assertTrue((bool) $bad->json('result.isError'));
        $this->assertStringContainsString('confirm_client_name', (string) $bad->json('result.content.0.text'));
        $this->assertSame($client->id, $person->fresh()->client_id);

        // Correct confirm → reparented.
        $ok = $this->callTool($token, 'move_contact_to_client', [
            'contact_id' => $person->id,
            'new_client_id' => $target->id,
            'confirm_client_name' => 'Target Co',
            'reason' => 'Account merger to the parent org.',
        ]);
        $ok->assertOk();
        $this->assertFalse((bool) $ok->json('result.isError'), (string) $ok->json('result.content.0.text'));
        $this->assertSame($target->id, $person->fresh()->client_id);

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'move_contact_to_client',
            'ticket_id' => null,
            'client_id' => $target->id,
        ]);
    }

    public function test_move_contact_to_same_client_is_rejected(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create(['name' => 'Same Co']);
        $person = $this->contact($client);
        $token = $this->token(['move_contact_to_client'], 'chet');

        $response = $this->callTool($token, 'move_contact_to_client', [
            'contact_id' => $person->id,
            'new_client_id' => $client->id,
            'confirm_client_name' => 'Same Co',
            'reason' => 'A no-op same-client move must be refused, not report phantom detaches.',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('already belongs to that client', (string) $response->json('result.content.0.text'));
    }

    public function test_move_contact_does_not_create_duplicate_primary_in_target(): void
    {
        $this->configureAiActor();
        $source = Client::factory()->create();
        $target = Client::factory()->create(['name' => 'Target Co']);
        $moving = $this->contact($source, ['first_name' => 'Moving', 'email' => 'moving@example.test', 'is_primary' => true]);
        $targetPrimary = $this->contact($target, ['first_name' => 'Target', 'email' => 'target-primary@example.test', 'is_primary' => true]);
        $token = $this->token(['move_contact_to_client'], 'chet');

        $response = $this->callTool($token, 'move_contact_to_client', [
            'contact_id' => $moving->id,
            'new_client_id' => $target->id,
            'confirm_client_name' => 'Target Co',
            'reason' => 'Consolidation of the contact into the parent org.',
        ]);
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        // The moved contact lands as non-primary; the target keeps its own primary.
        $this->assertSame($target->id, $moving->fresh()->client_id);
        $this->assertFalse((bool) $moving->fresh()->is_primary);
        $this->assertTrue((bool) $targetPrimary->fresh()->is_primary);
        $this->assertSame(1, Person::where('client_id', $target->id)->where('is_primary', true)->count());
    }

    public function test_move_contact_detaches_cross_client_pivots_and_reports_counts(): void
    {
        $this->configureAiActor();
        $from = Client::factory()->create(['name' => 'From Co']);
        $to = Client::factory()->create(['name' => 'To Co']);
        $person = $this->contact($from);

        // A manual contract link + a device link, both at the OLD client.
        $contract = Contract::create(['client_id' => $from->id, 'name' => 'From Co MSA', 'type' => 'managed', 'start_date' => '2026-01-01']);
        $person->contracts()->attach($contract->id, ['assignment_source' => 'manual', 'assigned_at' => now()]);
        $asset = Asset::factory()->for($from)->create();
        $person->assets()->attach($asset->id, ['assignment_source' => 'manual']);

        $token = $this->token(['move_contact_to_client'], 'chet');
        $response = $this->callTool($token, 'move_contact_to_client', [
            'contact_id' => $person->id,
            'new_client_id' => $to->id,
            'confirm_client_name' => 'To Co',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $person->refresh();
        $this->assertSame($to->id, $person->client_id);
        // The old-client pivots are gone (they'd otherwise point at From Co's contract/device).
        $this->assertSame(0, $person->contracts()->count());
        $this->assertSame(0, $person->assets()->count());

        $result = $this->decodedResult($response);
        $this->assertSame(1, $result['contracts_detached']);
        $this->assertSame(1, $result['assets_detached']);

        // The contract detach leaves a billing-audit trail, like every other
        // contract-assignment change (routed through ContractAssignmentService).
        $this->assertDatabaseHas('contract_activities', [
            'contract_id' => $contract->id,
            'action' => 'assignment_removed',
        ]);
    }

    public function test_move_contact_preserves_links_already_pointing_at_the_target_client(): void
    {
        $this->configureAiActor();
        $from = Client::factory()->create(['name' => 'From Co']);
        $to = Client::factory()->create(['name' => 'To Co']);
        $person = $this->contact($from);

        // One link at the OLD client (cross-client after the move) and one already at
        // the TARGET client (must NOT be detached — the != filter must not over-reach).
        $oldContract = Contract::create(['client_id' => $from->id, 'name' => 'From MSA', 'type' => 'managed', 'start_date' => '2026-01-01']);
        $targetContract = Contract::create(['client_id' => $to->id, 'name' => 'To MSA', 'type' => 'managed', 'start_date' => '2026-01-01']);
        $person->contracts()->attach($oldContract->id, ['assignment_source' => 'manual', 'assigned_at' => now()]);
        $person->contracts()->attach($targetContract->id, ['assignment_source' => 'manual', 'assigned_at' => now()]);

        $token = $this->token(['move_contact_to_client'], 'chet');
        $response = $this->callTool($token, 'move_contact_to_client', [
            'contact_id' => $person->id,
            'new_client_id' => $to->id,
            'confirm_client_name' => 'To Co',
        ]);
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        // Only the old-client link is gone; the target-client link survives; count is exactly 1.
        $this->assertSame([$targetContract->id], $person->fresh()->contracts()->pluck('contracts.id')->all());
        $this->assertSame(1, $this->decodedResult($response)['contracts_detached']);
    }

    public function test_delete_contact_requires_typed_confirm_and_soft_deletes(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create();
        $person = $this->contact($client, ['first_name' => 'Del', 'last_name' => 'Contact']);
        $token = $this->token(['delete_contact'], 'chet');

        // Wrong confirm → refused.
        $bad = $this->callTool($token, 'delete_contact', [
            'contact_id' => $person->id,
            'confirm_contact_name' => 'Wrong Person',
            'reason' => 'Duplicate.',
        ]);
        $bad->assertOk();
        $this->assertTrue((bool) $bad->json('result.isError'));
        $this->assertStringContainsString('confirm_contact_name', (string) $bad->json('result.content.0.text'));
        $this->assertNull($person->fresh()->deleted_at);

        // Correct confirm (full name) → soft-deleted.
        $ok = $this->callTool($token, 'delete_contact', [
            'contact_id' => $person->id,
            'confirm_contact_name' => 'Del Contact',
            'reason' => 'Duplicate contact created in error.',
        ]);
        $ok->assertOk();
        $this->assertFalse((bool) $ok->json('result.isError'), (string) $ok->json('result.content.0.text'));
        $this->assertSoftDeleted('people', ['id' => $person->id]);

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'delete_contact',
            'ticket_id' => null,
            'client_id' => $client->id,
        ]);
    }

    public function test_delete_contact_surfaces_open_ticket_block(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create();
        $person = $this->contact($client, ['first_name' => 'Busy', 'last_name' => 'Contact']);
        Ticket::factory()->for($client)->create(['contact_id' => $person->id, 'status' => TicketStatus::InProgress]);
        $token = $this->token(['delete_contact'], 'chet');

        $response = $this->callTool($token, 'delete_contact', [
            'contact_id' => $person->id,
            'confirm_contact_name' => 'Busy Contact',
            'reason' => 'Cleanup.',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('open ticket', (string) $response->json('result.content.0.text'));
        $this->assertNull($person->fresh()->deleted_at);
    }
}
