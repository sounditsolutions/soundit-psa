<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Support\McpConfig;
use App\Support\McpStaffToken;
use App\Support\McpToolModes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * merge_ticket / merge_asset under the unified staged/immediate convention
 * (operator ruling, 2026-08-25): the canonical tools are action-verb named
 * with a `staged` parameter and per-token mode grants; the propose_merge /
 * propose_asset_merge names retire from the catalog but survive as staged-only
 * call-time aliases, so existing grants and in-flight staged runs are
 * untouched. The immediate lane is new: it executes the same service methods
 * cockpit approval runs (TicketService::mergeTickets / AssetService::
 * mergeAssets) and is only reachable behind the per-tool immediate mode grant.
 */
class McpMergeToolModesTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $tools, string $label = 'opsbot'): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: $label);
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

    /** @return array<string, mixed> */
    private function decodedResult(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    /** @return array{0: Client, 1: Ticket, 2: Ticket} */
    private function ticketPair(): array
    {
        $client = Client::factory()->create();
        $primary = Ticket::factory()->for($client)->create([
            'status' => TicketStatus::InProgress,
            'closed_at' => null,
            'subject' => 'Printer offline',
        ]);
        $secondary = Ticket::factory()->for($client)->create([
            'status' => TicketStatus::InProgress,
            'closed_at' => null,
            'subject' => 'Duplicate printer issue',
        ]);

        return [$client, $primary, $secondary];
    }

    public function test_grant_grammar_maps_the_merge_capabilities(): void
    {
        // Bare legacy alias entries (how every pre-convention token is
        // granted) resolve to the canonical capability in staged-only mode.
        $parsed = McpToolModes::parseGrants(['propose_merge', 'propose_asset_merge']);
        $this->assertContains('merge_ticket', $parsed['tools']);
        $this->assertContains('merge_asset', $parsed['tools']);
        $this->assertSame('staged', $parsed['modes']['merge_ticket']);
        $this->assertSame('staged', $parsed['modes']['merge_asset']);

        // Explicit mode suffixes on the canonical names.
        $parsed = McpToolModes::parseGrants(['merge_ticket:immediate', 'merge_asset:staged']);
        $this->assertSame('immediate', $parsed['modes']['merge_ticket']);
        $this->assertSame('staged', $parsed['modes']['merge_asset']);
    }

    public function test_staged_only_grant_downgrades_merge_ticket_to_a_proposal(): void
    {
        $this->configureAiActor();
        $token = $this->token(['merge_ticket:staged']);
        [, $primary, $secondary] = $this->ticketPair();

        $response = $this->callTool($token, 'merge_ticket', [
            'client_id' => $primary->client_id,
            'primary_ticket_id' => $primary->id,
            'secondary_ticket_id' => $secondary->id,
            'reason' => 'Same printer, same user, same morning.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $result = $this->decodedResult($response);
        $this->assertTrue((bool) ($result['downgraded_to_staged'] ?? false));

        // Held as the same propose_merge run a legacy call produces; nothing merged.
        $run = TechnicianRun::where('ticket_id', $primary->id)->where('action_type', 'propose_merge')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertNull($secondary->fresh()->parent_ticket_id);
    }

    public function test_immediate_grant_merges_tickets_directly_with_audit(): void
    {
        $this->configureAiActor();
        $token = $this->token(['merge_ticket:immediate']);
        [, $primary, $secondary] = $this->ticketPair();

        $response = $this->callTool($token, 'merge_ticket', [
            'client_id' => $primary->client_id,
            'primary_ticket_id' => $primary->id,
            'secondary_ticket_id' => $secondary->id,
            'reason' => 'Same printer, same user, same morning.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $result = $this->decodedResult($response);
        $this->assertTrue((bool) ($result['success'] ?? false));
        $this->assertArrayNotHasKey('downgraded_to_staged', $result);

        $secondary->refresh();
        $this->assertSame($primary->id, $secondary->parent_ticket_id);
        $this->assertSame(TicketStatus::Closed, $secondary->status);

        // Direct execution audits under the canonical action type; no held run.
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'merge_ticket',
            'ticket_id' => $primary->id,
            'result_status' => 'executed',
        ]);
        $this->assertSame(0, TechnicianRun::count());

        // An identical retry is answered idempotently, not as an error.
        $retry = $this->decodedResult($this->callTool($token, 'merge_ticket', [
            'client_id' => $primary->client_id,
            'primary_ticket_id' => $primary->id,
            'secondary_ticket_id' => $secondary->id,
            'reason' => 'Same printer, same user, same morning.',
        ]));
        $this->assertTrue((bool) ($retry['idempotent'] ?? false), 'retry after merge should be idempotent, got: '.json_encode($retry));
    }

    public function test_legacy_propose_merge_alias_still_stages_and_never_executes(): void
    {
        $this->configureAiActor();
        $token = $this->token(['propose_merge']);
        [, $primary, $secondary] = $this->ticketPair();

        // A stray staged=false on the alias must not become an execution.
        $response = $this->callTool($token, 'propose_merge', [
            'client_id' => $primary->client_id,
            'primary_ticket_id' => $primary->id,
            'secondary_ticket_id' => $secondary->id,
            'staged' => false,
            'reason' => 'Legacy client still calls the alias.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $run = TechnicianRun::where('ticket_id', $primary->id)->where('action_type', 'propose_merge')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertNull($secondary->fresh()->parent_ticket_id);
    }

    public function test_staged_only_grant_downgrades_merge_asset_to_a_proposal(): void
    {
        $this->configureAiActor();
        $token = $this->token(['merge_asset:staged']);
        [$client, $ticket] = $this->ticketPair();
        $survivor = Asset::factory()->for($client)->create();
        $duplicate = Asset::factory()->for($client)->create();

        $response = $this->callTool($token, 'merge_asset', [
            'client_id' => $client->id,
            'survivor_asset_id' => $survivor->id,
            'duplicate_asset_id' => $duplicate->id,
            'ticket_id' => $ticket->id,
            'reason' => 'Same serial, re-imaged machine.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $result = $this->decodedResult($response);
        $this->assertTrue((bool) ($result['downgraded_to_staged'] ?? false));

        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'propose_asset_merge')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertNull($duplicate->fresh()->merged_into_asset_id);
    }

    public function test_immediate_grant_merges_assets_directly_with_audit(): void
    {
        $this->configureAiActor();
        $token = $this->token(['merge_asset:immediate']);
        [$client, $ticket] = $this->ticketPair();
        $survivor = Asset::factory()->for($client)->create();
        $duplicate = Asset::factory()->for($client)->create();

        $response = $this->callTool($token, 'merge_asset', [
            'client_id' => $client->id,
            'survivor_asset_id' => $survivor->id,
            'duplicate_asset_id' => $duplicate->id,
            'ticket_id' => $ticket->id,
            'reason' => 'Same serial, re-imaged machine.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $result = $this->decodedResult($response);
        $this->assertTrue((bool) ($result['success'] ?? false));
        $this->assertArrayNotHasKey('downgraded_to_staged', $result);

        $this->assertSame($survivor->id, $duplicate->fresh()->merged_into_asset_id);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'merge_asset',
            'ticket_id' => $ticket->id,
            'result_status' => 'executed',
        ]);
        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_merge_asset_immediate_still_refuses_identity_conflicts(): void
    {
        $this->configureAiActor();
        $token = $this->token(['merge_asset:immediate']);
        [$client, $ticket] = $this->ticketPair();
        $survivor = Asset::factory()->for($client)->create(['halo_id' => 'halo-a']);
        $duplicate = Asset::factory()->for($client)->create(['halo_id' => 'halo-b']);

        $response = $this->callTool($token, 'merge_asset', [
            'client_id' => $client->id,
            'survivor_asset_id' => $survivor->id,
            'duplicate_asset_id' => $duplicate->id,
            'ticket_id' => $ticket->id,
            'reason' => 'Looks like a duplicate.',
        ]);

        $response->assertOk();
        $result = $this->decodedResult($response);
        $this->assertStringContainsString('two live agents is two devices', (string) ($result['error'] ?? ''));
        $this->assertNull($duplicate->fresh()->merged_into_asset_id);
    }

    public function test_tools_list_advertises_canonical_names_and_retires_the_aliases(): void
    {
        User::factory()->create();
        $token = $this->token(['merge_ticket:immediate', 'merge_asset:staged']);

        $tools = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ])
            ->json('result.tools') ?? [];

        $names = array_column($tools, 'name');
        $this->assertContains('merge_ticket', $names);
        $this->assertContains('merge_asset', $names);
        $this->assertNotContains('propose_merge', $names);
        $this->assertNotContains('propose_asset_merge', $names);

        $byName = array_column($tools, null, 'name');
        $this->assertArrayHasKey('staged', $byName['merge_ticket']['inputSchema']['properties'] ?? []);
        $this->assertArrayHasKey('staged', $byName['merge_asset']['inputSchema']['properties'] ?? []);
    }

    /**
     * The advertised mode and the executable lane resolve through ONE point
     * (McpToolModes::effectiveMode()). A token holding no per-tool mode entry —
     * including the legacy full-surface token — is both shown the staged merge
     * surface and refused the immediate lane; wiring effectiveMode() into
     * tools/list alone would advertise an approval-gated merge while the gate
     * still executed an approval-free one.
     */
    public function test_a_token_without_an_explicit_mode_is_denied_the_immediate_merge_lane(): void
    {
        $full = new McpStaffToken(allowedTools: null);

        foreach (['merge_ticket', 'merge_asset'] as $name) {
            $this->assertSame(McpToolModes::MODE_STAGED, McpToolModes::effectiveMode($full, $name));
            $this->assertFalse($full->allowsImmediate($name), "{$name} must not execute immediately without the explicit :immediate grant");
        }

        // Every other stageable capability keeps its legacy full-surface trust.
        $this->assertSame(McpToolModes::MODE_IMMEDIATE, McpToolModes::effectiveMode($full, 'send_email'));
        $this->assertTrue($full->allowsImmediate('send_email'));

        // A scoped token resolves through the same point, unchanged.
        $staged = new McpStaffToken(allowedTools: ['merge_ticket'], toolModes: ['merge_ticket' => McpToolModes::MODE_STAGED]);
        $this->assertFalse($staged->allowsImmediate('merge_ticket'));

        $immediate = new McpStaffToken(allowedTools: ['merge_ticket'], toolModes: ['merge_ticket' => McpToolModes::MODE_IMMEDIATE]);
        $this->assertTrue($immediate->allowsImmediate('merge_ticket'));
    }
}
