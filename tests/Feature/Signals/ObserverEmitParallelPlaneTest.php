<?php

namespace Tests\Feature\Signals;

use App\Enums\PersonType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Client;
use App\Models\Person;
use App\Models\SignalEvent;
use App\Models\Ticket;
use App\Services\Signals\SignalHub;
use App\Services\TicketService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * psa-0htk: TicketObserver::created() and TicketNoteObserver's client-reply signal
 * both call app(SignalHub::class)->emit(...) unwrapped, in the CALLER's frame — outside
 * SignalHub::emit()'s own internal try/catch. A container-resolution or dispatch failure
 * there must never break the LIVE plane (real ticket/note writes). These tests install a
 * SignalHub double that always throws and assert the native path still completes.
 */
class ObserverEmitParallelPlaneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
    }

    public function test_ticket_creation_survives_signal_hub_throwing_on_ticket_created(): void
    {
        $this->installThrowingSignalHub();

        $client = Client::factory()->create();

        $ticket = app(TicketService::class)->createTicket([
            'client_id' => $client->id,
            'subject' => 'Parallel-plane guard: throwing SignalHub must not break ticket creation',
            'description' => 'Users cannot connect to VPN.',
            'type' => TicketType::Incident->value,
            'priority' => TicketPriority::P2->value,
        ], null);

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'subject' => 'Parallel-plane guard: throwing SignalHub must not break ticket creation',
        ]);
        $this->assertDatabaseCount('signal_events', 0);
    }

    public function test_client_reply_note_survives_signal_hub_throwing_on_client_replied(): void
    {
        $this->installThrowingSignalHub();

        $client = Client::factory()->create();
        $person = Person::create([
            'halo_id' => 5002,
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Client',
            'last_name' => 'User',
            'email' => 'reply-client@example.test',
            'is_active' => true,
            'portal_enabled' => true,
        ]);
        $ticket = Ticket::withoutEvents(fn () => Ticket::factory()->create([
            'client_id' => $client->id,
            'contact_id' => $person->id,
            'status' => TicketStatus::InProgress,
            'closed_at' => null,
        ]));

        $note = app(TicketService::class)->addPortalReply($ticket, $person, 'Parallel-plane guard reply body');

        $this->assertDatabaseHas('ticket_notes', [
            'id' => $note->id,
            'ticket_id' => $ticket->id,
            'body' => 'Parallel-plane guard reply body',
        ]);
        $this->assertDatabaseCount('signal_events', 0);
    }

    private function installThrowingSignalHub(): void
    {
        $this->app->instance(SignalHub::class, new class extends SignalHub
        {
            public function __construct() {}

            public function emit(
                string $typeKey,
                ?Model $entity,
                string $summary,
                array $context = [],
                ?int $originEventId = null,
            ): ?SignalEvent {
                throw new \RuntimeException('boom');
            }
        });
    }
}
