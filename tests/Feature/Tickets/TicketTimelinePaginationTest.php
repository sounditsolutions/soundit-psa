<?php

namespace Tests\Feature\Tickets;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\NoteType;
use App\Enums\WhoType;
use App\Models\AssistantConversation;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class TicketTimelinePaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ticket creation fires the observer (triage dispatch + creation notification).
        Bus::fake();
    }

    /**
     * Seed $count notes on $ticket. Index 0 is the newest; each subsequent note
     * is one minute older, so ordering across pages is deterministic.
     */
    private function seedNotes(Ticket $ticket, User $user, int $count, int $minutesEach = 0): void
    {
        for ($i = 0; $i < $count; $i++) {
            TicketNote::create([
                'ticket_id' => $ticket->id,
                'author_id' => $user->id,
                'author_name' => $user->name,
                'who_type' => WhoType::Agent,
                'body' => "NOTE-BODY-{$i}",
                'body_html' => "<p>NOTE-BODY-{$i}</p>",
                'note_type' => NoteType::Note,
                'is_private' => false,
                'time_minutes' => $minutesEach,
                'noted_at' => now()->subMinutes($i),
            ]);
        }
    }

    public function test_ticket_show_paginates_the_timeline_to_25_items_per_page(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();
        $this->seedNotes($ticket, $user, 30);

        // Page 1: the 25 newest notes, with a link through to page 2.
        $page1 = $this->actingAs($user)->get(route('tickets.show', $ticket));
        $page1->assertOk();
        $page1->assertSee('NOTE-BODY-0');       // newest
        $page1->assertSee('NOTE-BODY-24');      // 25th newest — last on page 1
        $page1->assertDontSee('NOTE-BODY-25');  // spills onto page 2
        $page1->assertDontSee('NOTE-BODY-29');  // oldest
        $page1->assertSee('page=2', false);     // pagination nav rendered

        // Page 2: the 5 oldest notes, and none of page 1's.
        $page2 = $this->actingAs($user)->get(route('tickets.show', ['ticket' => $ticket, 'page' => 2]));
        $page2->assertOk();
        $page2->assertSee('NOTE-BODY-29');      // oldest
        $page2->assertSee('NOTE-BODY-25');
        $page2->assertDontSee('NOTE-BODY-0');   // newest lives on page 1
        $page2->assertDontSee('NOTE-BODY-24');
    }

    public function test_a_single_page_of_activity_shows_no_pagination_nav(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();
        $this->seedNotes($ticket, $user, 3);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));
        $resp->assertOk();
        $resp->assertSee('NOTE-BODY-0');
        $resp->assertSee('NOTE-BODY-2');
        $resp->assertDontSee('page=2', false);  // one page → no nav
    }

    public function test_total_time_sums_every_note_not_just_the_visible_page(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();
        // 30 notes x 10 minutes = 300 minutes = "5h", independent of pagination.
        $this->seedNotes($ticket, $user, 30, minutesEach: 10);

        $this->actingAs($user)->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('5h');

        // Even on page 2 (only 5 notes rendered) the total still reflects all 30.
        $this->actingAs($user)->get(route('tickets.show', ['ticket' => $ticket, 'page' => 2]))
            ->assertOk()
            ->assertSee('5h');
    }

    public function test_build_timeline_merges_and_orders_all_three_sources_newest_first(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();

        // Four activity items with strictly decreasing timestamps.
        $newestNote = TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $user->id,
            'author_name' => $user->name,
            'who_type' => WhoType::Agent,
            'body' => 'newest note',
            'body_html' => '<p>newest note</p>',
            'note_type' => NoteType::Note,
            'is_private' => false,
            'noted_at' => now(),
        ]);

        $call = PhoneCall::create([
            'ticket_id' => $ticket->id,
            'call_uuid' => 'test-call-'.$ticket->id,
            'direction' => CallDirection::Inbound,
            'from_number' => '+15550001111',
            'status' => CallStatus::Completed,
            'started_at' => now()->subMinutes(1),
        ]);

        $conversation = AssistantConversation::create([
            'user_id' => $user->id,
            'context_type' => 'ticket',
            'context_id' => $ticket->id,
            'title' => 'AI chat',
        ]);
        // created_at is not fillable; back-date it so it sorts between the notes.
        $conversation->forceFill(['created_at' => now()->subMinutes(2)])->save();

        $oldestNote = TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $user->id,
            'author_name' => $user->name,
            'who_type' => WhoType::Agent,
            'body' => 'oldest note',
            'body_html' => '<p>oldest note</p>',
            'note_type' => NoteType::Note,
            'is_private' => false,
            'noted_at' => now()->subMinutes(3),
        ]);

        $service = app(TicketService::class);

        // One large page verifies the merge + sort across all three tables.
        $all = $service->buildTimeline($ticket->fresh(), 10);
        $this->assertInstanceOf(LengthAwarePaginator::class, $all);
        $this->assertSame(4, $all->total());

        $ordered = $all->items();
        $this->assertInstanceOf(TicketNote::class, $ordered[0]);
        $this->assertTrue($ordered[0]->is($newestNote));
        $this->assertInstanceOf(PhoneCall::class, $ordered[1]);
        $this->assertTrue($ordered[1]->is($call));
        $this->assertInstanceOf(AssistantConversation::class, $ordered[2]);
        $this->assertTrue($ordered[2]->is($conversation));
        $this->assertInstanceOf(TicketNote::class, $ordered[3]);
        $this->assertTrue($ordered[3]->is($oldestNote));

        // Heavy display relations are eager-loaded for the rendered page's items.
        $this->assertTrue($ordered[0]->relationLoaded('author'));
        $this->assertTrue($ordered[1]->relationLoaded('answeredBy'));
        $this->assertTrue($ordered[2]->relationLoaded('messages'));
    }

    public function test_build_timeline_slices_a_small_page_off_the_merged_stream(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();
        $this->seedNotes($ticket, $user, 5);

        // Default resolver → page 1. A per-page of 2 gives 3 pages over 5 notes.
        $page1 = app(TicketService::class)->buildTimeline($ticket->fresh(), 2);

        $this->assertSame(2, $page1->perPage());
        $this->assertSame(5, $page1->total());
        $this->assertSame(3, $page1->lastPage());
        $this->assertCount(2, $page1->items());
        $this->assertSame('NOTE-BODY-0', $page1->items()[0]->body); // newest
        $this->assertSame('NOTE-BODY-1', $page1->items()[1]->body);
    }
}
