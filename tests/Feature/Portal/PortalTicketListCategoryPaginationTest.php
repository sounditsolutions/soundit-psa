<?php

namespace Tests\Feature\Portal;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Services\Mcp\PortalMcpToolExecutor;
use App\Services\Portal\PortalChatbotToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * psa-717bn.3 (portal category) + psa-ti6n9 (portal pagination): the portal
 * ticket-list tools — chatbot list_tickets and portal-MCP list_my_open_tickets /
 * search_my_tickets — must (a) wrap rows in the uniform {tickets, pagination}
 * envelope (settable limit/offset, hard max 100, has_more/total) and (b) emit
 * the ITIL category path per row. get_ticket / get_my_ticket carry the category
 * too. Crucially, none of this may widen the client scope: the portal boundary
 * is ours to enforce client-side, so paging over a raised cap must still return
 * only the caller's own client's tickets.
 */
class PortalTicketListCategoryPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function hardwareLaptop(): TicketCategory
    {
        $root = TicketCategory::create(['name' => 'Hardware']);

        return TicketCategory::create(['name' => 'Laptop', 'parent_id' => $root->id]);
    }

    private function person(Client $client, bool $companyWide = true): Person
    {
        return Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Pat',
            'last_name' => 'Portal',
            'email' => 'pat'.uniqid().'@example.test',
            'is_active' => true,
            'portal_enabled' => true,
            'company_wide_access' => $companyWide,
            'password' => 'secret-portal-pw',
        ]);
    }

    // ── Portal MCP: list_my_open_tickets ──

    public function test_mcp_list_my_open_tickets_returns_pagination_envelope(): void
    {
        $client = Client::create(['name' => 'Acme']);
        $person = $this->person($client);
        Ticket::factory()->count(3)->create(['client_id' => $client->id, 'status' => 'in_progress']);

        $result = (new PortalMcpToolExecutor($person))->execute('list_my_open_tickets', []);

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

    public function test_mcp_list_my_open_tickets_offset_pages_without_overlap(): void
    {
        $client = Client::create(['name' => 'Acme']);
        $person = $this->person($client);
        Ticket::factory()->create(['client_id' => $client->id, 'status' => 'in_progress', 'priority' => 'p1']);
        Ticket::factory()->create(['client_id' => $client->id, 'status' => 'in_progress', 'priority' => 'p2']);
        Ticket::factory()->create(['client_id' => $client->id, 'status' => 'in_progress', 'priority' => 'p3']);

        $page1 = (new PortalMcpToolExecutor($person))->execute('list_my_open_tickets', ['limit' => 2, 'offset' => 0]);
        $page2 = (new PortalMcpToolExecutor($person))->execute('list_my_open_tickets', ['limit' => 2, 'offset' => 2]);

        $this->assertCount(2, $page1['tickets']);
        $this->assertTrue($page1['pagination']['has_more']);
        $this->assertCount(1, $page2['tickets']);
        $this->assertFalse($page2['pagination']['has_more']);

        $ids1 = array_column($page1['tickets'], 'id');
        $ids2 = array_column($page2['tickets'], 'id');
        $this->assertEmpty(array_intersect($ids1, $ids2), 'a second page must not repeat first-page rows');
    }

    public function test_mcp_limit_is_hard_capped_at_100(): void
    {
        $client = Client::create(['name' => 'Acme']);
        $person = $this->person($client);

        $result = (new PortalMcpToolExecutor($person))->execute('list_my_open_tickets', ['limit' => 5000]);

        $this->assertSame(100, $result['pagination']['limit']);
    }

    public function test_mcp_rows_carry_category_path(): void
    {
        $client = Client::create(['name' => 'Acme']);
        $person = $this->person($client);
        $leaf = $this->hardwareLaptop();
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'status' => 'in_progress', 'category_id' => $leaf->id]);

        $result = (new PortalMcpToolExecutor($person))->execute('list_my_open_tickets', []);
        $row = collect($result['tickets'])->firstWhere('id', $ticket->id);

        $this->assertNotNull($row);
        $this->assertSame('Hardware / Laptop', $row['category_path']);
    }

    public function test_mcp_uncategorized_row_has_null_category(): void
    {
        $client = Client::create(['name' => 'Acme']);
        $person = $this->person($client);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'status' => 'in_progress', 'category_id' => null]);

        $result = (new PortalMcpToolExecutor($person))->execute('list_my_open_tickets', []);
        $row = collect($result['tickets'])->firstWhere('id', $ticket->id);

        $this->assertArrayHasKey('category_path', $row);
        $this->assertNull($row['category_path']);
    }

    public function test_mcp_list_never_crosses_client_scope_through_pagination(): void
    {
        $mine = Client::create(['name' => 'Mine']);
        $other = Client::create(['name' => 'Other']);
        $person = $this->person($mine);
        Ticket::factory()->create(['client_id' => $mine->id, 'status' => 'in_progress', 'subject' => 'mine open ticket']);
        Ticket::factory()->create(['client_id' => $other->id, 'status' => 'in_progress', 'subject' => 'SECRET other client ticket']);

        // A raised cap must not become a hole in the client boundary.
        $result = (new PortalMcpToolExecutor($person))->execute('list_my_open_tickets', ['limit' => 100]);

        $subjects = array_column($result['tickets'], 'subject');
        $this->assertContains('mine open ticket', $subjects);
        $this->assertNotContains('SECRET other client ticket', $subjects);
        $this->assertSame(1, $result['pagination']['total']);
    }

    public function test_mcp_no_n_plus_one_across_a_page(): void
    {
        $client = Client::create(['name' => 'Acme']);
        $person = $this->person($client);
        $root = TicketCategory::create(['name' => 'Network']);
        $leaf = TicketCategory::create(['name' => 'VPN', 'parent_id' => $root->id]);
        for ($i = 0; $i < 5; $i++) {
            Ticket::factory()->create(['client_id' => $client->id, 'status' => 'in_progress', 'category_id' => $leaf->id]);
        }

        DB::enableQueryLog();
        $result = (new PortalMcpToolExecutor($person))->execute('list_my_open_tickets', []);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(5, $result['tickets']);
        // count + page fetch + the categoryNode ancestor chain — NOT one-per-row.
        $this->assertLessThan(10, $queries, "query count {$queries} suggests an N+1 across the page");
    }

    // ── Portal MCP: search_my_tickets ──

    public function test_mcp_search_my_tickets_returns_envelope_with_category(): void
    {
        $client = Client::create(['name' => 'Acme']);
        $person = $this->person($client);
        $leaf = $this->hardwareLaptop();
        $match = Ticket::factory()->create([
            'client_id' => $client->id,
            'subject' => 'zzsearchprobe printer jam',
            'category_id' => $leaf->id,
        ]);

        $result = (new PortalMcpToolExecutor($person))->execute('search_my_tickets', ['query' => 'zzsearchprobe']);

        $this->assertArrayHasKey('tickets', $result);
        $this->assertArrayHasKey('pagination', $result);
        $row = collect($result['tickets'])->firstWhere('id', $match->id);
        $this->assertNotNull($row);
        $this->assertSame('Hardware / Laptop', $row['category_path']);
    }

    // ── Portal MCP: get_my_ticket ──

    public function test_mcp_get_my_ticket_carries_category_path(): void
    {
        $client = Client::create(['name' => 'Acme']);
        $person = $this->person($client);
        $leaf = $this->hardwareLaptop();
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'category_id' => $leaf->id]);

        $result = (new PortalMcpToolExecutor($person))->execute('get_my_ticket', ['ticket_id' => $ticket->id]);

        $this->assertArrayHasKey('category_path', $result);
        $this->assertSame('Hardware / Laptop', $result['category_path']);
    }

    // ── Portal chatbot: list_tickets ──

    public function test_chatbot_list_tickets_returns_envelope_with_category(): void
    {
        $client = Client::create(['name' => 'Acme']);
        $leaf = $this->hardwareLaptop();
        Ticket::factory()->count(3)->create(['client_id' => $client->id, 'status' => 'in_progress', 'category_id' => $leaf->id]);

        $result = (new PortalChatbotToolExecutor(clientId: $client->id, companyWideAccess: true))
            ->execute('list_tickets', ['status' => 'open']);

        $this->assertArrayHasKey('tickets', $result);
        $this->assertArrayHasKey('pagination', $result);
        $this->assertSame(20, $result['pagination']['limit']);
        $this->assertSame(3, $result['pagination']['total']);
        $this->assertSame('Hardware / Laptop', $result['tickets'][0]['category_path']);
    }

    public function test_chatbot_list_tickets_offset_pages_without_overlap(): void
    {
        $client = Client::create(['name' => 'Acme']);
        Ticket::factory()->create(['client_id' => $client->id, 'status' => 'in_progress', 'priority' => 'p1']);
        Ticket::factory()->create(['client_id' => $client->id, 'status' => 'in_progress', 'priority' => 'p2']);
        Ticket::factory()->create(['client_id' => $client->id, 'status' => 'in_progress', 'priority' => 'p3']);

        $page1 = (new PortalChatbotToolExecutor(clientId: $client->id, companyWideAccess: true))
            ->execute('list_tickets', ['status' => 'open', 'limit' => 2, 'offset' => 0]);
        $page2 = (new PortalChatbotToolExecutor(clientId: $client->id, companyWideAccess: true))
            ->execute('list_tickets', ['status' => 'open', 'limit' => 2, 'offset' => 2]);

        $this->assertCount(2, $page1['tickets']);
        $this->assertTrue($page1['pagination']['has_more']);
        $this->assertCount(1, $page2['tickets']);
        $this->assertFalse($page2['pagination']['has_more']);
        $this->assertEmpty(array_intersect(
            array_column($page1['tickets'], 'id'),
            array_column($page2['tickets'], 'id')
        ));
    }

    public function test_chatbot_list_tickets_never_crosses_client_scope(): void
    {
        $mine = Client::create(['name' => 'Mine']);
        $other = Client::create(['name' => 'Other']);
        Ticket::factory()->create(['client_id' => $mine->id, 'status' => 'in_progress', 'subject' => 'mine open ticket']);
        Ticket::factory()->create(['client_id' => $other->id, 'status' => 'in_progress', 'subject' => 'SECRET other client ticket']);

        $result = (new PortalChatbotToolExecutor(clientId: $mine->id, companyWideAccess: true))
            ->execute('list_tickets', ['status' => 'all', 'limit' => 100]);

        $subjects = array_column($result['tickets'], 'subject');
        $this->assertContains('mine open ticket', $subjects);
        $this->assertNotContains('SECRET other client ticket', $subjects);
        $this->assertSame(1, $result['pagination']['total']);
    }

    public function test_chatbot_get_ticket_carries_category_path(): void
    {
        $client = Client::create(['name' => 'Acme']);
        $leaf = $this->hardwareLaptop();
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'category_id' => $leaf->id]);

        $result = (new PortalChatbotToolExecutor(clientId: $client->id, companyWideAccess: true))
            ->execute('get_ticket', ['ticket_id' => $ticket->id]);

        $this->assertArrayHasKey('category_path', $result);
        $this->assertSame('Hardware / Laptop', $result['category_path']);
    }
}
