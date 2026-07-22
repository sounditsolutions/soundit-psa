<?php

namespace Tests\Feature\Taxonomy;

use App\Enums\RecordTypeHint;
use App\Enums\SopStatus;
use App\Models\Ticket;
use App\Models\TicketCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Foundation for the so-0ftg auto-SOP + ITIL taxonomy build (office spec so-0ftg).
 * ticket_categories is a self-referential tree (Category -> Subcategory ->
 * Item/Symptom, depth <= 3), carrying the SOP text served inline on ticket detail.
 * These lock the schema + model contract every later leg (UI, MCP tools,
 * get_ticket_detail delivery, triage mapping) builds on.
 */
class TicketCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_persists_all_fields_with_enum_casts_and_defaults(): void
    {
        $node = TicketCategory::create([
            'name' => 'Security & EDR',
            'description' => 'Endpoint detection and response.',
            'record_type_hint' => RecordTypeHint::Incident,
        ]);

        $fresh = $node->fresh();
        $this->assertSame('Security & EDR', $fresh->name);
        $this->assertInstanceOf(RecordTypeHint::class, $fresh->record_type_hint);
        $this->assertSame(RecordTypeHint::Incident, $fresh->record_type_hint);
        // Defaults: no SOP authored yet, active, root.
        $this->assertSame(SopStatus::None, $fresh->sop_status);
        $this->assertTrue($fresh->is_active);
        $this->assertNull($fresh->parent_id);
        $this->assertNull($fresh->sop_text);
    }

    public function test_tree_parent_children_ancestors_descendants_and_depth(): void
    {
        $cat = TicketCategory::create(['name' => 'Security & EDR']);
        $sub = TicketCategory::create(['name' => 'Scareware', 'parent_id' => $cat->id]);
        $item = TicketCategory::create(['name' => 'Fake-AV popup', 'parent_id' => $sub->id]);

        // parent / children
        $this->assertSame($cat->id, $sub->parent->id);
        $this->assertTrue($cat->children->contains($sub));

        // depth: root = 1, leaf item = 3
        $this->assertSame(1, $cat->depth());
        $this->assertSame(2, $sub->depth());
        $this->assertSame(3, $item->depth());

        // ancestors: root -> ... -> self-parent chain, ordered root-first
        $path = $item->ancestors()->pluck('id')->all();
        $this->assertSame([$cat->id, $sub->id], $path);
        $this->assertSame('Security & EDR / Scareware / Fake-AV popup', $item->pathString());

        // descendants (all levels under a node)
        $this->assertEqualsCanonicalizing([$sub->id, $item->id], $cat->descendants()->pluck('id')->all());

        // leaf detection
        $this->assertFalse($cat->isLeaf());
        $this->assertTrue($item->isLeaf());
    }

    public function test_deleting_a_parent_nulls_childrens_parent_id(): void
    {
        $cat = TicketCategory::create(['name' => 'Network']);
        $sub = TicketCategory::create(['name' => 'Wi-Fi', 'parent_id' => $cat->id]);

        $cat->delete();

        $this->assertSame(0, TicketCategory::whereKey($cat->id)->count());
        $this->assertNull($sub->fresh()->parent_id, 'child must survive with a nulled parent_id (nullOnDelete)');
    }

    public function test_scopes_active_coverage_gap_and_stale(): void
    {
        $withSop = TicketCategory::create(['name' => 'Has SOP', 'sop_text' => '# Do this', 'sop_status' => SopStatus::Reviewed]);
        $gap = TicketCategory::create(['name' => 'No SOP', 'sop_status' => SopStatus::None]);
        // Retired but was reviewed before retirement — proves coverageGap() keys on
        // the status, not on activeness (callers compose active()->coverageGap()).
        $retired = TicketCategory::create(['name' => 'Retired', 'is_active' => false, 'sop_status' => SopStatus::Reviewed]);

        $this->assertEqualsCanonicalizing(
            [$withSop->id, $gap->id],
            TicketCategory::active()->pluck('id')->all(),
            'active() excludes retired nodes'
        );

        // coverageGap = the SOP-hint marker of "no procedure here yet" (pure status
        // scope; the UI composes it with active()).
        $this->assertSame([$gap->id], TicketCategory::coverageGap()->pluck('id')->all());

        // stale: updated_at older than the threshold
        $withSop->forceFill(['updated_at' => now()->subDays(120)])->saveQuietly();
        $staleIds = TicketCategory::stale(90)->pluck('id')->all();
        $this->assertContains($withSop->id, $staleIds);
        $this->assertNotContains($gap->id, $staleIds);
    }

    public function test_ticket_belongs_to_category_and_category_has_tickets(): void
    {
        $leaf = TicketCategory::create(['name' => 'Password reset']);
        $ticket = Ticket::factory()->create(['category_id' => $leaf->id]);

        // Relation is categoryNode() — category() would be shadowed by the legacy
        // free-text `category` string column.
        $this->assertSame($leaf->id, $ticket->fresh()->categoryNode->id);
        $this->assertTrue($leaf->tickets->contains($ticket));

        // The legacy free-text columns are untouched (additive change).
        $this->assertArrayHasKey('category', $ticket->fresh()->getAttributes());
    }

    public function test_sop_status_is_a_soft_hint_has_sop_reflects_text_not_status(): void
    {
        // A "reviewed" status with no text is still a coverage gap for delivery;
        // status NEVER gates whether text is served (that is the delivery leg's job,
        // but hasSop() is the primitive it will use).
        $draftWithText = TicketCategory::create(['name' => 'x', 'sop_text' => 'steps', 'sop_status' => SopStatus::Draft]);
        $reviewedNoText = TicketCategory::create(['name' => 'y', 'sop_status' => SopStatus::Reviewed]);

        $this->assertTrue($draftWithText->hasSop());
        $this->assertFalse($reviewedNoText->hasSop());
    }

    public function test_taxonomy_migrations_roll_back_via_the_real_runner_on_a_file_sqlite_db(): void
    {
        // Faithful to the path gus runs in prod: drive Laravel's REAL migrator
        // (migrate / migrate:rollback) against a FILE-backed SQLite database — not the
        // :memory: default, and not the migration objects called in isolation (whose
        // DROP COLUMN behaviour proved environment-sensitive and so was not a reliable
        // guard). This test is RED if the redundant explicit category_id index is
        // reintroduced — SQLite refuses `DROP COLUMN category_id` while the column is
        // still indexed ("error in index tickets_category_id_index after drop column")
        // — and GREEN at the fixed migration, whose FK-backed index (MariaDB) is
        // sufficient and needs no explicit duplicate.
        $dbPath = tempnam(sys_get_temp_dir(), 'taxonomy_rollback_');
        // tempnam() already created an empty file at $dbPath; a 0-byte file is a valid
        // empty SQLite database, so the migrator can build the schema straight into it.

        config()->set('database.connections.taxonomy_rollback_sqlite', [
            'driver' => 'sqlite',
            'database' => $dbPath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        try {
            $this->artisan('migrate:fresh', [
                '--database' => 'taxonomy_rollback_sqlite',
                '--force' => true,
            ])->assertSuccessful();

            // Rollback runs newest-first; the two taxonomy migrations sort last, so
            // --step=2 rolls back 000002 (add category_id) then 000001 (create table).
            $this->artisan('migrate:rollback', [
                '--database' => 'taxonomy_rollback_sqlite',
                '--step' => 2,
                '--force' => true,
            ])->assertSuccessful();

            $schema = Schema::connection('taxonomy_rollback_sqlite');
            $this->assertFalse($schema->hasColumn('tickets', 'category_id'), 'category_id dropped on rollback');
            $this->assertFalse($schema->hasTable('ticket_categories'), 'ticket_categories dropped on rollback');
        } finally {
            DB::purge('taxonomy_rollback_sqlite');
            @unlink($dbPath);
        }
    }
}
