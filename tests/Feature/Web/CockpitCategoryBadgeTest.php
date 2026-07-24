<?php

namespace Tests\Feature\Web;

use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\Technician\Cockpit\CockpitQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-717bn.4: cockpit lane cards show the ticket's ITIL category chip next to
 * the client badge (shared <x-ticket-category-badge>), and CockpitQuery
 * eager-loads categoryNode.parent.parent on every ticket-bearing lane so the
 * badge's pathString() never lazy-loads per card. Null-safe (uncategorized
 * tickets render no chip, no error); retired nodes preserved. Subjects avoid
 * the category words so assertions prove the badge rendered.
 */
class CockpitCategoryBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function tree(): TicketCategory
    {
        $root = TicketCategory::create(['name' => 'Security & EDR']);
        $mid = TicketCategory::create(['name' => 'Scareware', 'parent_id' => $root->id]);

        return TicketCategory::create(['name' => 'Fake-AV popup', 'parent_id' => $mid->id]);
    }

    private function openTicket(?int $categoryId = null, string $subject = 'Printer keeps jamming'): Ticket
    {
        $client = Client::factory()->create();

        return Ticket::factory()->for($client)->create([
            'status' => TicketStatus::InProgress,
            'subject' => $subject,
            'category_id' => $categoryId,
        ]);
    }

    private function stageRun(Ticket $ticket, string $actionType, TechnicianRunState $state): TechnicianRun
    {
        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => $actionType,
            'content_hash' => str_repeat('c', 64),
            'state' => $state,
            'proposed_content' => 'Drafted reply text.',
        ]);
    }

    public function test_reply_draft_card_shows_category_leaf_and_path(): void
    {
        $ticket = $this->openTicket($this->tree()->id, 'Popup on screen');
        $this->stageRun($ticket, 'send_reply', TechnicianRunState::AwaitingApproval);

        $this->actingAs(User::factory()->create())->get(route('cockpit.index'))
            ->assertOk()
            ->assertSee('Fake-AV popup')
            ->assertSee('Security &amp; EDR / Scareware / Fake-AV popup', false);
    }

    public function test_flagged_card_shows_category_and_uncategorized_is_null_safe(): void
    {
        $flaggedTicket = $this->openTicket($this->tree()->id, 'Needs a human eye');
        $this->stageRun($flaggedTicket, 'flag_attention', TechnicianRunState::Flagged);

        // A second, uncategorized ticket in the same lanes must not error out the page.
        $bare = $this->openTicket(null, 'No category here');
        $this->stageRun($bare, 'send_reply', TechnicianRunState::AwaitingApproval);

        $this->actingAs(User::factory()->create())->get(route('cockpit.index'))
            ->assertOk()
            ->assertSee('Fake-AV popup');
    }

    public function test_retired_category_is_preserved_on_cards(): void
    {
        $retired = TicketCategory::create(['name' => 'Legacy Bucket', 'is_active' => false]);
        $ticket = $this->openTicket($retired->id, 'Old-style request');
        $this->stageRun($ticket, 'send_reply', TechnicianRunState::AwaitingApproval);

        $this->actingAs(User::factory()->create())->get(route('cockpit.index'))
            ->assertOk()
            ->assertSee('Legacy Bucket')
            ->assertSee('retired');
    }

    public function test_ticket_bearing_lanes_eager_load_the_category_chain(): void
    {
        $leaf = $this->tree();

        $draftTicket = $this->openTicket($leaf->id);
        $this->stageRun($draftTicket, 'send_reply', TechnicianRunState::AwaitingApproval);

        $flaggedTicket = $this->openTicket($leaf->id);
        $this->stageRun($flaggedTicket, 'flag_attention', TechnicianRunState::Flagged);

        $queuedTicket = $this->openTicket($leaf->id);
        $this->stageRun($queuedTicket, 'tactical_stage_reboot', TechnicianRunState::QueuedOffline);

        $expiredTicket = $this->openTicket($leaf->id);
        $this->stageRun($expiredTicket, 'tactical_stage_reboot', TechnicianRunState::Expired);

        $closedTicket = $this->openTicket($leaf->id);
        $closedTicket->update(['status' => TicketStatus::Closed]);
        $this->stageRun($closedTicket, 'direct_close', TechnicianRunState::Done);

        $query = app(CockpitQuery::class);

        foreach ([
            'pendingDrafts' => $query->pendingDrafts(),
            'flaggedForAttention' => $query->flaggedForAttention(),
            'queuedOffline' => $query->queuedOffline(),
            'expiredQueue' => $query->expiredQueue(),
            'recentDirectCloses' => $query->recentDirectCloses(),
        ] as $lane => $rows) {
            $this->assertTrue($rows->isNotEmpty(), "{$lane} returned no rows");
            $this->assertTrue(
                $rows->first()->ticket->relationLoaded('categoryNode'),
                "{$lane} must eager-load ticket.categoryNode (badge would lazy-load per card)"
            );
        }
    }
}
