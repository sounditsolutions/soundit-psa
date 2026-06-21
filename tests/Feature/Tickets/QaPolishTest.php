<?php

namespace Tests\Feature\Tickets;

use App\Enums\NoteType;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Locks in three QA-sourced UI polish fixes (psa-grjd soft hint, psa-4638 a11y labels).
 */
class QaPolishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_resolve_modal_hints_blank_resolution_is_drafted_when_ai_configured(): void
    {
        config(['services.ai.api_key' => 'test-key']);
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);

        $this->actingAs($user)->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee("Leave it blank and we'll draft one from the ticket's notes", false);
    }

    public function test_resolve_modal_omits_draft_hint_when_ai_not_configured(): void
    {
        config(['services.ai.api_key' => null]);
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);

        $this->actingAs($user)->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertDontSee("we'll draft one from the ticket's notes", false);
    }

    public function test_note_action_buttons_have_accessible_names(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $user->id,
            'author_name' => $user->name,
            'who_type' => WhoType::Agent,
            'body' => 'A note',
            'body_html' => '<p>A note</p>',
            'note_type' => NoteType::Note,
            'is_private' => true,
            'noted_at' => now(),
        ]);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket))->assertOk();
        $resp->assertSee('aria-label="Edit note"', false);
        $resp->assertSee('aria-label="Delete note"', false);
    }
}
