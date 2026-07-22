<?php

namespace Tests\Feature\Mcp;

use App\Enums\TicketCategoryChangeSource;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * update_ticket MCP: agents (Chet) set/change a ticket's ITIL taxonomy node
 * (tickets.category_id), mirroring the psa-alzsw human UI (so-0ftg, psa-bk13g).
 *
 * The write reuses TicketService::updateTicket -> TicketObserver, so it stamps
 * tickets.category_source and logs the change like any other category write.
 * The MCP surface has no authenticated web-user, so a Chet-set category stamps
 * source=System — which triage treats as human-owned/protected (see
 * TriageToolExecutor:549 and its companion guard in
 * SetTicketCategoryTaxonomyMappingTest::test_a_system_owned_node_is_never_overwritten_by_triage),
 * i.e. authoritative: triage will not clobber it. System is honestly distinct
 * from the human UI's Staff, and the actor is also recorded in mcp_audit_logs.
 */
class UpdateTicketCategoryMcpTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $tools, string $label = 'chet'): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: $label);
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

    private function ticket(): Ticket
    {
        // The direct action path resolves TechnicianConfig::requiredAiActorUserId().
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        return Ticket::factory()->for(Client::factory()->create())->create(['category_id' => null]);
    }

    private function assertNotError(TestResponse $resp): void
    {
        $resp->assertOk();
        $this->assertFalse((bool) $resp->json('result.isError'), (string) $resp->json('result.content.0.text'));
    }

    public function test_update_ticket_sets_the_taxonomy_category_and_stamps_system_source(): void
    {
        $token = $this->token(['update_ticket']);
        $ticket = $this->ticket();
        $node = TicketCategory::create(['name' => 'Boot failure']);

        $resp = $this->callTool($token, 'update_ticket', [
            'ticket_id' => $ticket->id,
            'category_id' => $node->id,
            'reason' => 'Classified from the reported symptom.',
        ]);

        $this->assertNotError($resp);
        $ticket->refresh();
        $this->assertSame($node->id, $ticket->category_id);
        // No auth web-user on the MCP surface -> System (triage-protected).
        $this->assertSame(TicketCategoryChangeSource::System, $ticket->category_source);
    }

    public function test_update_ticket_can_clear_the_category(): void
    {
        $token = $this->token(['update_ticket']);
        $node = TicketCategory::create(['name' => 'Boot failure']);
        $ticket = $this->ticket();
        $ticket->update(['category_id' => $node->id]);

        $resp = $this->callTool($token, 'update_ticket', [
            'ticket_id' => $ticket->id,
            'category_id' => null,
        ]);

        $this->assertNotError($resp);
        $this->assertNull($ticket->refresh()->category_id);
    }

    public function test_update_ticket_rejects_an_inactive_category(): void
    {
        $token = $this->token(['update_ticket']);
        $ticket = $this->ticket();
        $retired = TicketCategory::create(['name' => 'Retired', 'is_active' => false]);

        $resp = $this->callTool($token, 'update_ticket', [
            'ticket_id' => $ticket->id,
            'category_id' => $retired->id,
        ]);

        $resp->assertOk();
        $this->assertTrue((bool) $resp->json('result.isError'));
        $this->assertNull($ticket->refresh()->category_id); // no write on a rejected payload
    }

    public function test_update_ticket_rejects_a_nonexistent_category(): void
    {
        $token = $this->token(['update_ticket']);
        $ticket = $this->ticket();

        $resp = $this->callTool($token, 'update_ticket', [
            'ticket_id' => $ticket->id,
            'category_id' => 999999,
        ]);

        $resp->assertOk();
        $this->assertTrue((bool) $resp->json('result.isError'));
        $this->assertNull($ticket->refresh()->category_id);
    }

    public function test_the_published_schema_advertises_category_id(): void
    {
        $token = $this->token(['update_ticket']);

        $tools = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ])
            ->json('result.tools') ?? [];

        $update = collect($tools)->firstWhere('name', 'update_ticket');
        $this->assertNotNull($update, 'update_ticket tool not published');
        $this->assertArrayHasKey('category_id', $update['inputSchema']['properties'] ?? []);
    }
}
