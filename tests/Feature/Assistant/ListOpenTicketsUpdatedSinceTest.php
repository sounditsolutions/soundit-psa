<?php

namespace Tests\Feature\Assistant;

use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Ticket;
use App\Services\Assistant\AssistantToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * psa-1f35 (triage req 148): a recently-modified feed for list_open_tickets so
 * Chet can find new client replies landing on EXISTING open tickets without
 * re-fetching every ticket one by one.
 */
class ListOpenTicketsUpdatedSinceTest extends TestCase
{
    use RefreshDatabase;

    private function openTicket(Client $client, string $subject, string $updatedAt): Ticket
    {
        $ticket = Ticket::factory()->for($client)->create([
            'subject' => $subject,
            'status' => TicketStatus::InProgress,
        ]);
        // Set updated_at precisely without Eloquent re-touching the timestamp.
        DB::table('tickets')->where('id', $ticket->id)->update(['updated_at' => $updatedAt]);

        return $ticket->refresh();
    }

    public function test_updated_since_returns_only_recently_modified_tickets_newest_first(): void
    {
        $client = Client::factory()->create();
        $stale = $this->openTicket($client, 'stale', now()->subHours(2)->toDateTimeString());
        $recent = $this->openTicket($client, 'recent', now()->subMinutes(10)->toDateTimeString());
        $freshest = $this->openTicket($client, 'freshest', now()->subMinutes(1)->toDateTimeString());

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('list_open_tickets', ['updated_since' => now()->subMinutes(30)->toIso8601String()]);

        $ids = array_column($result, 'id');
        $this->assertNotContains($stale->id, $ids, 'a ticket last touched before the cutoff is excluded');
        $this->assertContains($recent->id, $ids);
        $this->assertContains($freshest->id, $ids);

        // Recently-modified feed: newest touch first.
        $this->assertSame([$freshest->id, $recent->id], $ids);

        // updated_at is surfaced so the agent can see the last-touch time.
        $this->assertArrayHasKey('updated_at', $result[0]);
        $this->assertNotNull($result[0]['updated_at']);
    }

    public function test_omitting_updated_since_preserves_the_default_unfiltered_listing(): void
    {
        $client = Client::factory()->create();
        $this->openTicket($client, 'stale', now()->subHours(2)->toDateTimeString());
        $this->openTicket($client, 'recent', now()->subMinutes(5)->toDateTimeString());

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('list_open_tickets', []);

        $this->assertCount(2, $result, 'without updated_since, all open tickets are returned');
    }

    public function test_unparseable_updated_since_returns_a_clear_error(): void
    {
        $client = Client::factory()->create();
        $this->openTicket($client, 'a', now()->subHours(2)->toDateTimeString());

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('list_open_tickets', ['updated_since' => 'not-a-date']);

        // Fail-loud, matching the existing `since` params on the read tools.
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('valid', strtolower((string) $result['error']));
    }
}
