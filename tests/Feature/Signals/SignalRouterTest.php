<?php

namespace Tests\Feature\Signals;

use App\Jobs\DeliverSignal;
use App\Jobs\RouteSignalEvent;
use App\Models\McpToken;
use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalRoute;
use App\Models\SignalRouteStep;
use App\Services\Signals\SignalRouter;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SignalRouterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_enabled_matching_route_dispatches_lowest_step_order_deliveries_only(): void
    {
        $route = $this->route(['types' => ['ticket.created']], stepOrders: [2, 1, 1]);
        $disabled = $this->route(['types' => ['ticket.created']], enabled: false);
        $event = $this->event('ticket.created');

        app(SignalRouter::class)->route($event);

        $this->assertSame(2, SignalDelivery::where('route_id', $route->id)->where('status', 'pending')->count());
        $this->assertSame(0, SignalDelivery::where('route_id', $disabled->id)->count());
        $this->assertSame([1, 1], SignalDelivery::where('route_id', $route->id)->pluck('step_order')->all());
        Bus::assertDispatchedTimes(DeliverSignal::class, 2);
    }

    public function test_disabled_destination_creates_suppressed_audit_row_without_dispatching(): void
    {
        $route = $this->route(['types' => ['ticket.created']], destinationEnabled: false);
        $event = $this->event('ticket.created');

        app(SignalRouter::class)->route($event);

        $delivery = SignalDelivery::query()
            ->where('route_id', $route->id)
            ->where('event_id', $event->id)
            ->sole();

        $this->assertSame('suppressed', $delivery->status);
        $this->assertSame('destination-disabled', $delivery->error);
        Bus::assertNotDispatched(DeliverSignal::class);
    }

    public function test_route_signal_event_job_invokes_router(): void
    {
        $this->route(['types' => ['ticket.created']]);
        $event = $this->event('ticket.created');

        (new RouteSignalEvent($event->id))->handle();

        $this->assertDatabaseHas('signal_deliveries', [
            'event_id' => $event->id,
            'status' => 'pending',
        ]);
        Bus::assertDispatched(DeliverSignal::class);
    }

    public function test_filter_matching_supports_all_types_category_priority_and_client_id(): void
    {
        $allRoute = $this->route(['types' => 'all']);
        $narrowRoute = $this->route([
            'types' => ['ticket.created'],
            'categories' => ['security'],
            'min_priority' => 2,
            'client_ids' => [7],
        ]);
        $this->route(['types' => ['ticket.client_replied']]);
        $this->route(['types' => ['ticket.created'], 'categories' => ['billing']]);
        $this->route(['types' => ['ticket.created'], 'min_priority' => 1], label: 'P1 miss');
        $this->route(['types' => ['ticket.created'], 'client_ids' => [8]]);

        app(SignalRouter::class)->route($this->event('ticket.created', [
            'category' => 'security',
            'priority' => '2',
            'client_id' => '7',
        ]));

        $this->assertSame(
            [$allRoute->id, $narrowRoute->id],
            SignalDelivery::query()->orderBy('route_id')->pluck('route_id')->all(),
        );
        Bus::assertDispatchedTimes(DeliverSignal::class, 2);
    }

    public function test_route_with_category_does_not_match_event_without_category(): void
    {
        $this->route(['types' => ['ticket.created'], 'categories' => ['security']]);

        app(SignalRouter::class)->route($this->event('ticket.created'));

        $this->assertDatabaseCount('signal_deliveries', 0);
        Bus::assertNotDispatched(DeliverSignal::class);
    }

    public function test_non_routable_event_types_never_match_even_with_all_filter(): void
    {
        $this->route(['types' => 'all']);
        $this->route(['types' => ['system.test']]);

        app(SignalRouter::class)->route($this->event('system.test'));

        $this->assertDatabaseCount('signal_deliveries', 0);
        Bus::assertNotDispatched(DeliverSignal::class);
    }

    public function test_cooldown_creates_suppressed_rows_and_dispatches_nothing(): void
    {
        $route = $this->route(['types' => ['ticket.created']], cooldownSeconds: 300);
        $previousEvent = $this->event('ticket.created', entityType: 'ticket', entityId: 55);
        $previous = SignalDelivery::create([
            'event_id' => $previousEvent->id,
            'route_id' => $route->id,
            'step_order' => 1,
            'destination_id' => $route->steps->first()->destination_id,
            'status' => 'delivered',
        ]);
        SignalDelivery::whereKey($previous->id)->update(['created_at' => now()->subMinute()]);

        app(SignalRouter::class)->route($this->event('ticket.created', entityType: 'ticket', entityId: 55));

        $delivery = SignalDelivery::where('event_id', '!=', $previousEvent->id)->firstOrFail();
        $this->assertSame('suppressed', $delivery->status);
        $this->assertSame('cooldown', $delivery->error);
        Bus::assertNotDispatched(DeliverSignal::class);
    }

    public function test_causal_depth_over_three_is_suppressed(): void
    {
        $route = $this->route(['types' => ['signal.delivery_failed']]);
        $root = $this->event('ticket.created');
        $second = $this->event('signal.delivery_failed', originEventId: $root->id);
        $third = $this->event('signal.delivery_failed', originEventId: $second->id);
        $fourth = $this->event('signal.delivery_failed', originEventId: $third->id);
        $tooDeep = $this->event('signal.delivery_failed', originEventId: $fourth->id);

        app(SignalRouter::class)->route($tooDeep);

        $delivery = SignalDelivery::where('route_id', $route->id)->firstOrFail();
        $this->assertSame('suppressed', $delivery->status);
        $this->assertSame('causal-depth', $delivery->error);
        Bus::assertNotDispatched(DeliverSignal::class);
    }

    public function test_per_type_hourly_rate_limit_is_suppressed(): void
    {
        $route = $this->route(['types' => ['ticket.created']]);
        for ($i = 0; $i < 60; $i++) {
            $this->event('ticket.created', entityId: $i, occurredAt: now()->subMinutes(30));
        }
        $current = $this->event('ticket.created', entityId: 999);

        app(SignalRouter::class)->route($current);

        $delivery = SignalDelivery::where('route_id', $route->id)->firstOrFail();
        $this->assertSame('suppressed', $delivery->status);
        $this->assertSame('rate-limit', $delivery->error);
        Bus::assertNotDispatched(DeliverSignal::class);
    }

    // ── The scream (psa-28j4.4) ──────────────────────────────────────────────

    /**
     * Captures real log records off the channel.
     *
     * Deliberately NOT Log::spy() + shouldNotHaveReceived('warning', [closure]): a raw closure is
     * not a Mockery argument matcher, so it is compared by identity, never matches, and the
     * negative assertion becomes VACUOUSLY TRUE (see EmailIntakeNotifyTest::warningsDuring, which
     * was bitten by exactly that). This listener records what was really logged, so BOTH the
     * positive assertion and the stays-quiet assertion below are capable of failing.
     *
     * @return array<int, array{level: string, message: string, context: array<string, mixed>}>
     */
    private function warningsDuring(callable $work): array
    {
        $warnings = [];
        Log::listen(function ($log) use (&$warnings): void {
            if ($log->level === 'warning') {
                $warnings[] = [
                    'level' => (string) $log->level,
                    'message' => (string) $log->message,
                    'context' => (array) $log->context,
                ];
            }
        });

        $work();

        return $warnings;
    }

    /** @param array<int, array{level: string, message: string, context: array<string, mixed>}> $warnings */
    private function rateLimitAlarms(array $warnings): array
    {
        return array_values(array_filter(
            $warnings,
            fn (array $w): bool => str_contains($w['message'], 'RATE LIMIT')
        ));
    }

    /**
     * A rate-capped signal is a signal the agent never sees. Dropping it silently — a
     * status=suppressed row and nothing else — is the "clean confident nothing" of CLAUDE.md
     * rule 3: an operator cannot tell a throttled Chet path from a quiet one.
     */
    public function test_per_type_hourly_rate_limit_suppression_is_logged_loudly(): void
    {
        $route = $this->route(['types' => ['ticket.created']]);
        for ($i = 0; $i < 60; $i++) {
            $this->event('ticket.created', entityId: $i, occurredAt: now()->subMinutes(30));
        }
        $current = $this->event('ticket.created', entityId: 999);

        $warnings = $this->warningsDuring(fn () => app(SignalRouter::class)->route($current));
        $alarms = $this->rateLimitAlarms($warnings);

        $this->assertCount(
            1,
            $alarms,
            'a rate-capped signal must SCREAM — a throttled agent path looks exactly like an idle one'
        );

        // The alarm has to be actionable: which type, which route, how bad, against what cap.
        $this->assertSame('ticket.created', $alarms[0]['context']['type_key'] ?? null);
        $this->assertSame($route->id, $alarms[0]['context']['route_id'] ?? null);
        $this->assertSame(61, $alarms[0]['context']['count'] ?? null);
        $this->assertSame(SignalRouter::MAX_PER_TYPE_PER_HOUR, $alarms[0]['context']['cap'] ?? null);
    }

    /**
     * Cooldown is INTENTIONAL dedup, not a degraded read — it fires constantly in normal
     * operation and must stay quiet, or the rate-limit alarm drowns in it and stops being read.
     */
    public function test_cooldown_suppression_does_not_cry_wolf(): void
    {
        $route = $this->route(['types' => ['ticket.created']], cooldownSeconds: 300);
        $previousEvent = $this->event('ticket.created', entityType: 'ticket', entityId: 55);
        $previous = SignalDelivery::create([
            'event_id' => $previousEvent->id,
            'route_id' => $route->id,
            'step_order' => 1,
            'destination_id' => $route->steps->first()->destination_id,
            'status' => 'delivered',
        ]);
        SignalDelivery::whereKey($previous->id)->update(['created_at' => now()->subMinute()]);

        $current = $this->event('ticket.created', entityType: 'ticket', entityId: 55);
        $warnings = $this->warningsDuring(fn () => app(SignalRouter::class)->route($current));

        $this->assertSame('cooldown', SignalDelivery::where('event_id', $current->id)->sole()->error);
        $this->assertSame([], $this->rateLimitAlarms($warnings));
    }

    public function test_mcp_destination_with_revoked_token_is_suppressed(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        McpToken::where('label', 'chet')->update(['revoked_at' => now()]);
        $route = $this->mcpRoute('chet');

        app(SignalRouter::class)->route($this->event('ticket.created'));

        $delivery = SignalDelivery::where('route_id', $route->id)->sole();
        $this->assertSame('suppressed', $delivery->status);
        $this->assertSame('mcp-token-revoked', $delivery->error);
        Bus::assertNotDispatched(DeliverSignal::class);
    }

    public function test_mcp_destination_without_a_token_label_is_suppressed(): void
    {
        $route = $this->mcpRoute(null);

        app(SignalRouter::class)->route($this->event('ticket.created'));

        $delivery = SignalDelivery::where('route_id', $route->id)->sole();
        $this->assertSame('suppressed', $delivery->status);
        $this->assertSame('mcp-token-missing', $delivery->error);
        Bus::assertNotDispatched(DeliverSignal::class);
    }

    public function test_mcp_destination_with_live_token_dispatches_delivery(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $route = $this->mcpRoute('chet');

        app(SignalRouter::class)->route($this->event('ticket.created'));

        $delivery = SignalDelivery::where('route_id', $route->id)->sole();
        $this->assertSame('pending', $delivery->status);
        Bus::assertDispatched(DeliverSignal::class);
    }

    private function mcpRoute(?string $tokenLabel): SignalRoute
    {
        $route = SignalRoute::create([
            'label' => 'MCP Route '.SignalRoute::count(),
            'event_filter' => ['types' => ['ticket.created']],
            'enabled' => true,
            'cooldown_seconds' => 300,
        ]);

        $destination = SignalDestination::create([
            'label' => "MCP dest {$route->id}",
            'type' => 'mcp',
            'mcp_token_label' => $tokenLabel,
            'enabled' => true,
        ]);

        SignalRouteStep::create([
            'route_id' => $route->id,
            'step_order' => 1,
            'destination_id' => $destination->id,
        ]);

        return $route->fresh('steps');
    }

    private function route(
        array $filter,
        array $stepOrders = [1],
        bool $enabled = true,
        int $cooldownSeconds = 300,
        string $label = 'Route',
        bool $destinationEnabled = true,
    ): SignalRoute {
        $route = SignalRoute::create([
            'label' => $label.' '.SignalRoute::count(),
            'event_filter' => $filter,
            'enabled' => $enabled,
            'cooldown_seconds' => $cooldownSeconds,
        ]);

        foreach ($stepOrders as $index => $stepOrder) {
            $destination = SignalDestination::create([
                'label' => "Destination {$route->id}-{$index}",
                'type' => 'webhook',
                'address' => "https://x{$route->id}{$index}.example/hook",
                'enabled' => $destinationEnabled,
            ]);
            SignalRouteStep::create([
                'route_id' => $route->id,
                'step_order' => $stepOrder,
                'destination_id' => $destination->id,
            ]);
        }

        return $route->fresh('steps');
    }

    private function event(
        string $typeKey,
        array $context = [],
        ?string $entityType = 'ticket',
        ?int $entityId = 123,
        ?int $originEventId = null,
        mixed $occurredAt = null,
    ): SignalEvent {
        return SignalEvent::create([
            'type_key' => $typeKey,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'summary' => 'Signal event',
            'context' => $context,
            'origin_event_id' => $originEventId,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }
}
