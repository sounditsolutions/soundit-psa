<?php

namespace Tests\Feature\Mcp;

use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Triage\TriageToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * psa-717bn.2 (category) + psa-ti6n9 (pagination): the staff ticket-list MCP
 * tools must (a) wrap their rows in the uniform {tickets, pagination} envelope
 * with a settable limit/offset and a has_more/total signal, and (b) emit the
 * ITIL category (id + full path) per row. Covers the Assistant queue tools and
 * the Triage client-scoped tools (the latter also serves the TechnicianAgent
 * via delegation).
 */
class TicketListPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function hardwareLaptop(): TicketCategory
    {
        $root = TicketCategory::create(['name' => 'Hardware']);

        return TicketCategory::create(['name' => 'Laptop', 'parent_id' => $root->id]);
    }

    public function test_list_open_tickets_returns_the_pagination_envelope(): void
    {
        Ticket::factory()->count(3)->create(['status' => 'in_progress']);

        $result = (new AssistantToolExecutor)->execute('list_open_tickets', []);

        $this->assertArrayHasKey('tickets', $result);
        $this->assertArrayHasKey('pagination', $result);
        $this->assertSame(
            ['total', 'limit', 'offset', 'returned', 'has_more'],
            array_keys($result['pagination'])
        );
        $this->assertSame(20, $result['pagination']['limit']);   // uniform default
        $this->assertSame(0, $result['pagination']['offset']);
        $this->assertSame(3, $result['pagination']['total']);
        $this->assertSame(3, $result['pagination']['returned']);
        $this->assertFalse($result['pagination']['has_more']);
    }

    public function test_list_open_tickets_offset_pages_without_overlap(): void
    {
        // Distinct priorities give a deterministic order so offset paging is stable.
        Ticket::factory()->create(['status' => 'in_progress', 'priority' => 'p1']);
        Ticket::factory()->create(['status' => 'in_progress', 'priority' => 'p2']);
        Ticket::factory()->create(['status' => 'in_progress', 'priority' => 'p3']);

        $page1 = (new AssistantToolExecutor)->execute('list_open_tickets', ['limit' => 2, 'offset' => 0]);
        $page2 = (new AssistantToolExecutor)->execute('list_open_tickets', ['limit' => 2, 'offset' => 2]);

        $this->assertCount(2, $page1['tickets']);
        $this->assertTrue($page1['pagination']['has_more']);

        $this->assertCount(1, $page2['tickets']);
        $this->assertFalse($page2['pagination']['has_more']);

        $ids1 = array_column($page1['tickets'], 'id');
        $ids2 = array_column($page2['tickets'], 'id');
        $this->assertEmpty(array_intersect($ids1, $ids2), 'a second page must not repeat first-page rows');
    }

    public function test_limit_is_hard_capped_at_100(): void
    {
        $result = (new AssistantToolExecutor)->execute('list_open_tickets', ['limit' => 5000]);

        $this->assertSame(100, $result['pagination']['limit']);
    }

    public function test_rows_carry_the_itil_category_path(): void
    {
        $leaf = $this->hardwareLaptop();
        $ticket = Ticket::factory()->create(['status' => 'in_progress', 'category_id' => $leaf->id]);

        $result = (new AssistantToolExecutor)->execute('list_open_tickets', []);
        $row = collect($result['tickets'])->firstWhere('id', $ticket->id);

        $this->assertNotNull($row);
        $this->assertSame($leaf->id, $row['category_id']);
        $this->assertSame('Hardware / Laptop', $row['category_path']);
    }

    public function test_uncategorized_row_has_null_category_fields(): void
    {
        $ticket = Ticket::factory()->create(['status' => 'in_progress', 'category_id' => null]);

        $result = (new AssistantToolExecutor)->execute('list_open_tickets', []);
        $row = collect($result['tickets'])->firstWhere('id', $ticket->id);

        $this->assertArrayHasKey('category_id', $row);
        $this->assertNull($row['category_id']);
        $this->assertNull($row['category_path']);
    }

    public function test_list_open_tickets_does_not_n_plus_one_across_a_page(): void
    {
        // The raised page cap (100) makes a per-row lazy-load of client/assignee/
        // category expensive, so those must be eager-loaded. Prove the query count
        // is bounded regardless of how many rows the page holds.
        $root = TicketCategory::create(['name' => 'Network']);
        $leaf = TicketCategory::create(['name' => 'VPN', 'parent_id' => $root->id]);
        for ($i = 0; $i < 5; $i++) {
            Ticket::factory()->create([
                'status' => 'in_progress',
                'client_id' => Client::factory()->create()->id,
                'assignee_id' => User::factory()->create()->id,
                'category_id' => $leaf->id,
            ]);
        }

        DB::enableQueryLog();
        $result = (new AssistantToolExecutor)->execute('list_open_tickets', []);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(5, $result['tickets']);
        // count + page fetch + a fixed set of eager-load queries (client, assignee,
        // categoryNode chain) — NOT one-per-row. A per-row N+1 on 5 rows would blow
        // well past this; eager-loaded it stays flat as rows grow.
        $this->assertLessThan(12, $queries, "query count {$queries} suggests an N+1 across the page");
    }

    public function test_triage_search_tickets_returns_envelope_with_category(): void
    {
        $client = Client::factory()->create();
        $current = Ticket::factory()->create(['client_id' => $client->id]);
        $leaf = $this->hardwareLaptop();
        $match = Ticket::factory()->create([
            'client_id' => $client->id,
            'subject' => 'zzsearchprobe printer jam',
            'category_id' => $leaf->id,
        ]);

        $result = (new TriageToolExecutor($current))->execute('search_tickets', ['query' => 'zzsearchprobe']);

        $this->assertArrayHasKey('tickets', $result);
        $this->assertArrayHasKey('pagination', $result);
        $row = collect($result['tickets'])->firstWhere('id', $match->id);
        $this->assertNotNull($row);
        $this->assertSame('Hardware / Laptop', $row['category_path']);
    }

    public function test_triage_list_client_tickets_returns_envelope_and_keeps_category(): void
    {
        $client = Client::factory()->create();
        $current = Ticket::factory()->create(['client_id' => $client->id]);
        $leaf = $this->hardwareLaptop();
        $open = Ticket::factory()->create([
            'client_id' => $client->id,
            'subject' => 'monitor flicker',
            'status' => 'in_progress',
            'category_id' => $leaf->id,
        ]);

        $result = (new TriageToolExecutor($current))->execute('list_client_tickets', ['status' => 'open']);

        $this->assertArrayHasKey('tickets', $result);
        $this->assertArrayHasKey('pagination', $result);
        $row = collect($result['tickets'])->firstWhere('id', $open->id);
        $this->assertNotNull($row);
        $this->assertSame('Hardware / Laptop', $row['category_path']);
    }
}
