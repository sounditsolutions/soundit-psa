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
 * Read fidelity for close decisions (psa-m7re). Chet's false-close discipline
 * hinges on two signals that were the weakest in the read tools: "when did
 * anyone last touch this" and "what did the client actually say last".
 */
class ReadFidelityTest extends TestCase
{
    use RefreshDatabase;

    private function note(Ticket $ticket, string $body, $notedAt): TicketNote
    {
        return TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_name' => 'Tester',
            'body' => $body,
            'note_type' => NoteType::Note->value,
            'noted_at' => $notedAt,
        ]);
    }

    public function test_get_ticket_notes_returns_the_latest_notes_not_the_oldest(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        for ($i = 1; $i <= 25; $i++) {
            $this->note($ticket, "note {$i}", now()->addMinutes($i)); // note 25 is newest
        }

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_ticket_notes', ['ticket_id' => $ticket->id]);

        $bodies = array_column($result, 'body');
        $this->assertContains('note 25', $bodies, 'the LATEST note must be in the payload');
        $this->assertNotContains('note 1', $bodies, 'oldest notes drop off when >20 exist');
        $this->assertSame('note 25', end($bodies), 'notes presented chronologically, newest last');
    }

    public function test_get_ticket_notes_does_not_truncate_long_bodies_mid_sentence(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $this->note($ticket, str_repeat('x', 1500).' TAIL_MARKER', now());

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_ticket_notes', ['ticket_id' => $ticket->id]);

        $this->assertStringContainsString('TAIL_MARKER', $result[0]['body'],
            'a long final client message must not be cut off before its end');
    }

    public function test_get_ticket_detail_exposes_last_activity_and_responded_at(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'responded_at' => now()->subDays(3),
        ]);
        $latestNoteAt = now()->subHour();
        $this->note($ticket, 'older', now()->subDays(2));
        $this->note($ticket, 'latest human touch', $latestNoteAt);

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_ticket_detail', ['ticket_id' => $ticket->id]);

        $this->assertArrayHasKey('last_activity_at', $result);
        $this->assertArrayHasKey('responded_at', $result);
        $this->assertNotNull($result['last_activity_at']);
        // last_activity reflects the most recent signal (the latest note here).
        $this->assertSame($latestNoteAt->toDateTimeString(), $result['last_activity_at']);
    }

    public function test_get_ticket_detail_recent_notes_allow_longer_bodies(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $this->note($ticket, str_repeat('y', 1200).' DETAIL_TAIL', now());

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_ticket_detail', ['ticket_id' => $ticket->id]);

        $this->assertStringContainsString('DETAIL_TAIL', $result['recent_notes'][0]['body'],
            'detail recent_notes must not clip a ~1.2k-char note at 500');
    }
}
