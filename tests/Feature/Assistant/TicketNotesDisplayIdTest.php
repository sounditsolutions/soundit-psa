<?php

namespace Tests\Feature\Assistant;

use App\Enums\NoteType;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\Assistant\AssistantToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * get_ticket_notes must accept display ids (psa-gq0f). Chet reads ticket
 * numbers off Teams/tool output as "#8351" — on externally-synced tickets
 * that number is halo_id, not the internal id, and the old id-only lookup
 * answered "not found or belongs to a different client" for a ticket the
 * client plainly owns.
 */
class TicketNotesDisplayIdTest extends TestCase
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

    /** Create a synced ticket whose display number diverges from its internal id. */
    private function syncedTicket(Client $client): Ticket
    {
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $ticket->update(['halo_id' => $ticket->id + 100000]);

        return $ticket;
    }

    public function test_reads_notes_by_bare_external_display_number(): void
    {
        $client = Client::factory()->create();
        $ticket = $this->syncedTicket($client);
        $this->note($ticket, 'the fix that worked');

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_ticket_notes', ['ticket_id' => $ticket->halo_id]);

        $this->assertArrayNotHasKey('error', $result,
            'a synced ticket must be readable by its display number');
        $this->assertSame('the fix that worked', $result[0]['body']);
    }

    public function test_reads_notes_by_hash_prefixed_display_id_string(): void
    {
        $client = Client::factory()->create();
        $ticket = $this->syncedTicket($client);
        $this->note($ticket, 'note behind the hash');

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_ticket_notes', ['ticket_id' => "#{$ticket->halo_id}"]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('note behind the hash', $result[0]['body']);
    }

    public function test_reads_notes_by_t_prefixed_internal_display_id(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $this->note($ticket, 'native ticket note');

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_ticket_notes', ['ticket_id' => "T-{$ticket->id}"]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('native ticket note', $result[0]['body']);
    }

    public function test_internal_id_still_wins_over_a_colliding_display_number(): void
    {
        $client = Client::factory()->create();
        $internal = Ticket::factory()->create(['client_id' => $client->id]);
        $this->note($internal, 'internal ticket note');
        $synced = Ticket::factory()->create(['client_id' => $client->id]);
        $synced->update(['halo_id' => $internal->id]);
        $this->note($synced, 'synced ticket note');

        $executor = new AssistantToolExecutor(clientId: $client->id);

        $bare = $executor->execute('get_ticket_notes', ['ticket_id' => $internal->id]);
        $this->assertSame('internal ticket note', $bare[0]['body'],
            'bare numbers keep resolving the internal id first');

        $hashed = $executor->execute('get_ticket_notes', ['ticket_id' => '#'.$internal->id]);
        $this->assertSame('synced ticket note', $hashed[0]['body'],
            '"#N" explicitly targets the synced ticket');
    }

    public function test_display_number_of_another_clients_ticket_is_still_denied(): void
    {
        $mine = Client::factory()->create();
        $other = Client::factory()->create();
        $foreign = $this->syncedTicket($other);
        $this->note($foreign, 'foreign note');

        $result = (new AssistantToolExecutor(clientId: $mine->id))
            ->execute('get_ticket_notes', ['ticket_id' => $foreign->halo_id]);

        $this->assertSame('Ticket not found or belongs to a different client', $result['error']);
    }

    public function test_unknown_reference_still_errors(): void
    {
        $client = Client::factory()->create();

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_ticket_notes', ['ticket_id' => 999999]);

        $this->assertSame('Ticket not found or belongs to a different client', $result['error']);
    }

    public function test_non_scalar_ticket_id_is_rejected_not_fatal(): void
    {
        $client = Client::factory()->create();

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_ticket_notes', ['ticket_id' => ['nested' => 'array']]);

        $this->assertSame('ticket_id is required', $result['error']);
    }
}
