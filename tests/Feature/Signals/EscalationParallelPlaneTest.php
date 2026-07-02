<?php

namespace Tests\Feature\Signals;

use App\Enums\FlagAttentionCategory;
use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\Setting;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalRoute;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\Escalation\EscalationNotifier;
use App\Services\Agent\Escalation\OperatorDelivery;
use App\Services\Agent\Escalation\OperatorDeliveryResult;
use App\Services\Signals\DefaultSignalRoutes;
use App\Services\Signals\SignalHub;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class EscalationParallelPlaneTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Ticket $ticket;

    private User $charlie;

    private TechnicianRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create(['name' => 'Acme Corp']);
        $this->ticket = Ticket::withoutEvents(fn () => Ticket::factory()->for($this->client)->create(['subject' => 'Server down']));
        $this->charlie = User::factory()->create([
            'name' => 'Charlie',
            'email' => 'charlie@example.com',
            'microsoft_id' => 'aad-charlie-uuid',
        ]);
        $this->run = TechnicianRun::create([
            'ticket_id' => $this->ticket->id,
            'client_id' => $this->client->id,
            'action_type' => 'flag_attention',
            'content_hash' => str_repeat('a', 64),
            'state' => TechnicianRunState::Flagged,
        ]);

        Setting::setValue('technician_escalation_judgment_user', (string) $this->charlie->id);
    }

    public function test_notify_emits_flag_attention_without_changing_operator_delivery_arguments_or_creating_signal_deliveries(): void
    {
        $blocker = 'Need a human decision with [unsafe](http://example.test) marker';
        $sanitizedBlocker = 'Need a human decision with unsafe marker';
        $sendCalls = [];
        $this->mockOperatorDelivery($blocker, $sendCalls, $sanitizedBlocker);

        $this->assertDatabaseCount('signal_routes', 0);

        app(EscalationNotifier::class)->notify(
            $this->ticket,
            $this->run,
            FlagAttentionCategory::NeedsDecision,
            $blocker,
        );

        $this->assertLegacyOperatorDeliveryCall($sendCalls, $sanitizedBlocker);
        $this->assertDatabaseCount('signal_events', 1);

        $event = SignalEvent::query()->sole();
        $this->assertSame('agent.flag_attention', $event->type_key);
        $this->assertSame($sanitizedBlocker, $event->summary);
        $this->assertDatabaseCount('signal_deliveries', 0);
    }

    public function test_notify_keeps_legacy_operator_delivery_when_signal_hub_throws(): void
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
                throw new \RuntimeException('SignalHub unavailable');
            }
        });

        $blocker = 'Need a human decision';
        $sendCalls = [];
        $this->mockOperatorDelivery($blocker, $sendCalls);

        app(EscalationNotifier::class)->notify(
            $this->ticket,
            $this->run,
            FlagAttentionCategory::NeedsDecision,
            $blocker,
        );

        $this->assertLegacyOperatorDeliveryCall($sendCalls, $blocker);
        $this->assertSame(
            [
                'category' => FlagAttentionCategory::NeedsDecision->value,
                'recipient_user_id' => $this->charlie->id,
                'step' => 0,
            ],
            array_intersect_key(
                $this->run->fresh()->proposed_meta['escalation'],
                ['category' => true, 'recipient_user_id' => true, 'step' => true],
            ),
        );
        $this->assertDatabaseCount('signal_events', 0);
        $this->assertDatabaseCount('signal_deliveries', 0);
    }

    public function test_default_legacy_operator_webhook_route_is_seeded_disabled_from_existing_setting(): void
    {
        Setting::setValue('technician_teams_webhook_url', 'https://hooks.example.test/legacy');

        app(DefaultSignalRoutes::class)->ensureLegacyOperatorWebhookRoute();
        app(DefaultSignalRoutes::class)->ensureLegacyOperatorWebhookRoute();

        $destination = SignalDestination::query()->sole();
        $this->assertSame('Legacy operator webhook (migration)', $destination->label);
        $this->assertSame('webhook', $destination->type);
        $this->assertSame('https://hooks.example.test/legacy', $destination->address);
        $this->assertFalse($destination->enabled);

        $route = SignalRoute::with('steps')->sole();
        $this->assertSame('Legacy operator webhook (migration)', $route->label);
        $this->assertFalse($route->enabled);
        $this->assertSame(['types' => ['agent.flag_attention']], $route->event_filter);
        $this->assertSame(1, $route->steps->count());
        $this->assertSame(1, $route->steps->first()->step_order);
        $this->assertSame($destination->id, $route->steps->first()->destination_id);

        $this->assertDatabaseCount('signal_config_log', 2);
        $this->assertDatabaseHas('signal_config_log', [
            'action' => 'created',
            'subject_type' => SignalDestination::class,
            'subject_id' => $destination->id,
        ]);
        $this->assertDatabaseHas('signal_config_log', [
            'action' => 'created',
            'subject_type' => SignalRoute::class,
            'subject_id' => $route->id,
        ]);
    }

    private function mockOperatorDelivery(string $expectedBlocker, array &$sendCalls, ?string $sanitizedBlocker = null): void
    {
        $this->mock(OperatorDelivery::class, function (MockInterface $mock) use ($expectedBlocker, &$sendCalls, $sanitizedBlocker) {
            $mock->shouldReceive('sanitize')
                ->once()
                ->with($expectedBlocker, '[escalation detail withheld - open the ticket]')
                ->andReturn($sanitizedBlocker ?? $expectedBlocker);

            $mock->shouldReceive('send')
                ->once()
                ->andReturnUsing(
                    function (
                        ?User $recipient,
                        ?string $conversationId,
                        ?string $serviceUrl,
                        string $subject,
                        string $body,
                    ) use (&$sendCalls): OperatorDeliveryResult {
                        $sendCalls[] = [$recipient, $conversationId, $serviceUrl, $subject, $body];

                        return new OperatorDeliveryResult(
                            posted: false,
                            postedToChat: false,
                            remoteMessageId: null,
                        );
                    },
                );
        });
    }

    private function assertLegacyOperatorDeliveryCall(array $sendCalls, string $blocker): void
    {
        $this->assertCount(1, $sendCalls);
        $this->assertCount(5, $sendCalls[0]);

        [$recipient, $conversationId, $serviceUrl, $subject, $body] = $sendCalls[0];

        $this->assertInstanceOf(User::class, $recipient);
        $this->assertTrue($recipient->is($this->charlie));
        $this->assertSame(
            [
                null,
                null,
                "AI Technician needs a human \u{2014} ticket #{$this->ticket->id}",
                $this->expectedLegacyBody($blocker),
            ],
            [$conversationId, $serviceUrl, $subject, $body],
        );
    }

    private function expectedLegacyBody(string $blocker): string
    {
        return "\u{1F916} The AI Technician needs Charlie on #{$this->ticket->id}"
            ." ({$this->client->name} \u{2014} {$this->ticket->subject}): {$blocker}."
            .' Open the cockpit: '.route('cockpit.index');
    }
}
