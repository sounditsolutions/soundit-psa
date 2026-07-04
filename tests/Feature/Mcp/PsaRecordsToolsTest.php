<?php

namespace Tests\Feature\Mcp;

use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\McpAuditLog;
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
 * Feature coverage for the P2a PSA write-surface Client CRUD MCP tools
 * (create_client, update_client, update_client_site_notes, delete_client) in
 * the dormant `psa_records` group. Mirrors PsaActionToolsTest conventions.
 */
class PsaRecordsToolsTest extends TestCase
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

    public function test_registry_and_runtime_require_explicit_grants_for_psa_records_tools(): void
    {
        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('psa_records', $groups);
        $this->assertTrue($groups['psa_records']['sensitive']);

        $names = array_column($groups['psa_records']['tools'], 'name');
        foreach (['create_client', 'update_client', 'update_client_site_notes', 'delete_client'] as $name) {
            $this->assertContains($name, $names);
        }

        // Dormant by default: a token with no explicit grant cannot see them.
        $legacyNames = collect($this->tools($this->legacyToken()))->pluck('name')->all();
        foreach (['create_client', 'update_client', 'update_client_site_notes', 'delete_client'] as $name) {
            $this->assertNotContains($name, $legacyNames);
        }

        // A granted token sees exactly what it was granted.
        $scoped = collect($this->tools($this->token(['create_client', 'update_client'], 'chet')))->keyBy('name');
        $this->assertTrue($scoped->has('create_client'));
        $this->assertTrue($scoped->has('update_client'));
        $this->assertFalse($scoped->has('delete_client'));
        $this->assertFalse($scoped->has('update_client_site_notes'));

        // create_client is a GLOBAL write — no client_id, name required.
        $createSchema = $scoped['create_client']['inputSchema'];
        $this->assertArrayNotHasKey('client_id', $createSchema['properties']);
        $this->assertContains('name', $createSchema['required']);
        $this->assertArrayNotHasKey('site_notes', $createSchema['properties']);
        $this->assertArrayNotHasKey('credentials', $createSchema['properties']);
        $this->assertArrayNotHasKey('stage', $createSchema['properties']);

        // update_client is entity-scoped — client_id is a required target, and
        // site notes / credentials are handled by their own tools.
        $updateSchema = $scoped['update_client']['inputSchema'];
        $this->assertArrayHasKey('client_id', $updateSchema['properties']);
        $this->assertContains('client_id', $updateSchema['required']);
        $this->assertArrayNotHasKey('site_notes', $updateSchema['properties']);
        $this->assertArrayNotHasKey('credentials', $updateSchema['properties']);
    }

    public function test_ungranted_token_cannot_call_psa_records_tools(): void
    {
        $client = Client::factory()->create();

        $calls = [
            ['create_client', ['name' => 'Nope Inc']],
            ['update_client', ['client_id' => $client->id, 'name' => 'Nope']],
            ['update_client_site_notes', ['client_id' => $client->id, 'site_notes' => 'nope']],
            ['delete_client', ['client_id' => $client->id, 'confirm_client_name' => $client->name, 'reason' => 'nope']],
        ];

        // Both a scoped token that grants a *different* sensitive tool AND a
        // full-surface legacy token (allowedTools = null) must be denied — the
        // psa_records group is grant-gated, never available by default.
        $tokens = [
            $this->token(['create_ticket'], 'chet'),
            $this->legacyToken(),
        ];

        foreach ($tokens as $token) {
            foreach ($calls as [$tool, $arguments]) {
                $response = $this->callTool($token, $tool, $arguments);
                $response->assertOk();
                $this->assertTrue((bool) $response->json('result.isError'), "{$tool} should be denied.");
                $this->assertStringContainsString('not allowed for this token', (string) $response->json('result.content.0.text'));
            }
        }

        // Nothing was created, changed, or deleted.
        $this->assertSame(1, Client::query()->count());
        $this->assertNull($client->fresh()->deleted_at);
        $this->assertSame(0, TechnicianActionLog::query()->count());
    }

    public function test_granted_token_creates_client_with_audit_and_ai_actor(): void
    {
        $actor = $this->configureAiActor();
        $token = $this->token(['create_client'], 'chet');
        $notes = 'Prospect converted from referral partner.';

        $response = $this->callTool($token, 'create_client', [
            'name' => 'Acme Widgets',
            'email' => 'ops@acme.test',
            'phone' => '5035551234',
            'city' => 'Portland',
            'notes' => $notes,
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $result = $this->decodedResult($response);
        $client = Client::findOrFail($result['client_id']);
        $this->assertSame('Acme Widgets', $client->name);
        $this->assertSame('ops@acme.test', $client->email);
        $this->assertSame('Portland', $client->city);

        // Entity audit row: ticket_id null, client_id = new client, AI actor.
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'create_client',
            'result_status' => 'executed',
            'ticket_id' => null,
            'client_id' => $client->id,
            'actor_id' => $actor->id,
            'actor_label' => 'mcp-staff:chet',
            'approver_user_id' => null,
        ]);

        // mcp_audit_logs redaction: notes reduced to a length only.
        $audit = McpAuditLog::where('tool_name', 'create_client')->firstOrFail();
        $this->assertSame('success', $audit->status);
        $this->assertSame('Acme Widgets', $audit->arguments['name']);
        $this->assertSame(mb_strlen($notes), $audit->arguments['notes_length']);
        $this->assertArrayNotHasKey('notes', $audit->arguments);
        $this->assertStringNotContainsString($notes, (string) json_encode($audit->arguments));

        // Entity audit row carries the [client#id] summary tag and a sha256 content hash.
        $log = TechnicianActionLog::where('action_type', 'create_client')->firstOrFail();
        $this->assertStringContainsString('[client#'.$client->id.']', (string) $log->summary);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $log->content_hash);
    }

    public function test_create_client_requires_name(): void
    {
        $token = $this->token(['create_client'], 'chet');

        $response = $this->callTool($token, 'create_client', ['email' => 'no-name@example.test']);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('name', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, Client::query()->count());
    }

    public function test_create_client_rejects_non_allowlisted_fields(): void
    {
        $token = $this->token(['create_client'], 'chet');

        $response = $this->callTool($token, 'create_client', [
            'name' => 'Sneaky Co',
            'stage' => 'active',
            'ninja_org_id' => 42,
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertSame(0, Client::query()->count());
    }

    public function test_create_client_rejects_supplied_client_id(): void
    {
        $client = Client::factory()->create();
        $token = $this->token(['create_client'], 'chet');

        $response = $this->callTool($token, 'create_client', [
            'client_id' => $client->id,
            'name' => 'Global Only',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('client_id must be omitted', (string) $response->json('result.content.0.text'));
        $this->assertSame(1, Client::query()->count());
    }

    public function test_granted_token_updates_client_with_audit(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create(['name' => 'Old Name', 'city' => 'Salem']);
        $token = $this->token(['update_client'], 'chet');

        $response = $this->callTool($token, 'update_client', [
            'client_id' => $client->id,
            'name' => 'New Name',
            'city' => 'Eugene',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $client->refresh();
        $this->assertSame('New Name', $client->name);
        $this->assertSame('Eugene', $client->city);

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'update_client',
            'result_status' => 'executed',
            'ticket_id' => null,
            'client_id' => $client->id,
            'actor_label' => 'mcp-staff:chet',
        ]);
    }

    public function test_update_client_requires_client_id(): void
    {
        $token = $this->token(['update_client'], 'chet');

        $response = $this->callTool($token, 'update_client', ['name' => 'Orphan']);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('client_id is required', (string) $response->json('result.content.0.text'));
    }

    public function test_update_client_rejects_unknown_client(): void
    {
        $token = $this->token(['update_client'], 'chet');

        $response = $this->callTool($token, 'update_client', ['client_id' => 999999, 'name' => 'Ghost']);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('not found', (string) $response->json('result.content.0.text'));
    }

    public function test_update_client_rejects_site_notes_and_credentials(): void
    {
        $client = Client::factory()->create();
        $token = $this->token(['update_client'], 'chet');

        foreach (['site_notes' => 'secret site notes', 'credentials' => 'p@ssw0rd'] as $field => $value) {
            $response = $this->callTool($token, 'update_client', [
                'client_id' => $client->id,
                $field => $value,
            ]);
            $response->assertOk();
            $this->assertTrue((bool) $response->json('result.isError'), "{$field} should be rejected.");
        }
    }

    public function test_update_client_site_notes_writes_and_audits(): void
    {
        $actor = $this->configureAiActor();
        $client = Client::factory()->create();
        $token = $this->token(['update_client_site_notes'], 'chet');
        $siteNotes = 'Rack is in the east closet. Gateway 10.0.0.1.';

        $response = $this->callTool($token, 'update_client_site_notes', [
            'client_id' => $client->id,
            'site_notes' => $siteNotes,
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $client->refresh();
        $this->assertSame($siteNotes, $client->site_notes);
        $this->assertNotNull($client->site_notes_html);
        $this->assertSame($actor->id, $client->site_notes_updated_by);

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'update_client_site_notes',
            'result_status' => 'executed',
            'ticket_id' => null,
            'client_id' => $client->id,
            'actor_label' => 'mcp-staff:chet',
        ]);

        // Redaction: the site notes body is reduced to a length only.
        $audit = McpAuditLog::where('tool_name', 'update_client_site_notes')->firstOrFail();
        $this->assertArrayNotHasKey('site_notes', $audit->arguments);
        $this->assertSame(mb_strlen($siteNotes), $audit->arguments['site_notes_length']);
        $this->assertStringNotContainsString('east closet', (string) json_encode($audit->arguments));
    }

    public function test_update_client_site_notes_surfaces_stale_write_conflict(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create();
        // Seed an existing edit so the optimistic-concurrency guard has a timestamp.
        $client->update(['site_notes' => 'v1', 'site_notes_updated_at' => now()]);
        $token = $this->token(['update_client_site_notes'], 'chet');

        $response = $this->callTool($token, 'update_client_site_notes', [
            'client_id' => $client->id,
            'site_notes' => 'v2',
            'expected_updated_at' => now()->subDay()->toISOString(),
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('while you were editing', (string) $response->json('result.content.0.text'));

        $this->assertSame('v1', $client->fresh()->site_notes);
    }

    public function test_update_client_site_notes_rejects_malformed_expected_updated_at(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create();
        $client->update(['site_notes' => 'v1', 'site_notes_updated_at' => now()]);
        $token = $this->token(['update_client_site_notes'], 'chet');

        $response = $this->callTool($token, 'update_client_site_notes', [
            'client_id' => $client->id,
            'site_notes' => 'v2',
            'expected_updated_at' => 'not-a-real-timestamp',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('valid ISO-8601 timestamp', (string) $response->json('result.content.0.text'));
        $this->assertSame('v1', $client->fresh()->site_notes);
    }

    public function test_delete_client_requires_typed_confirm_and_soft_deletes(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create(['name' => 'Deletable Co']);
        $token = $this->token(['delete_client'], 'chet');

        // Wrong confirmation → refused, not deleted.
        $bad = $this->callTool($token, 'delete_client', [
            'client_id' => $client->id,
            'confirm_client_name' => 'Wrong Name',
            'reason' => 'Duplicate account.',
        ]);
        $bad->assertOk();
        $this->assertTrue((bool) $bad->json('result.isError'));
        $this->assertStringContainsString('confirm_client_name', (string) $bad->json('result.content.0.text'));
        $this->assertNull($client->fresh()->deleted_at);

        // Correct confirmation → soft-deleted with an audit row.
        $ok = $this->callTool($token, 'delete_client', [
            'client_id' => $client->id,
            'confirm_client_name' => 'Deletable Co',
            'reason' => 'Duplicate account created in error.',
        ]);
        $ok->assertOk();
        $this->assertFalse((bool) $ok->json('result.isError'), (string) $ok->json('result.content.0.text'));
        $this->assertSoftDeleted('clients', ['id' => $client->id]);

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'delete_client',
            'result_status' => 'executed',
            'ticket_id' => null,
            'client_id' => $client->id,
            'actor_label' => 'mcp-staff:chet',
        ]);
    }

    public function test_delete_client_surfaces_open_ticket_block(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create(['name' => 'Busy Co']);
        Ticket::factory()->for($client)->create(['status' => TicketStatus::InProgress]);
        $token = $this->token(['delete_client'], 'chet');

        $response = $this->callTool($token, 'delete_client', [
            'client_id' => $client->id,
            'confirm_client_name' => 'Busy Co',
            'reason' => 'Cleanup.',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('open ticket', (string) $response->json('result.content.0.text'));
        $this->assertNull($client->fresh()->deleted_at);
    }
}
