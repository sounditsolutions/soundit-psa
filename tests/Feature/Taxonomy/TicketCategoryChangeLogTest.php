<?php

namespace Tests\Feature\Taxonomy;

use App\Enums\TicketCategoryChangeSource;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketCategoryChangeLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The observer seam behind so-0ftg Part 4's override logging: EVERY
 * tickets.category_id change is recorded by TicketObserver with source
 * attribution and path/legacy-pair snapshots — no writer opts in, so future
 * surfaces (ticket edit UI, MCP tools) are captured the day they exist.
 * Phase 1 mines these rows to refine the coarse triage map.
 */
class TicketCategoryChangeLogTest extends TestCase
{
    use RefreshDatabase;

    private function tree(): array
    {
        $root = TicketCategory::create(['name' => 'Identity & Access']);
        $leaf = TicketCategory::create(['name' => 'Offboarding', 'parent_id' => $root->id]);

        return [$root, $leaf];
    }

    public function test_an_authenticated_change_is_attributed_to_staff_with_the_user_id(): void
    {
        [, $leaf] = $this->tree();
        $ticket = Ticket::factory()->create(['category' => 'User Account', 'subcategory' => 'Offboarding']);
        $staff = User::factory()->create();

        $this->actingAs($staff);
        $ticket->update(['category_id' => $leaf->id]);

        $log = TicketCategoryChangeLog::sole();
        $this->assertSame(TicketCategoryChangeSource::Staff, $log->source);
        $this->assertSame($staff->id, $log->changed_by);
        $this->assertNull($log->previous_category_id);
        $this->assertSame($leaf->id, $log->new_category_id);
        $this->assertSame('Identity & Access / Offboarding', $log->new_path);
        // The legacy free-text pair at change time rides along (Phase-1 join key).
        $this->assertSame('User Account', $log->legacy_category);
        $this->assertSame('Offboarding', $log->legacy_subcategory);
    }

    public function test_an_unauthenticated_change_is_attributed_to_system(): void
    {
        [, $leaf] = $this->tree();
        $ticket = Ticket::factory()->create();

        $ticket->update(['category_id' => $leaf->id]);

        $log = TicketCategoryChangeLog::sole();
        $this->assertSame(TicketCategoryChangeSource::System, $log->source);
        $this->assertNull($log->changed_by);
    }

    public function test_clearing_the_node_snapshots_the_previous_path(): void
    {
        [, $leaf] = $this->tree();
        $ticket = Ticket::factory()->create();
        $ticket->update(['category_id' => $leaf->id]);

        $ticket->update(['category_id' => null]);

        $last = TicketCategoryChangeLog::orderByDesc('id')->first();
        $this->assertSame($leaf->id, $last->previous_category_id);
        $this->assertSame('Identity & Access / Offboarding', $last->previous_path);
        $this->assertNull($last->new_category_id);
        $this->assertNull($last->new_path);
    }

    public function test_updates_that_do_not_touch_category_id_write_no_row(): void
    {
        $ticket = Ticket::factory()->create();

        $ticket->update(['subject' => 'Renamed subject']);

        $this->assertSame(0, TicketCategoryChangeLog::count());
    }

    public function test_latest_source_for_reflects_the_most_recent_change(): void
    {
        [, $leaf] = $this->tree();
        $ticket = Ticket::factory()->create();

        $this->assertNull(TicketCategoryChangeLog::latestSourceFor($ticket));

        TicketCategoryChangeLog::runAsTriage(fn () => $ticket->update(['category_id' => $leaf->id]));
        $this->assertSame(TicketCategoryChangeSource::Triage, TicketCategoryChangeLog::latestSourceFor($ticket));

        $this->actingAs(User::factory()->create());
        $ticket->update(['category_id' => null]);
        $this->assertSame(TicketCategoryChangeSource::Staff, TicketCategoryChangeLog::latestSourceFor($ticket));
    }

    public function test_run_as_triage_resets_attribution_even_when_the_write_throws(): void
    {
        [, $leaf] = $this->tree();
        $ticket = Ticket::factory()->create();

        try {
            TicketCategoryChangeLog::runAsTriage(function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        // Attribution must not leak out of the failed triage context.
        $ticket->update(['category_id' => $leaf->id]);
        $this->assertSame(TicketCategoryChangeSource::System, TicketCategoryChangeLog::sole()->source);
    }
}
