<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\NoteType;
use App\Enums\WhoType;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAuthoredBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function aiNote(Ticket $ticket): TicketNote
    {
        return TicketNote::create([
            'ticket_id' => $ticket->id, 'author_name' => 'Chet', 'who_type' => WhoType::Agent,
            'ai_authored' => true, 'body' => 'Thanks for reaching out — disclosed AI reply.',
            'note_type' => NoteType::Reply, 'is_private' => false, 'noted_at' => now(),
        ]);
    }

    public function test_staff_timeline_marks_an_ai_authored_note(): void
    {
        $ticket = Ticket::factory()->create(['client_id' => Client::factory()->create()->id]);
        $this->aiNote($ticket);

        $this->actingAs(User::factory()->create())
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('AI-authored', false) // the badge text/label — unambiguous (not just 'AI')
            ->assertSee('disclosed AI reply');
    }
}
