<?php

namespace Tests\Unit\Tickets;

use App\Models\Ticket;
use App\Models\TicketCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ticket::categoryFields() — the shared null-safe category mapper the ticket-list
 * MCP tools emit per row (psa-717bn.2), mirroring getTicketDetail's applicable_sop
 * shape: category_id + category_path (the full pathString), or nulls when unset.
 */
class TicketCategoryFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_id_and_full_path_for_a_categorized_ticket(): void
    {
        $root = TicketCategory::create(['name' => 'Security & EDR']);
        $mid = TicketCategory::create(['name' => 'Scareware', 'parent_id' => $root->id]);
        $leaf = TicketCategory::create(['name' => 'Fake-AV popup', 'parent_id' => $mid->id]);
        $ticket = Ticket::factory()->create(['category_id' => $leaf->id]);

        $this->assertSame([
            'category_id' => $leaf->id,
            'category_path' => 'Security & EDR / Scareware / Fake-AV popup',
        ], $ticket->categoryFields());
    }

    public function test_returns_nulls_for_an_uncategorized_ticket(): void
    {
        $ticket = Ticket::factory()->create(['category_id' => null]);

        $this->assertSame(['category_id' => null, 'category_path' => null], $ticket->categoryFields());
    }

    public function test_preserves_a_retired_nodes_path(): void
    {
        $retired = TicketCategory::create(['name' => 'Legacy Bucket', 'is_active' => false]);
        $ticket = Ticket::factory()->create(['category_id' => $retired->id]);

        $fields = $ticket->categoryFields();
        $this->assertSame($retired->id, $fields['category_id']);
        $this->assertSame('Legacy Bucket', $fields['category_path']);
    }
}
