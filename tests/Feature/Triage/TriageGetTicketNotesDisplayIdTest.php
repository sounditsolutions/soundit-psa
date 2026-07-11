<?php

namespace Tests\Feature\Triage;

use App\Enums\NoteType;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\Triage\TriageToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The triage/technician surface shares the get_ticket_notes contract with the
 * assistant surface (psa-gq0f): past synced tickets must be readable by the
 * display number the agent sees in search output and note bodies.
 */
class TriageGetTicketNotesDisplayIdTest extends TestCase
{
    use RefreshDatabase;

    private function note(Ticket $ticket, string $body): TicketNote
    {
        return TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_name' => 'Tester',
            'body' => $body,
            'note_type' => NoteType::Note->value,
            'noted_at' => now(),
        ]);
    }

    public function test_reads_a_synced_tickets_notes_by_display_number(): void
    {
        $client = Client::factory()->create();
        $context = Ticket::factory()->create(['client_id' => $client->id]);
        $past = Ticket::factory()->create(['client_id' => $client->id]);
        $past->update(['halo_id' => $past->id + 100000]);
        $this->note($past, 'how we fixed it last time');

        $executor = new TriageToolExecutor($context);

        $bare = $executor->execute('get_ticket_notes', ['ticket_id' => $past->halo_id]);
        $this->assertArrayNotHasKey('error', $bare);
        $this->assertSame('how we fixed it last time', $bare[0]['body']);

        $hashed = $executor->execute('get_ticket_notes', ['ticket_id' => "#{$past->halo_id}"]);
        $this->assertArrayNotHasKey('error', $hashed);
        $this->assertSame('how we fixed it last time', $hashed[0]['body']);
    }

    public function test_display_number_of_another_clients_ticket_is_still_denied(): void
    {
        $client = Client::factory()->create();
        $other = Client::factory()->create();
        $context = Ticket::factory()->create(['client_id' => $client->id]);
        $foreign = Ticket::factory()->create(['client_id' => $other->id]);
        $foreign->update(['halo_id' => $foreign->id + 100000]);
        $this->note($foreign, 'foreign note');

        $result = (new TriageToolExecutor($context))
            ->execute('get_ticket_notes', ['ticket_id' => $foreign->halo_id]);

        $this->assertSame('Ticket not found or belongs to a different client', $result['error']);
    }
}
