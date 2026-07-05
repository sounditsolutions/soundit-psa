<?php

namespace Tests\Feature\Signals;

use App\Enums\EmailDirection;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Client;
use App\Models\Email;
use App\Models\SignalEvent;
use App\Models\Ticket;
use App\Services\EmailService;
use App\Services\Signals\SignalHub;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * psa-ip15 W1 Task 2: E1 (intake.email_received) + E2 (intake.email_unresolved)
 * emissions from EmailService::processInbound(). Parallel-plane: these tests assert
 * the SIGNAL side effects ADD UP correctly without ever changing native email
 * processing behaviour (dismiss / match / notify all stay byte-identical).
 */
class IntakeEmailEmissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Fakes RouteSignalEvent (dispatched inside SignalHub::emit) AND the
        // SendTicketNotification job dispatched by notifyUnresolvedEmail — no
        // external calls, no real notification delivery.
        Bus::fake();
    }

    public function test_auto_reply_email_is_dismissed_and_emits_no_intake_signals(): void
    {
        $email = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'postmaster@x.com',
            'from_name' => 'Mail Delivery System',
            'subject' => 'Undeliverable: hello',
            'body_text' => 'Delivery has failed.',
            'received_at' => now(),
        ]);

        app(EmailService::class)->processInbound($email);

        $this->assertNotNull($email->fresh()->dismissed_at);
        $this->assertSame(0, SignalEvent::where('type_key', 'intake.email_received')->count());
        $this->assertSame(0, SignalEvent::where('type_key', 'intake.email_unresolved')->count());
    }

    public function test_spam_from_unknown_sender_is_dismissed_and_emits_no_intake_signals(): void
    {
        // Deterministic spam score >= 5, no AI dependency: unsubscribe language (+2),
        // 3 sales-pitch phrases (+3), free email domain (+1) = 6.
        $email = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'spammer@gmail.com',
            'from_name' => 'Spammer',
            'subject' => 'Special offer just for you',
            'body_text' => 'Special offer! Limited time, act now! Unsubscribe here to opt out.',
            'received_at' => now(),
        ]);

        app(EmailService::class)->processInbound($email);

        $this->assertNotNull($email->fresh()->dismissed_at);
        $this->assertSame(0, SignalEvent::where('type_key', 'intake.email_received')->count());
        $this->assertSame(0, SignalEvent::where('type_key', 'intake.email_unresolved')->count());
    }

    public function test_email_matched_to_existing_ticket_emits_one_intake_email_received_signal(): void
    {
        $ticket = $this->makeTicket();

        $email = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'jane@acme.com',
            'from_name' => 'Jane Doe',
            'subject' => "RE: Server down [{$ticket->display_id}]",
            'body_text' => 'Still down, please help.',
            'received_at' => now(),
        ]);

        app(EmailService::class)->processInbound($email);

        $event = $this->assertSingleSignalEvent('intake.email_received');

        $this->assertSame($email->getMorphClass(), $event->entity_type);
        $this->assertSame($email->id, $event->entity_id);
        $this->assertStringContainsString('matched', $event->summary);
    }

    public function test_unresolved_email_within_24h_emits_received_and_unresolved_signals(): void
    {
        $client = Client::factory()->create();

        // No matching ticket, client known (bypasses the unknown-sender spam guard),
        // email_auto_ticket setting left off (default/unset), received just now.
        $email = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'someone@knownclient.test',
            'from_name' => 'Someone',
            'subject' => 'New issue, no ticket yet',
            'body_text' => 'Please help with a new problem.',
            'received_at' => now(),
            'client_id' => $client->id,
        ]);

        app(EmailService::class)->processInbound($email);

        $received = $this->assertSingleSignalEvent('intake.email_received');
        $this->assertStringContainsString('unresolved', $received->summary);

        $this->assertSingleSignalEvent('intake.email_unresolved');
    }

    public function test_unresolved_email_older_than_24h_emits_received_but_not_unresolved_signal(): void
    {
        $client = Client::factory()->create();

        $email = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'someone@knownclient.test',
            'from_name' => 'Someone',
            'subject' => 'Old issue, no ticket',
            'body_text' => 'This came in a while ago.',
            'received_at' => now()->subHours(30),
            'client_id' => $client->id,
        ]);

        app(EmailService::class)->processInbound($email);

        // Site B (E1) fires unconditionally on fallthrough; E2 is gated by the native
        // 24h notify guard, so a >24h backfill must NOT wake the unresolved feed.
        $this->assertSingleSignalEvent('intake.email_received');
        $this->assertSame(0, SignalEvent::where('type_key', 'intake.email_unresolved')->count());
    }

    public function test_intake_signal_summaries_never_leak_subject_or_from_address(): void
    {
        $client = Client::factory()->create();

        $email = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'secret@evil.test',
            'from_name' => 'Evil Sender',
            'subject' => 'SECRET SUBJECT',
            'body_text' => 'Body text that must also never leak into a summary.',
            'received_at' => now(),
            'client_id' => $client->id,
        ]);

        app(EmailService::class)->processInbound($email);

        // Falls through to unresolved exactly like the 24h test above — both E1 and E2 fire.
        $events = SignalEvent::where('type_key', 'like', 'intake.email_%')->get();
        $this->assertSame(2, $events->count());

        foreach ($events as $event) {
            $this->assertStringNotContainsString('SECRET SUBJECT', $event->summary);
            $this->assertStringNotContainsString('secret@evil.test', $event->summary);
        }
    }

    public function test_matched_email_completes_native_link_when_signal_hub_throws(): void
    {
        $ticket = $this->makeTicket();

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

        // Deliberately no body_text/body_html/has_attachments/graph_id: this keeps
        // linkEmailToTicket's note-creation branch (and therefore TicketNoteObserver's
        // separate, pre-existing, unwrapped ticket.client_replied emit) out of play, so
        // this test isolates OUR wrapping (emitIntakeSignal) as the thing under test.
        $email = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'jane@acme.com',
            'from_name' => 'Jane Doe',
            'subject' => "RE: Server down [{$ticket->display_id}]",
            'received_at' => now(),
        ]);

        app(EmailService::class)->processInbound($email);

        $this->assertSame($ticket->id, $email->fresh()->ticket_id);
        $this->assertDatabaseCount('signal_events', 0);
    }

    private function makeTicket(): Ticket
    {
        $client = Client::factory()->create();

        // withoutEvents: keep TicketObserver's own ticket.created emit out of these
        // tests so signal_events only ever contains what processInbound itself emits.
        return Ticket::withoutEvents(fn () => Ticket::create([
            'client_id' => $client->id,
            'subject' => 'Server down',
            'type' => TicketType::Incident,
            'status' => TicketStatus::New,
            'priority' => TicketPriority::P3,
        ]));
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
