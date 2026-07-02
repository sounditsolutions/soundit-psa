<?php

namespace Tests\Feature\Signals;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\EmailDirection;
use App\Enums\PersonType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Client;
use App\Models\Email;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\Ticket;
use App\Services\Prospect\ProspectIntakeService;
use App\Services\Technician\Notify\DigestBuilder;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CoreEmissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
    }

    public function test_ticket_service_create_ticket_emits_one_ticket_created_signal_with_client_and_priority_context(): void
    {
        $client = Client::factory()->create();

        $ticket = app(TicketService::class)->createTicket([
            'client_id' => $client->id,
            'subject' => 'VPN unavailable',
            'description' => 'Users cannot connect to VPN.',
            'type' => TicketType::Incident->value,
            'priority' => TicketPriority::P2->value,
        ], null);

        $event = $this->assertSingleSignalEvent('ticket.created');

        $this->assertSame($ticket->getMorphClass(), $event->entity_type);
        $this->assertSame($ticket->id, $event->entity_id);
        $this->assertSame($client->id, $event->context['client_id'] ?? null);
        $this->assertIsInt($event->context['priority'] ?? null);
        $this->assertSame($ticket->priority_order, $event->context['priority'] ?? null);
    }

    public function test_prospect_intake_provision_from_call_emits_one_ticket_created_signal(): void
    {
        $call = PhoneCall::create([
            'call_uuid' => 'prospect-call-1',
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550123',
            'to_number' => '+15555550000',
            'status' => CallStatus::Completed,
            'started_at' => now()->subMinutes(5),
            'call_summary' => 'Needs help with backups',
        ]);

        $result = app(ProspectIntakeService::class)->provisionFromCall($call, 'Jane Prospect');
        $ticket = $result['ticket'];

        $event = $this->assertSingleSignalEvent('ticket.created');

        $this->assertSame($ticket->getMorphClass(), $event->entity_type);
        $this->assertSame($ticket->id, $event->entity_id);
        $this->assertSame($result['client']->id, $event->context['client_id'] ?? null);
        $this->assertIsInt($event->context['priority'] ?? null);
        $this->assertSame($ticket->priority_order, $event->context['priority'] ?? null);
    }

    public function test_portal_client_reply_emits_one_ticket_client_replied_signal_with_client_context(): void
    {
        $client = Client::factory()->create();
        $person = Person::create([
            'halo_id' => 5001,
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Client',
            'last_name' => 'User',
            'email' => 'client@example.test',
            'is_active' => true,
            'portal_enabled' => true,
        ]);
        $ticket = Ticket::withoutEvents(fn () => Ticket::factory()->create([
            'client_id' => $client->id,
            'contact_id' => $person->id,
            'status' => TicketStatus::InProgress,
            'closed_at' => null,
        ]));

        app(TicketService::class)->addPortalReply($ticket, $person, 'Any update on this?');

        $event = $this->assertSingleSignalEvent('ticket.client_replied');

        $this->assertSame($ticket->getMorphClass(), $event->entity_type);
        $this->assertSame($ticket->id, $event->entity_id);
        $this->assertSame($client->id, $event->context['client_id'] ?? null);
    }

    public function test_email_client_reply_emits_one_ticket_client_replied_signal_with_client_context(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::withoutEvents(fn () => Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => TicketStatus::InProgress,
            'closed_at' => null,
        ]));
        $email = Email::create([
            'graph_id' => 'graph-client-reply-1',
            'direction' => EmailDirection::Inbound,
            'from_address' => 'client@example.test',
            'from_name' => 'Client User',
            'subject' => 'Re: '.$ticket->subject,
            'body_preview' => 'Any update?',
            'body_text' => 'Any update?',
            'received_at' => now(),
            'client_id' => $client->id,
        ]);

        app(\App\Services\EmailService::class)->linkEmailToTicket($email, $ticket);

        $event = $this->assertSingleSignalEvent('ticket.client_replied');

        $this->assertSame($ticket->getMorphClass(), $event->entity_type);
        $this->assertSame($ticket->id, $event->entity_id);
        $this->assertSame($client->id, $event->context['client_id'] ?? null);
    }

    public function test_technician_digest_omits_signal_destination_health_when_no_destinations_failed(): void
    {
        SignalDestination::create([
            'label' => 'Healthy webhook',
            'type' => 'webhook',
            'address' => 'https://hooks.example.test/healthy',
            'last_delivery_status' => 'delivered',
            'last_delivery_at' => now()->subHour(),
        ]);

        $digest = app(DigestBuilder::class)->build();

        $this->assertStringNotContainsString('Signal destination health', $digest->body);
        $this->assertStringNotContainsString('Healthy webhook', $digest->body);
    }

    public function test_technician_digest_lists_recent_failed_signal_destination_and_omits_stale_failures(): void
    {
        SignalDestination::create([
            'label' => 'Recent failed webhook',
            'type' => 'webhook',
            'address' => 'https://hooks.example.test/recent',
            'last_delivery_status' => 'failed',
            'last_delivery_at' => now()->subHours(2),
        ]);
        SignalDestination::create([
            'label' => 'Old failed webhook',
            'type' => 'webhook',
            'address' => 'https://hooks.example.test/old',
            'last_delivery_status' => 'failed',
            'last_delivery_at' => now()->subHours(25),
        ]);

        $digest = app(DigestBuilder::class)->build();

        $this->assertFalse($digest->isEmpty);
        $this->assertStringContainsString('Recent failed webhook', $digest->body);
        $this->assertStringNotContainsString('Old failed webhook', $digest->body);
    }

    private function assertSingleSignalEvent(string $typeKey): SignalEvent
    {
        $events = SignalEvent::query()->where('type_key', $typeKey)->get();

        $this->assertSame(1, $events->count(), "Expected exactly one {$typeKey} signal event.");

        /** @var SignalEvent $event */
        $event = $events->first();

        return $event;
    }
}
