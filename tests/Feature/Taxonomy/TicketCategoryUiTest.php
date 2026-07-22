<?php

namespace Tests\Feature\Taxonomy;

use App\Enums\SopStatus;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * so-0ftg Phase-0 staff UI for the ticket-category taxonomy: tree list with
 * name / sop_status (coverage-gap) / staleness filters, create + retire, and
 * edit-in-place on the show page (the priority UX — every field saves from
 * where it is read). Open to all authenticated staff, no RBAC.
 */
class TicketCategoryUiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /** Category -> Subcategory -> Item fixture used across the tests. */
    private function makeTree(): array
    {
        $cat = TicketCategory::create(['name' => 'Security & EDR']);
        $sub = TicketCategory::create(['name' => 'Scareware', 'parent_id' => $cat->id]);
        $item = TicketCategory::create(['name' => 'Fake-AV popup', 'parent_id' => $sub->id]);

        return [$cat, $sub, $item];
    }

    // ── access ───────────────────────────────────────────────────────────────

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('ticket-categories.index'))->assertRedirect(route('login'));
    }

    public function test_any_staff_user_can_view_and_edit_no_rbac(): void
    {
        [$cat] = $this->makeTree();

        $this->actingAs($this->user)->get(route('ticket-categories.index'))->assertOk();
        $this->actingAs($this->user)
            ->patch(route('ticket-categories.update', $cat), ['name' => 'Security'])
            ->assertRedirect(route('ticket-categories.show', $cat));
    }

    // ── index: tree + filters ────────────────────────────────────────────────

    public function test_index_shows_the_full_tree_nested(): void
    {
        [$cat, $sub, $item] = $this->makeTree();

        $this->actingAs($this->user)->get(route('ticket-categories.index'))
            ->assertOk()
            ->assertSeeInOrder(['Security & EDR', 'Scareware', 'Fake-AV popup']);
    }

    public function test_index_tree_includes_retired_nodes_so_active_children_stay_visible(): void
    {
        [$cat, $sub, $item] = $this->makeTree();
        $sub->update(['is_active' => false]);

        // The structural tree never hides a retired parent — otherwise its
        // active children would silently vanish from the page.
        $this->actingAs($this->user)->get(route('ticket-categories.index'))
            ->assertOk()
            ->assertSee('Scareware')
            ->assertSee('Fake-AV popup')
            ->assertSee('Retired');
    }

    public function test_index_search_by_name_returns_flat_paths(): void
    {
        $this->makeTree();
        TicketCategory::create(['name' => 'Printing']);

        $resp = $this->actingAs($this->user)->get(route('ticket-categories.index', ['q' => 'scare']));
        $resp->assertOk()
            ->assertSee('Security &amp; EDR / Scareware', false)
            ->assertDontSee('Printing');
    }

    public function test_index_filters_coverage_gaps_by_sop_status(): void
    {
        $gap = TicketCategory::create(['name' => 'No SOP here']);
        TicketCategory::create(['name' => 'Documented', 'sop_text' => '# Steps', 'sop_status' => SopStatus::Reviewed]);

        $this->actingAs($this->user)->get(route('ticket-categories.index', ['sop_status' => 'none']))
            ->assertOk()
            ->assertSee('No SOP here')
            ->assertDontSee('Documented');
    }

    public function test_index_filters_stale_nodes_by_updated_at(): void
    {
        $stale = TicketCategory::create(['name' => 'Forgotten SOP']);
        $stale->forceFill(['updated_at' => now()->subDays(120)])->saveQuietly();
        TicketCategory::create(['name' => 'Fresh SOP']);

        $this->actingAs($this->user)->get(route('ticket-categories.index', ['stale' => 90]))
            ->assertOk()
            ->assertSee('Forgotten SOP')
            ->assertDontSee('Fresh SOP');
    }

    public function test_index_filters_by_active_state(): void
    {
        TicketCategory::create(['name' => 'Live node']);
        $retired = TicketCategory::create(['name' => 'Retired node', 'is_active' => false]);

        // Filtered views default to active-only.
        $this->actingAs($this->user)->get(route('ticket-categories.index', ['q' => 'node']))
            ->assertOk()
            ->assertSee('Live node')
            ->assertDontSee('Retired node');

        $this->actingAs($this->user)->get(route('ticket-categories.index', ['active' => '0']))
            ->assertOk()
            ->assertSee('Retired node')
            ->assertDontSee('Live node');
    }

    // ── create ───────────────────────────────────────────────────────────────

    public function test_create_page_offers_only_parents_above_the_bottom_tier(): void
    {
        [$cat, $sub, $item] = $this->makeTree();

        $resp = $this->actingAs($this->user)->get(route('ticket-categories.create', ['parent' => $sub->id]));
        $resp->assertOk()
            ->assertSee('Security &amp; EDR / Scareware', false)
            // A depth-3 node cannot parent anything.
            ->assertDontSee('Fake-AV popup');
    }

    public function test_store_creates_a_root_node_with_author_attribution(): void
    {
        $resp = $this->actingAs($this->user)->post(route('ticket-categories.store'), [
            'name' => 'Email & Collaboration',
            'description' => 'Mail flow, spam, calendars.',
            'sop_text' => "# Triage\n1. Check Mesh quarantine.",
            'sop_status' => 'draft',
            'record_type_hint' => 'mixed',
        ]);

        $node = TicketCategory::where('name', 'Email & Collaboration')->firstOrFail();
        $resp->assertRedirect(route('ticket-categories.show', $node));

        $this->assertNull($node->parent_id);
        $this->assertSame(SopStatus::Draft, $node->sop_status);
        $this->assertSame($this->user->id, $node->updated_by);
    }

    public function test_store_creates_a_child_under_a_valid_parent(): void
    {
        [$cat] = $this->makeTree();

        $this->actingAs($this->user)->post(route('ticket-categories.store'), [
            'name' => 'Phishing',
            'parent_id' => $cat->id,
        ]);

        $child = TicketCategory::where('name', 'Phishing')->firstOrFail();
        $this->assertSame($cat->id, $child->parent_id);
        $this->assertSame(SopStatus::None, $child->sop_status);
    }

    public function test_store_refuses_a_parent_at_the_bottom_tier(): void
    {
        [, , $item] = $this->makeTree();

        $resp = $this->actingAs($this->user)->post(route('ticket-categories.store'), [
            'name' => 'Too deep',
            'parent_id' => $item->id,
        ]);

        $resp->assertSessionHasErrors('parent_id');
        $this->assertDatabaseMissing('ticket_categories', ['name' => 'Too deep']);
    }

    // ── show: the edit-in-place surface ──────────────────────────────────────

    public function test_show_renders_sop_and_description_markdown_as_html(): void
    {
        $node = TicketCategory::create([
            'name' => 'VPN',
            'description' => 'Remote access via **WireGuard**.',
            'sop_text' => "# Reset tunnel\n- step one",
        ]);

        $this->actingAs($this->user)->get(route('ticket-categories.show', $node))
            ->assertOk()
            ->assertSee('<strong>WireGuard</strong>', false)
            ->assertSee('<h1>Reset tunnel</h1>', false);
    }

    public function test_show_flags_a_missing_sop_as_a_coverage_gap(): void
    {
        $node = TicketCategory::create(['name' => 'Undocumented']);

        $this->actingAs($this->user)->get(route('ticket-categories.show', $node))
            ->assertOk()
            ->assertSee('No SOP written yet');
    }

    public function test_update_renames_in_place(): void
    {
        $node = TicketCategory::create(['name' => 'Old name']);

        $this->actingAs($this->user)
            ->patch(route('ticket-categories.update', $node), ['form_key' => 'name', 'name' => 'New name'])
            ->assertRedirect(route('ticket-categories.show', $node));

        $this->assertSame('New name', $node->fresh()->name);
    }

    public function test_update_saves_sop_text_alone_and_records_the_editor(): void
    {
        $node = TicketCategory::create(['name' => 'Backups', 'description' => 'Keep me']);

        $this->actingAs($this->user)->patch(route('ticket-categories.update', $node), [
            'form_key' => 'sop',
            'sop_text' => "# Corrected procedure\nDo the new thing.",
        ]);

        $fresh = $node->fresh();
        $this->assertSame("# Corrected procedure\nDo the new thing.", $fresh->sop_text);
        // A partial in-place save must not clobber untouched fields.
        $this->assertSame('Keep me', $fresh->description);
        $this->assertSame($this->user->id, $fresh->updated_by);
    }

    public function test_update_sets_sop_status_alone(): void
    {
        $node = TicketCategory::create(['name' => 'Backups', 'sop_text' => 'steps']);

        $this->actingAs($this->user)->patch(route('ticket-categories.update', $node), [
            'form_key' => 'sop_status',
            'sop_status' => 'reviewed',
        ]);

        $this->assertSame(SopStatus::Reviewed, $node->fresh()->sop_status);
    }

    public function test_update_rejects_an_unknown_sop_status(): void
    {
        $node = TicketCategory::create(['name' => 'Backups']);

        $this->actingAs($this->user)
            ->patch(route('ticket-categories.update', $node), ['sop_status' => 'approved'])
            ->assertSessionHasErrors('sop_status');
    }

    public function test_retire_and_reactivate_toggle_is_active(): void
    {
        $node = TicketCategory::create(['name' => 'Legacy fax support']);

        $this->actingAs($this->user)->patch(route('ticket-categories.update', $node), ['is_active' => '0']);
        $this->assertFalse($node->fresh()->is_active);

        $this->actingAs($this->user)->patch(route('ticket-categories.update', $node), ['is_active' => '1']);
        $this->assertTrue($node->fresh()->is_active);
    }

    // ── re-parenting ─────────────────────────────────────────────────────────

    public function test_update_can_move_a_node_to_a_new_parent(): void
    {
        [$cat] = $this->makeTree();
        $other = TicketCategory::create(['name' => 'Workstations']);

        $this->actingAs($this->user)->patch(route('ticket-categories.update', $other), [
            'parent_id' => $cat->id,
        ]);

        $this->assertSame($cat->id, $other->fresh()->parent_id);
    }

    public function test_update_can_promote_a_node_to_root(): void
    {
        [, $sub] = $this->makeTree();

        $this->actingAs($this->user)->patch(route('ticket-categories.update', $sub), [
            'parent_id' => '',
        ]);

        $this->assertNull($sub->fresh()->parent_id);
    }

    public function test_update_refuses_a_move_under_the_nodes_own_descendant(): void
    {
        [$cat, , $item] = $this->makeTree();

        $this->actingAs($this->user)
            ->patch(route('ticket-categories.update', $cat), ['parent_id' => $item->id])
            ->assertSessionHasErrors('parent_id');

        $this->assertNull($cat->fresh()->parent_id);
    }

    public function test_update_refuses_a_move_that_would_exceed_max_depth(): void
    {
        [, $sub] = $this->makeTree();
        $mover = TicketCategory::create(['name' => 'Printers']);
        TicketCategory::create(['name' => 'Jams', 'parent_id' => $mover->id]);

        // mover has height 2; under a depth-2 parent its child would land at depth 4.
        $this->actingAs($this->user)
            ->patch(route('ticket-categories.update', $mover), ['parent_id' => $sub->id])
            ->assertSessionHasErrors('parent_id');

        $this->assertNull($mover->fresh()->parent_id);
    }

    public function test_update_allows_resubmitting_the_current_parent_unchanged(): void
    {
        // The details form always posts parent_id; an unchanged value must not
        // trip the guard even when the node already sits at full height.
        [$cat, $sub, $item] = $this->makeTree();

        $this->actingAs($this->user)
            ->patch(route('ticket-categories.update', $sub), [
                'form_key' => 'details',
                'parent_id' => (string) $cat->id,
                'sort_order' => '5',
            ])
            ->assertRedirect(route('ticket-categories.show', $sub))
            ->assertSessionHasNoErrors();

        $this->assertSame(5, $sub->fresh()->sort_order);
    }

    // ── retired parents (tree-policy ruling, psa-m4bki) ──────────────────────

    public function test_store_refuses_a_retired_parent(): void
    {
        $retired = TicketCategory::create(['name' => 'Legacy', 'is_active' => false]);

        $this->actingAs($this->user)->post(route('ticket-categories.store'), [
            'name' => 'Orphan-to-be',
            'parent_id' => $retired->id,
        ])->assertSessionHasErrors('parent_id');

        $this->assertDatabaseMissing('ticket_categories', ['name' => 'Orphan-to-be']);
    }

    public function test_update_refuses_a_move_under_a_retired_parent(): void
    {
        $retired = TicketCategory::create(['name' => 'Legacy', 'is_active' => false]);
        $mover = TicketCategory::create(['name' => 'Printers']);

        $this->actingAs($this->user)
            ->patch(route('ticket-categories.update', $mover), ['parent_id' => $retired->id])
            ->assertSessionHasErrors('parent_id');

        $this->assertNull($mover->fresh()->parent_id);
    }

    public function test_create_page_omits_retired_parents_but_keeps_their_active_children(): void
    {
        [$cat, $sub] = $this->makeTree();
        $cat->update(['is_active' => false]);
        $lone = TicketCategory::create(['name' => 'Zz Legacy Fax', 'is_active' => false]);

        $resp = $this->actingAs($this->user)->get(route('ticket-categories.create'));
        $resp->assertOk()->assertDontSee('Zz Legacy Fax');

        // Retirement is per-node, not per-subtree: the retired root is no
        // longer offered, but its still-active child is.
        $offered = collect($resp->viewData('parentOptions'))->pluck('node.id');
        $this->assertNotContains($cat->id, $offered);
        $this->assertNotContains($lone->id, $offered);
        $this->assertContains($sub->id, $offered);
    }

    public function test_show_dropdown_omits_retired_nodes_except_the_current_parent(): void
    {
        [$cat, $sub] = $this->makeTree();
        $cat->update(['is_active' => false]);
        $bystander = TicketCategory::create(['name' => 'Workstations']);

        // The child's own page still offers its retired current parent, so
        // the always-posted parent <select> round-trips without re-rooting.
        $subOffered = collect(
            $this->actingAs($this->user)->get(route('ticket-categories.show', $sub))
                ->assertOk()->assertSee('(retired)')
                ->viewData('parentOptions')
        )->pluck('node.id');
        $this->assertContains($cat->id, $subOffered);

        // Everyone else's page no longer offers the retired node at all.
        $bystanderOffered = collect(
            $this->actingAs($this->user)->get(route('ticket-categories.show', $bystander))
                ->assertOk()->viewData('parentOptions')
        )->pluck('node.id');
        $this->assertNotContains($cat->id, $bystanderOffered);
    }

    public function test_details_form_roundtrips_for_a_child_of_a_retired_parent(): void
    {
        // Retirement is soft and non-cascading: a child already under a
        // retired parent keeps its place, and editing it in place (which
        // resubmits its current parent_id) must neither error nor re-root it.
        [$cat, $sub] = $this->makeTree();
        $cat->update(['is_active' => false]);

        $this->actingAs($this->user)
            ->patch(route('ticket-categories.update', $sub), [
                'form_key' => 'details',
                'parent_id' => (string) $cat->id,
                'sort_order' => '7',
            ])
            ->assertRedirect(route('ticket-categories.show', $sub))
            ->assertSessionHasNoErrors();

        $fresh = $sub->fresh();
        $this->assertSame($cat->id, $fresh->parent_id);
        $this->assertSame(7, $fresh->sort_order);
    }

    public function test_retired_node_page_offers_no_add_child_affordances(): void
    {
        [$cat, $sub] = $this->makeTree();
        $cat->update(['is_active' => false]);

        // The guard would refuse the attachment, so the page must not offer
        // it — but the existing children stay listed (retirement is soft).
        $this->actingAs($this->user)->get(route('ticket-categories.show', $cat))
            ->assertOk()
            ->assertDontSee('Add Child')
            ->assertDontSee('Quick-add child')
            ->assertSee('Scareware');
    }

    public function test_retiring_a_node_keeps_its_existing_children_attached(): void
    {
        [$cat, $sub, $item] = $this->makeTree();

        $this->actingAs($this->user)
            ->patch(route('ticket-categories.update', $sub), ['is_active' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($sub->fresh()->is_active);
        $this->assertSame($sub->id, $item->fresh()->parent_id);
        $this->assertTrue($item->fresh()->is_active);
    }
}
