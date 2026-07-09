<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * psa-vm2r: the unassigned-open-ticket count badge belongs on the "Unassigned"
 * quick-filter chip, not the "All" chip.
 *
 * QA saw the "All" chip read "All 1" while the queue heading read Tickets (60):
 * the badge was the unassigned count (1) rendered on the wrong button, so it
 * read as a misleading queue-size cue. The count itself is correct — it was
 * just attached to the wrong toggle.
 */
class TicketQuickFilterBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_unassigned_count_badge_renders_on_the_unassigned_chip_not_all(): void
    {
        $viewer = User::factory()->create();
        $assignee = User::factory()->create();

        // One open, unassigned ticket -> unassignedCount = 1 (the badge value).
        // The factory defaults to Closed + null assignee, so set an open status.
        Ticket::factory()->create(['status' => TicketStatus::New, 'closed_at' => null]);

        // Assigned open tickets so the queue is larger than the unassigned count,
        // mirroring the QA scenario (badge shows 1 while the list holds more).
        Ticket::factory()->count(2)->create([
            'status' => TicketStatus::New,
            'closed_at' => null,
            'assignee_id' => $assignee->id,
        ]);

        $resp = $this->actingAs($viewer)
            ->get(route('tickets.index', ['assignee_id' => 'all']))
            ->assertOk();

        // The count badge (uniquely marked with ms-1) reports the unassigned total.
        $resp->assertSee('<span class="badge bg-warning text-dark ms-1">1</span>', false);

        // It sits AFTER the Unassigned chip's href — i.e. inside the Unassigned
        // button. Before the fix it lived between the All and Unassigned anchors,
        // which would place it before "assignee_id=unassigned" and fail this order.
        $resp->assertSeeInOrder([
            'assignee_id=all',
            'assignee_id=unassigned',
            'badge bg-warning text-dark ms-1',
        ], false);
    }

    public function test_no_count_badge_when_no_unassigned_tickets(): void
    {
        $viewer = User::factory()->create();
        $assignee = User::factory()->create();

        // Only assigned open tickets -> unassignedCount = 0 -> guard hides the badge.
        Ticket::factory()->count(2)->create([
            'status' => TicketStatus::New,
            'closed_at' => null,
            'assignee_id' => $assignee->id,
        ]);

        $this->actingAs($viewer)
            ->get(route('tickets.index', ['assignee_id' => 'all']))
            ->assertOk()
            ->assertDontSee('badge bg-warning text-dark ms-1', false);
    }
}
