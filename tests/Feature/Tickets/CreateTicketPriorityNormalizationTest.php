<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketType;
use App\Models\Client;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * psa-mnmh root-cause guard: TicketService::createTicket() must accept a
 * `priority` supplied as either a TicketPriority instance or its scalar backing
 * value. The portal controller passes an instance; every other caller passes
 * ->value. Native enum from() throws a TypeError on an instance, so the service
 * normalises both.
 */
class CreateTicketPriorityNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The created-ticket observer dispatches queued work — contain it.
        Bus::fake();
    }

    private function baseData(Client $client): array
    {
        return [
            'client_id' => $client->id,
            'subject' => 'Test ticket',
            'description' => 'Body',
            'source' => TicketSource::Manual->value,
            'type' => TicketType::ServiceRequest->value,
        ];
    }

    public function test_create_ticket_accepts_a_priority_enum_instance(): void
    {
        $client = Client::factory()->create();

        $ticket = app(TicketService::class)->createTicket(
            $this->baseData($client) + ['priority' => TicketPriority::P2],
            null,
        );

        $this->assertSame(TicketPriority::P2, $ticket->priority);
        $this->assertSame(TicketPriority::P2->sortOrder(), $ticket->priority_order);
    }

    public function test_create_ticket_accepts_a_scalar_priority_value(): void
    {
        $client = Client::factory()->create();

        $ticket = app(TicketService::class)->createTicket(
            $this->baseData($client) + ['priority' => TicketPriority::P3->value],
            null,
        );

        $this->assertSame(TicketPriority::P3, $ticket->priority);
        $this->assertSame(TicketPriority::P3->sortOrder(), $ticket->priority_order);
    }
}
