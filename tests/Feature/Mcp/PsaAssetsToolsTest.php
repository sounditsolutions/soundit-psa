<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TicketStatus;
use App\Models\Asset;
use App\Models\Client;
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
 * Feature coverage for the P2c PSA write-surface Asset CRUD + asset↔user-link
 * MCP tools (create/update/retire/restore_asset, link/unlink/set_primary_asset_user)
 * in the dormant psa_records group. Mirrors PsaRecordsToolsTest / PsaPeopleToolsTest.
 */
class PsaAssetsToolsTest extends TestCase
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

    private function person(Client $client, array $overrides = []): Person
    {
        return Person::create(array_merge([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Asset',
            'last_name' => 'User',
            'email' => 'asset-user@example.test',
            'is_active' => true,
        ], $overrides));
    }

    public function test_registry_lists_asset_tools_in_psa_records_and_requires_grants(): void
    {
        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('psa_records', $groups);

        $names = array_column($groups['psa_records']['tools'], 'name');
        foreach (['create_asset', 'update_asset', 'retire_asset', 'restore_asset', 'link_asset_user', 'unlink_asset_user', 'set_primary_asset_user'] as $name) {
            $this->assertContains($name, $names);
        }
        // P2a/P2b tools remain.
        $this->assertContains('create_client', $names);
        $this->assertContains('create_contact', $names);

        $legacyNames = collect($this->tools($this->legacyToken()))->pluck('name')->all();
        foreach (['create_asset', 'update_asset', 'retire_asset', 'restore_asset', 'link_asset_user', 'unlink_asset_user', 'set_primary_asset_user'] as $name) {
            $this->assertNotContains($name, $legacyNames);
        }

        $scoped = collect($this->tools($this->token(['create_asset', 'update_asset'], 'chet')))->keyBy('name');
        $this->assertTrue($scoped->has('create_asset'));
        $this->assertTrue($scoped->has('update_asset'));
        $this->assertFalse($scoped->has('retire_asset'));

        // create_asset is parent-scoped — client_id required.
        $createSchema = $scoped['create_asset']['inputSchema'];
        $this->assertArrayHasKey('client_id', $createSchema['properties']);
        $this->assertContains('client_id', $createSchema['required']);
        $this->assertContains('name', $createSchema['required']);
        foreach (['ninja_id', 'tactical_asset_id', 'servosity_backup_password', 'm365_device_id', 'screenconnect_session_id', 'controld_device_id'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $createSchema['properties']);
        }

        // update_asset is asset-scoped — asset_id required, no client_id.
        $updateSchema = $scoped['update_asset']['inputSchema'];
        $this->assertArrayHasKey('asset_id', $updateSchema['properties']);
        $this->assertContains('asset_id', $updateSchema['required']);
        $this->assertArrayNotHasKey('client_id', $updateSchema['properties']);
    }

    public function test_ungranted_and_legacy_tokens_cannot_call_asset_tools(): void
    {
        $client = Client::factory()->create();
        $asset = Asset::factory()->for($client)->create(['name' => 'WS-1']);
        $person = $this->person($client);

        $calls = [
            ['create_asset', ['client_id' => $client->id, 'name' => 'Nope']],
            ['update_asset', ['asset_id' => $asset->id, 'name' => 'Nope']],
            ['retire_asset', ['asset_id' => $asset->id, 'confirm_asset_name' => 'WS-1', 'reason' => 'x']],
            ['restore_asset', ['asset_id' => $asset->id]],
            ['link_asset_user', ['asset_id' => $asset->id, 'person_id' => $person->id]],
            ['unlink_asset_user', ['asset_id' => $asset->id, 'person_id' => $person->id]],
            ['set_primary_asset_user', ['asset_id' => $asset->id, 'person_id' => $person->id]],
        ];

        foreach ([$this->token(['create_ticket'], 'chet'), $this->legacyToken()] as $token) {
            foreach ($calls as [$tool, $arguments]) {
                $response = $this->callTool($token, $tool, $arguments);
                $response->assertOk();
                $this->assertTrue((bool) $response->json('result.isError'), "{$tool} should be denied.");
                $this->assertStringContainsString('not allowed for this token', (string) $response->json('result.content.0.text'));
            }
        }

        $this->assertSame(1, Asset::query()->count());
        $this->assertNull($asset->fresh()->deleted_at);
        $this->assertSame(0, TechnicianActionLog::query()->count());
    }

    public function test_create_asset_requires_client_id_and_creates_with_audit(): void
    {
        $actor = $this->configureAiActor();
        $client = Client::factory()->create();
        $token = $this->token(['create_asset'], 'chet');

        $missing = $this->callTool($token, 'create_asset', ['name' => 'Orphan']);
        $missing->assertOk();
        $this->assertTrue((bool) $missing->json('result.isError'));
        $this->assertStringContainsString('client_id is required', (string) $missing->json('result.content.0.text'));

        $notes = 'Primary workstation in the finance office.';
        $response = $this->callTool($token, 'create_asset', [
            'client_id' => $client->id,
            'name' => 'FIN-WS-07',
            'asset_type' => 'Workstation',
            'hostname' => 'fin-ws-07',
            'os' => 'Windows 11 Pro',
            'ip_address' => '10.0.4.7',
            'notes' => $notes,
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $result = $this->decodedResult($response);
        $asset = Asset::findOrFail($result['asset_id']);
        $this->assertSame($client->id, $asset->client_id);
        $this->assertSame('FIN-WS-07', $asset->name);
        $this->assertSame('fin-ws-07', $asset->hostname);

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'create_asset',
            'result_status' => 'executed',
            'ticket_id' => null,
            'client_id' => $client->id,
            'actor_id' => $actor->id,
            'actor_label' => 'mcp-staff:chet',
        ]);
        $log = TechnicianActionLog::where('action_type', 'create_asset')->firstOrFail();
        $this->assertStringContainsString('[asset#'.$asset->id.']', (string) $log->summary);

        $audit = McpAuditLog::where('tool_name', 'create_asset')->where('status', 'success')->firstOrFail();
        $this->assertSame(mb_strlen($notes), $audit->arguments['notes_length']);
        $this->assertArrayNotHasKey('notes', $audit->arguments);
        $this->assertStringNotContainsString($notes, (string) json_encode($audit->arguments));
    }

    public function test_create_asset_rejects_vendor_and_sync_fields(): void
    {
        $client = Client::factory()->create();
        $token = $this->token(['create_asset'], 'chet');

        foreach ([
            ['ninja_id' => 'NJ-1'],
            ['tactical_asset_id' => 'TA-1'],
            ['servosity_backup_password' => 'secret'],
            ['m365_device_id' => 'm365-1'],
            ['screenconnect_session_id' => 'sc-1'],
            ['controld_device_id' => 'cd-1'],
            ['rmm_online' => true],
        ] as $vendor) {
            $response = $this->callTool($token, 'create_asset', array_merge([
                'client_id' => $client->id,
                'name' => 'Guard',
            ], $vendor));
            $response->assertOk();
            $this->assertTrue((bool) $response->json('result.isError'), 'field '.array_key_first($vendor).' must be rejected');
        }

        $this->assertSame(0, Asset::query()->count());
    }

    public function test_update_asset_is_asset_scoped_and_forbids_client_id(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create();
        $other = Client::factory()->create();
        $asset = Asset::factory()->for($client)->create(['name' => 'Old', 'hostname' => 'old-host']);
        $token = $this->token(['update_asset'], 'chet');

        $ok = $this->callTool($token, 'update_asset', [
            'asset_id' => $asset->id,
            'hostname' => 'new-host',
            'os' => 'Ubuntu 24.04',
        ]);
        $ok->assertOk();
        $this->assertFalse((bool) $ok->json('result.isError'), (string) $ok->json('result.content.0.text'));
        $asset->refresh();
        $this->assertSame('new-host', $asset->hostname);
        $this->assertSame('Ubuntu 24.04', $asset->os);

        $stray = $this->callTool($token, 'update_asset', [
            'asset_id' => $asset->id,
            'client_id' => $other->id,
            'hostname' => 'nope',
        ]);
        $stray->assertOk();
        $this->assertTrue((bool) $stray->json('result.isError'));
        $this->assertStringContainsString('client_id must be omitted', (string) $stray->json('result.content.0.text'));
        $this->assertSame($client->id, $asset->fresh()->client_id);

        $unknown = $this->callTool($token, 'update_asset', ['asset_id' => 999999, 'hostname' => 'ghost']);
        $unknown->assertOk();
        $this->assertTrue((bool) $unknown->json('result.isError'));
        $this->assertStringContainsString('existing asset', (string) $unknown->json('result.content.0.text'));

        // Vendor fields rejected on update too.
        $vendor = $this->callTool($token, 'update_asset', ['asset_id' => $asset->id, 'ninja_id' => 'NJ-9']);
        $vendor->assertOk();
        $this->assertTrue((bool) $vendor->json('result.isError'));
    }

    public function test_retire_asset_requires_typed_confirm_soft_deletes_and_blocks_open_tickets(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create();
        $asset = Asset::factory()->for($client)->create(['name' => 'RETIRE-ME']);
        $token = $this->token(['retire_asset'], 'chet');

        // Wrong confirm → refused.
        $bad = $this->callTool($token, 'retire_asset', ['asset_id' => $asset->id, 'confirm_asset_name' => 'WRONG', 'reason' => 'x']);
        $bad->assertOk();
        $this->assertTrue((bool) $bad->json('result.isError'));
        $this->assertStringContainsString('confirm_asset_name', (string) $bad->json('result.content.0.text'));
        $this->assertNull($asset->fresh()->deleted_at);

        // Open ticket → service block surfaced.
        $ticket = Ticket::factory()->for($client)->create(['status' => TicketStatus::InProgress]);
        $ticket->assets()->attach($asset->id);
        $blocked = $this->callTool($token, 'retire_asset', ['asset_id' => $asset->id, 'confirm_asset_name' => 'RETIRE-ME', 'reason' => 'x']);
        $blocked->assertOk();
        $this->assertTrue((bool) $blocked->json('result.isError'));
        $this->assertStringContainsString('open ticket', (string) $blocked->json('result.content.0.text'));
        $this->assertNull($asset->fresh()->deleted_at);

        // Resolve the ticket, then retire succeeds (soft-delete).
        $ticket->update(['status' => TicketStatus::Resolved]);
        $ok = $this->callTool($token, 'retire_asset', ['asset_id' => $asset->id, 'confirm_asset_name' => 'RETIRE-ME', 'reason' => 'Offboarded.']);
        $ok->assertOk();
        $this->assertFalse((bool) $ok->json('result.isError'), (string) $ok->json('result.content.0.text'));
        $this->assertSoftDeleted('assets', ['id' => $asset->id]);

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'retire_asset',
            'ticket_id' => null,
            'client_id' => $client->id,
        ]);
    }

    public function test_restore_asset_reactivates_a_retired_asset(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create();
        $asset = Asset::factory()->for($client)->create(['name' => 'BACK', 'is_active' => false]);
        $asset->delete();
        $this->assertSoftDeleted('assets', ['id' => $asset->id]);
        $token = $this->token(['restore_asset'], 'chet');

        $response = $this->callTool($token, 'restore_asset', ['asset_id' => $asset->id]);
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $fresh = Asset::withTrashed()->findOrFail($asset->id);
        $this->assertNull($fresh->deleted_at);
        $this->assertTrue((bool) $fresh->is_active);

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'restore_asset',
            'ticket_id' => null,
            'client_id' => $client->id,
        ]);
    }

    public function test_link_asset_user_enforces_same_client_and_dedups(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create();
        $other = Client::factory()->create();
        $asset = Asset::factory()->for($client)->create(['name' => 'LINK-WS']);
        $sameClientPerson = $this->person($client, ['email' => 'same@example.test']);
        $otherClientPerson = $this->person($other, ['email' => 'other@example.test']);
        $token = $this->token(['link_asset_user'], 'chet');

        // Cross-client person → rejected, no link.
        $cross = $this->callTool($token, 'link_asset_user', ['asset_id' => $asset->id, 'person_id' => $otherClientPerson->id]);
        $cross->assertOk();
        $this->assertTrue((bool) $cross->json('result.isError'));
        $this->assertSame(0, $asset->users()->count());

        // Same-client person → linked (manual, non-primary).
        $ok = $this->callTool($token, 'link_asset_user', ['asset_id' => $asset->id, 'person_id' => $sameClientPerson->id]);
        $ok->assertOk();
        $this->assertFalse((bool) $ok->json('result.isError'), (string) $ok->json('result.content.0.text'));
        $this->assertTrue($asset->users()->where('person_id', $sameClientPerson->id)->exists());
        $pivot = $asset->users()->where('person_id', $sameClientPerson->id)->first()->pivot;
        $this->assertSame('manual', $pivot->assignment_source);
        $this->assertFalse((bool) $pivot->is_primary);

        // Re-link → idempotent, still exactly one link.
        $again = $this->callTool($token, 'link_asset_user', ['asset_id' => $asset->id, 'person_id' => $sameClientPerson->id]);
        $again->assertOk();
        $this->assertFalse((bool) $again->json('result.isError'));
        $this->assertSame(1, $asset->users()->count());

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'link_asset_user',
            'ticket_id' => null,
            'client_id' => $client->id,
        ]);
    }

    public function test_set_primary_asset_user_requires_link_and_demotes_prior(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create();
        $asset = Asset::factory()->for($client)->create(['name' => 'PRIM-WS']);
        $p1 = $this->person($client, ['email' => 'p1@example.test']);
        $p2 = $this->person($client, ['email' => 'p2@example.test']);
        $token = $this->token(['link_asset_user', 'set_primary_asset_user', 'unlink_asset_user'], 'chet');

        // Setting primary before linking → error.
        $unlinked = $this->callTool($token, 'set_primary_asset_user', ['asset_id' => $asset->id, 'person_id' => $p1->id]);
        $unlinked->assertOk();
        $this->assertTrue((bool) $unlinked->json('result.isError'));
        $this->assertStringContainsString('not linked', (string) $unlinked->json('result.content.0.text'));

        // Link both, promote p1, then promote p2 → p2 primary, p1 demoted.
        $this->callTool($token, 'link_asset_user', ['asset_id' => $asset->id, 'person_id' => $p1->id]);
        $this->callTool($token, 'link_asset_user', ['asset_id' => $asset->id, 'person_id' => $p2->id]);
        $this->callTool($token, 'set_primary_asset_user', ['asset_id' => $asset->id, 'person_id' => $p1->id]);
        $promote = $this->callTool($token, 'set_primary_asset_user', ['asset_id' => $asset->id, 'person_id' => $p2->id]);
        $promote->assertOk();
        $this->assertFalse((bool) $promote->json('result.isError'), (string) $promote->json('result.content.0.text'));

        $this->assertTrue((bool) $asset->users()->where('person_id', $p2->id)->first()->pivot->is_primary);
        $this->assertFalse((bool) $asset->users()->where('person_id', $p1->id)->first()->pivot->is_primary);
        $this->assertSame(1, $asset->users()->wherePivot('is_primary', true)->count());

        // Unlink p2 removes the link.
        $unlink = $this->callTool($token, 'unlink_asset_user', ['asset_id' => $asset->id, 'person_id' => $p2->id]);
        $unlink->assertOk();
        $this->assertFalse((bool) $unlink->json('result.isError'), (string) $unlink->json('result.content.0.text'));
        $this->assertFalse($asset->users()->where('person_id', $p2->id)->exists());
    }

    public function test_asset_scoped_tool_audits_a_client_less_asset_with_null_client(): void
    {
        // Assets are client-nullable; the audit must record client_id NULL, not 0
        // (which would FK-violate) — this guards the auditEntityExecution ?int widening.
        $this->configureAiActor();
        $asset = Asset::factory()->create(['client_id' => null, 'name' => 'ORPHAN-WS']);
        $token = $this->token(['update_asset'], 'chet');

        $response = $this->callTool($token, 'update_asset', ['asset_id' => $asset->id, 'hostname' => 'orphan-host']);
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertSame('orphan-host', $asset->fresh()->hostname);

        $log = TechnicianActionLog::where('action_type', 'update_asset')->firstOrFail();
        $this->assertNull($log->client_id);
        $this->assertNull($log->ticket_id);
        $this->assertStringContainsString('[asset#'.$asset->id.']', (string) $log->summary);
    }
}
