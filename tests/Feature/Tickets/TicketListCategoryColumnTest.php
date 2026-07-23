<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The ITIL taxonomy category must appear on the staff ticket list, not only the
 * detail view (psa-717bn — Charlie: "everywhere that lists a ticket should also
 * list its category"). Leaf name in the row, full path on hover; null-safe;
 * retired nodes preserved; N+1-safe via categoryNode.parent.parent eager-load.
 */
class TicketListCategoryColumnTest extends TestCase
{
    use RefreshDatabase;

    private function tree(): TicketCategory
    {
        $root = TicketCategory::create(['name' => 'Security & EDR']);
        $mid = TicketCategory::create(['name' => 'Scareware', 'parent_id' => $root->id]);

        return TicketCategory::create(['name' => 'Fake-AV popup', 'parent_id' => $mid->id]);
    }

    public function test_list_shows_leaf_name_and_full_path_tooltip(): void
    {
        $leaf = $this->tree();
        Ticket::factory()->create(['status' => TicketStatus::InProgress, 'category_id' => $leaf->id]);

        $resp = $this->actingAs(User::factory()->create())
            ->get(route('tickets.index', ['assignee_id' => 'all']))
            ->assertOk();

        $resp->assertSee('Fake-AV popup');                                   // leaf in the row
        $resp->assertSee('Security &amp; EDR / Scareware / Fake-AV popup', false); // full path in tooltip
    }

    public function test_list_is_null_safe_for_uncategorized_ticket(): void
    {
        Ticket::factory()->create(['status' => TicketStatus::InProgress, 'category_id' => null]);

        // Must render without error — no category is a gap, not a crash.
        $this->actingAs(User::factory()->create())
            ->get(route('tickets.index', ['assignee_id' => 'all']))
            ->assertOk();
    }

    public function test_list_preserves_a_retired_category_node(): void
    {
        $retired = TicketCategory::create(['name' => 'Legacy Bucket', 'is_active' => false]);
        Ticket::factory()->create(['status' => TicketStatus::InProgress, 'category_id' => $retired->id]);

        $this->actingAs(User::factory()->create())
            ->get(route('tickets.index', ['assignee_id' => 'all']))
            ->assertOk()
            ->assertSee('Legacy Bucket')
            ->assertSee('retired');
    }

    public function test_category_path_is_not_n_plus_one_across_rows(): void
    {
        $leaf = $this->tree();
        // Several tickets on the same depth-3 node; the ancestor walk must resolve
        // from the eager-loaded chain, not one query per row.
        Ticket::factory()->count(4)->create(['status' => TicketStatus::InProgress, 'category_id' => $leaf->id]);

        DB::enableQueryLog();
        $this->actingAs(User::factory()->create())
            ->get(route('tickets.index', ['assignee_id' => 'all']))
            ->assertOk();
        $categoryQueries = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'ticket_categories'))
            ->count();
        DB::disableQueryLog();

        // categoryNode + parent + parent.parent = a bounded handful regardless of
        // how many rows carry the node. A per-row ancestor walk would blow past this.
        $this->assertLessThanOrEqual(5, $categoryQueries, "Category path is N+1 across rows ({$categoryQueries} ticket_categories queries)");
    }
}
