<?php

namespace Tests\Feature\Taxonomy;

use App\Models\TicketCategory;
use App\Services\Taxonomy\TicketCategoryTreeGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The write-layer tree-shape rules for the so-0ftg taxonomy: the schema does
 * not enforce depth <= 3 or acyclicity, so this guard must — for every
 * surface that creates, re-parents, or reactivates nodes (staff UI, MCP
 * tools).
 */
class TicketCategoryTreeGuardTest extends TestCase
{
    use RefreshDatabase;

    private TicketCategoryTreeGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new TicketCategoryTreeGuard;
    }

    public function test_attaching_as_root_is_always_legal(): void
    {
        $cat = TicketCategory::create(['name' => 'Network']);
        $sub = TicketCategory::create(['name' => 'Wi-Fi', 'parent_id' => $cat->id]);
        TicketCategory::create(['name' => 'Slow Wi-Fi', 'parent_id' => $sub->id]);

        $this->assertNull($this->guard->attachmentError(null, null));
        // Even a full-height subtree can be promoted to root.
        $this->assertNull($this->guard->attachmentError($cat->fresh(), null));
    }

    public function test_new_node_can_attach_under_depth_1_and_2_but_not_3(): void
    {
        $cat = TicketCategory::create(['name' => 'Network']);
        $sub = TicketCategory::create(['name' => 'Wi-Fi', 'parent_id' => $cat->id]);
        $item = TicketCategory::create(['name' => 'Slow Wi-Fi', 'parent_id' => $sub->id]);

        $this->assertNull($this->guard->attachmentError(null, $cat));
        $this->assertNull($this->guard->attachmentError(null, $sub));
        $this->assertStringContainsString('maximum tree depth', (string) $this->guard->attachmentError(null, $item));
    }

    public function test_node_cannot_be_its_own_parent(): void
    {
        $cat = TicketCategory::create(['name' => 'Network']);

        $this->assertStringContainsString('own parent', (string) $this->guard->attachmentError($cat, $cat));
    }

    public function test_node_cannot_move_under_its_own_descendant(): void
    {
        $cat = TicketCategory::create(['name' => 'Network']);
        $sub = TicketCategory::create(['name' => 'Wi-Fi', 'parent_id' => $cat->id]);
        $item = TicketCategory::create(['name' => 'Slow Wi-Fi', 'parent_id' => $sub->id]);

        $this->assertStringContainsString('own descendant', (string) $this->guard->attachmentError($cat->fresh(), $item->fresh()));
        $this->assertStringContainsString('own descendant', (string) $this->guard->attachmentError($cat->fresh(), $sub->fresh()));
    }

    public function test_subtree_height_limits_how_deep_a_move_can_land(): void
    {
        // A node carrying children (height 2) fits under a root (1 + 2 = 3)
        // but not under a depth-2 parent (2 + 2 = 4).
        $mover = TicketCategory::create(['name' => 'Printers']);
        TicketCategory::create(['name' => 'Jams', 'parent_id' => $mover->id]);

        $otherRoot = TicketCategory::create(['name' => 'Hardware']);
        $otherSub = TicketCategory::create(['name' => 'Peripherals', 'parent_id' => $otherRoot->id]);

        $this->assertNull($this->guard->attachmentError($mover->fresh(), $otherRoot));
        $this->assertStringContainsString('maximum tree depth', (string) $this->guard->attachmentError($mover->fresh(), $otherSub));
    }

    public function test_new_node_cannot_be_created_under_a_retired_parent(): void
    {
        $retired = TicketCategory::create(['name' => 'Legacy', 'is_active' => false]);

        $this->assertStringContainsString('retired parent', (string) $this->guard->attachmentError(null, $retired));
    }

    public function test_node_cannot_move_under_a_retired_parent(): void
    {
        $retired = TicketCategory::create(['name' => 'Legacy', 'is_active' => false]);
        $mover = TicketCategory::create(['name' => 'Printers']);

        $this->assertStringContainsString('retired parent', (string) $this->guard->attachmentError($mover, $retired));
    }

    public function test_retiring_a_parent_does_not_invalidate_its_existing_children(): void
    {
        // Retirement is soft and non-cascading: the guard governs only NEW
        // attach/move targets, so a child already under a retired parent
        // keeps its place, and the retired node's active children remain
        // legal targets themselves.
        $cat = TicketCategory::create(['name' => 'Network']);
        $sub = TicketCategory::create(['name' => 'Wi-Fi', 'parent_id' => $cat->id]);
        $cat->update(['is_active' => false]);

        $this->assertSame($cat->id, $sub->fresh()->parent_id);
        $this->assertNull($this->guard->attachmentError(null, $sub->fresh()));
    }

    public function test_reactivation_is_refused_under_a_retired_parent(): void
    {
        $parent = TicketCategory::create(['name' => 'Legacy', 'is_active' => false]);
        $child = TicketCategory::create(['name' => 'Fax', 'parent_id' => $parent->id, 'is_active' => false]);

        $error = (string) $this->guard->reactivationError($child->fresh());
        $this->assertStringContainsString('"Legacy" is retired', $error);
        $this->assertStringContainsString('Reactivate it first, or reparent this node', $error);
    }

    public function test_reactivation_is_legal_under_an_active_parent_or_as_root(): void
    {
        $parent = TicketCategory::create(['name' => 'Network']);
        $child = TicketCategory::create(['name' => 'Wi-Fi', 'parent_id' => $parent->id, 'is_active' => false]);
        $root = TicketCategory::create(['name' => 'Hardware', 'is_active' => false]);

        $this->assertNull($this->guard->reactivationError($child->fresh()));
        $this->assertNull($this->guard->reactivationError($root->fresh()));
    }

    public function test_subtree_height_counts_the_deepest_branch(): void
    {
        $node = TicketCategory::create(['name' => 'Root']);
        $this->assertSame(1, $this->guard->subtreeHeight($node));

        $child = TicketCategory::create(['name' => 'Child', 'parent_id' => $node->id]);
        TicketCategory::create(['name' => 'Shallow sibling', 'parent_id' => $node->id]);
        $this->assertSame(2, $this->guard->subtreeHeight($node->fresh()));

        TicketCategory::create(['name' => 'Grandchild', 'parent_id' => $child->id]);
        $this->assertSame(3, $this->guard->subtreeHeight($node->fresh()));
    }
}
