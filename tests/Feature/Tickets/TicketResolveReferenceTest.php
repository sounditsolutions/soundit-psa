<?php

namespace Tests\Feature\Tickets;

use App\Models\Client;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ticket::resolveReference — the inverse of display_id (psa-gq0f).
 *
 * AI tools receive whatever number the caller saw: the internal id, or the
 * display id ("#8351") that externally-synced tickets show everywhere. The
 * resolver must accept both without ever widening client scope.
 */
class TicketResolveReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_bare_number_resolves_the_internal_id(): void
    {
        $ticket = Ticket::factory()->create();

        $this->assertTrue($ticket->is(Ticket::resolveReference($ticket->id)));
        $this->assertTrue($ticket->is(Ticket::resolveReference((string) $ticket->id)));
    }

    public function test_bare_number_falls_back_to_halo_id_for_synced_tickets(): void
    {
        $ticket = Ticket::factory()->create();
        // A display number far above any internal id this test run assigns.
        $ticket->update(['halo_id' => $ticket->id + 100000]);

        $resolved = Ticket::resolveReference($ticket->id + 100000);

        $this->assertNotNull($resolved, 'a synced ticket must resolve by its display number');
        $this->assertTrue($ticket->is($resolved));
    }

    public function test_bare_number_prefers_the_internal_id_over_a_colliding_halo_id(): void
    {
        $client = Client::factory()->create();
        $internal = Ticket::factory()->create(['client_id' => $client->id]);
        $synced = Ticket::factory()->create(['client_id' => $client->id]);
        $synced->update(['halo_id' => $internal->id]); // collision: Y's display number = X's internal id

        $this->assertTrue($internal->is(Ticket::resolveReference($internal->id)),
            'existing internal-id callers must keep their exact matches');
    }

    public function test_hash_prefix_resolves_only_the_halo_id(): void
    {
        $client = Client::factory()->create();
        $internal = Ticket::factory()->create(['client_id' => $client->id]);
        $synced = Ticket::factory()->create(['client_id' => $client->id]);
        $synced->update(['halo_id' => $internal->id]);

        $this->assertTrue($synced->is(Ticket::resolveReference('#'.$internal->id)),
            '"#N" is unambiguously the external display number');
        $this->assertNull(Ticket::resolveReference('#'.($synced->id + 100000)),
            'a "#N" with no matching halo_id must not fall back to internal ids');
    }

    public function test_t_prefix_resolves_only_the_internal_id(): void
    {
        $ticket = Ticket::factory()->create();
        $ticket->update(['halo_id' => $ticket->id + 100000]);

        $this->assertTrue($ticket->is(Ticket::resolveReference("T-{$ticket->id}")));
        $this->assertTrue($ticket->is(Ticket::resolveReference("t-{$ticket->id}")));
        $this->assertTrue($ticket->is(Ticket::resolveReference("T{$ticket->id}")));
        $this->assertNull(Ticket::resolveReference('T-'.($ticket->id + 100000)),
            '"T-N" is unambiguously internal — it must not match halo_id');
    }

    public function test_client_scope_is_never_widened(): void
    {
        $mine = Client::factory()->create();
        $other = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $other->id]);
        $ticket->update(['halo_id' => $ticket->id + 100000]);

        $this->assertNull(Ticket::resolveReference($ticket->id, $mine->id));
        $this->assertNull(Ticket::resolveReference($ticket->id + 100000, $mine->id));
        $this->assertNull(Ticket::resolveReference('#'.($ticket->id + 100000), $mine->id));
        $this->assertTrue($ticket->is(Ticket::resolveReference($ticket->id, $other->id)));
    }

    public function test_garbage_references_resolve_to_null(): void
    {
        Ticket::factory()->create();

        $this->assertNull(Ticket::resolveReference(''));
        $this->assertNull(Ticket::resolveReference('#'));
        $this->assertNull(Ticket::resolveReference('12a'));
        $this->assertNull(Ticket::resolveReference('ticket 5'));
        $this->assertNull(Ticket::resolveReference('-1'));
    }

    public function test_soft_deleted_tickets_do_not_resolve(): void
    {
        $ticket = Ticket::factory()->create();
        $ticket->update(['halo_id' => $ticket->id + 100000]);
        $ticket->delete();

        $this->assertNull(Ticket::resolveReference($ticket->id));
        $this->assertNull(Ticket::resolveReference('#'.($ticket->id + 100000)));
    }
}
