<?php

namespace App\Services\Signals;

use App\Jobs\CheckSignalStepAcks;
use App\Jobs\DeliverSignal;
use App\Models\McpToken;
use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalEventTypeSetting;
use App\Models\SignalRoute;
use App\Models\SignalRouteStep;
use App\Support\McpConfig;
use Illuminate\Database\Eloquent\Collection;

class SignalRouter
{
    public const MAX_PER_TYPE_PER_HOUR = 60;

    /**
     * The operator-bridge tool an agent uses to DRAIN a signal destination's inbox. A token
     * that is not granted this tool can never read what we deliver, so a destination pointing
     * at one is not consumable (see wouldReachMcpDestination()). Name per OperatorBridgeTools.
     */
    private const POLL_SIGNALS_TOOL = 'poll_signals';

    public function route(SignalEvent $event): void
    {
        if (! SignalEventTypes::routable($event->type_key)) {
            return;
        }

        // D4 global per-type master gate (psa-0j6i): a globally-disabled type delivers
        // through NO route. Default-safe — with no overlay row every type is enabled, so
        // this is a no-op and existing routing is byte-identical. Non-lossy: per-route
        // config is untouched, so re-enabling restores delivery.
        if (! SignalEventTypeSetting::isTypeGloballyEnabled($event->type_key)) {
            return;
        }

        SignalRoute::query()
            ->where('enabled', true)
            ->with('steps.destination')
            ->get()
            ->each(function (SignalRoute $route) use ($event): void {
                if (! $this->matches($route, $event)) {
                    return;
                }

                $steps = $this->firstSteps($route);
                if ($steps->isEmpty()) {
                    return;
                }

                $suppressionReason = $this->suppressionReason($route, $event);
                if ($suppressionReason !== null) {
                    $this->createSuppressedDeliveries($route, $event, $steps, $suppressionReason);

                    return;
                }

                $pendingCount = 0;
                foreach ($steps as $step) {
                    if ($this->createDelivery($route, $event, $step)->status === 'pending') {
                        $pendingCount++;
                    }
                }

                if ($pendingCount > 0) {
                    $this->afterStepDispatched($route, $event, $steps->first()->step_order);
                }
            });
    }

    protected function afterStepDispatched(SignalRoute $route, SignalEvent $event, int $stepOrder): void
    {
        CheckSignalStepAcks::scheduleIfNeeded($route, $event, $stepOrder);
    }

    /**
     * Would an event of $typeKey carrying $context actually be DELIVERED to an enabled
     * MCP destination — i.e. land in an inbox an agent can poll?
     *
     * This exists so "nobody is watching the support inbox" can be DETECTED and shouted
     * about instead of silently entered (psa-28j4.3). It deliberately reuses matches()
     * and mcpDeliveryBlockReason() rather than re-deriving them, because a second copy
     * of the filter semantics WOULD drift — and the traps here are precisely the ones a
     * naive re-implementation misses:
     *   - types are EXACT-match; "intake.*" matches nothing (only the literal "all" wildcards);
     *   - a route carrying min_priority or categories matches NO intake event at all,
     *     because the intake emissions carry neither (only client_id) and matches() hard-
     *     fails a null context key. Such a route looks correct and delivers zero emails.
     * Asking the real matcher is the only answer that cannot rot.
     *
     * Scope: this answers "is a path WIRED", not "will this one event survive the rate
     * limit / cooldown" — those are runtime and are reported at the point of suppression.
     *
     * Conservative by construction: it inspects firstSteps() — the steps that actually fire
     * when the event lands — so it can never return a false all-clear. An MCP destination
     * parked on a later escalation tier reads as unwatched, which is the safe direction to
     * be wrong in: a spurious warning is an annoyance, a false all-clear loses the inbox.
     *
     * "Reach" here means CONSUMABLE, not merely deliverable. A live, non-revoked token label
     * is not enough: the staff MCP boundary only lets a SCOPED token granted poll_signals
     * drain a destination's inbox (McpStaffController::toolAllowed(), bridge branch), and
     * poll_signals is precisely how an agent reads what we deliver. So we additionally require
     * the destination's token to be authorized for it — otherwise a delivered signal lands in
     * an inbox nobody can read, which is a black hole wearing a live label. This closes the
     * false all-clear the psa-28j4.3 review gate found in the original label-only check.
     */
    public function wouldReachMcpDestination(string $typeKey, array $context = []): bool
    {
        if (! SignalEventTypes::routable($typeKey)) {
            return false;
        }

        // Honor the D4 master gate: a globally-disabled type reaches nobody (psa-0j6i).
        if (! SignalEventTypeSetting::isTypeGloballyEnabled($typeKey)) {
            return false;
        }

        $probe = new SignalEvent(['type_key' => $typeKey, 'context' => $context]);

        return SignalRoute::query()
            ->where('enabled', true)
            ->with('steps.destination')
            ->get()
            ->contains(fn (SignalRoute $route): bool => $this->matches($route, $probe)
                && $this->firstSteps($route)->contains(
                    fn (SignalRouteStep $step): bool => (bool) $step->destination?->enabled
                        && $step->destination->type === 'mcp'
                        && $this->mcpDeliveryBlockReason($step->destination) === null
                        && McpConfig::labelCanUseBridgeTool($step->destination->mcp_token_label, self::POLL_SIGNALS_TOOL)
                )
            );
    }

    private function matches(SignalRoute $route, SignalEvent $event): bool
    {
        $filter = $route->event_filter ?? [];
        $types = $filter['types'] ?? [];

        if ($types !== 'all' && ! in_array($event->type_key, (array) $types, true)) {
            return false;
        }

        if (array_key_exists('categories', $filter)) {
            $category = $event->context['category'] ?? null;
            if ($category === null || ! in_array((string) $category, $filter['categories'], true)) {
                return false;
            }
        }

        if (array_key_exists('min_priority', $filter)) {
            $priority = $event->context['priority'] ?? null;
            if ($priority === null || (int) $priority > (int) $filter['min_priority']) {
                return false;
            }
        }

        if (array_key_exists('client_ids', $filter)) {
            $clientId = $event->context['client_id'] ?? null;
            if ($clientId === null || ! in_array((int) $clientId, array_map('intval', $filter['client_ids']), true)) {
                return false;
            }
        }

        return true;
    }

    private function firstSteps(SignalRoute $route): Collection
    {
        if ($route->steps->isEmpty()) {
            return new Collection;
        }

        $firstOrder = $route->steps->min('step_order');

        return $route->steps->where('step_order', $firstOrder)->values();
    }

    private function suppressionReason(SignalRoute $route, SignalEvent $event): ?string
    {
        if ($this->causalDepth($event) > 3) {
            return 'causal-depth';
        }

        if ($this->hourlyTypeCount($event) > self::MAX_PER_TYPE_PER_HOUR) {
            return 'rate-limit';
        }

        if ($this->inCooldown($route, $event)) {
            return 'cooldown';
        }

        return null;
    }

    private function createSuppressedDeliveries(SignalRoute $route, SignalEvent $event, Collection $steps, string $reason): void
    {
        foreach ($steps as $step) {
            SignalDelivery::create([
                'event_id' => $event->id,
                'route_id' => $route->id,
                'step_order' => $step->step_order,
                'destination_id' => $step->destination_id,
                'status' => 'suppressed',
                'error' => $reason,
            ]);
        }
    }

    private function createDelivery(SignalRoute $route, SignalEvent $event, SignalRouteStep $step): SignalDelivery
    {
        if (! $step->destination?->enabled) {
            return SignalDelivery::create([
                'event_id' => $event->id,
                'route_id' => $route->id,
                'step_order' => $step->step_order,
                'destination_id' => $step->destination_id,
                'status' => 'suppressed',
                'error' => 'destination-disabled',
            ]);
        }

        // A revoked MCP token can never authenticate to poll again, so delivering
        // to a destination that points at one (or at no token at all) only piles up
        // signal_inbox rows nobody can read. Suppress instead of enqueueing.
        $mcpBlockReason = $this->mcpDeliveryBlockReason($step->destination);
        if ($mcpBlockReason !== null) {
            return SignalDelivery::create([
                'event_id' => $event->id,
                'route_id' => $route->id,
                'step_order' => $step->step_order,
                'destination_id' => $step->destination_id,
                'status' => 'suppressed',
                'error' => $mcpBlockReason,
            ]);
        }

        $delivery = SignalDelivery::create([
            'event_id' => $event->id,
            'route_id' => $route->id,
            'step_order' => $step->step_order,
            'destination_id' => $step->destination_id,
            'status' => 'pending',
        ]);

        DeliverSignal::dispatch($delivery->id);

        return $delivery;
    }

    /**
     * Why an MCP destination cannot be delivered to right now, or null if it can.
     * Non-MCP destinations are never blocked here. Draft/paused tokens are fine —
     * only a revoked or absent token means nobody will ever poll the queue.
     */
    private function mcpDeliveryBlockReason(SignalDestination $destination): ?string
    {
        if ($destination->type !== 'mcp') {
            return null;
        }

        if (trim((string) $destination->mcp_token_label) === '') {
            return 'mcp-token-missing';
        }

        return McpToken::hasLiveLabel($destination->mcp_token_label) ? null : 'mcp-token-revoked';
    }

    private function causalDepth(SignalEvent $event): int
    {
        $depth = 0;
        $originEventId = $event->origin_event_id;

        while ($originEventId !== null) {
            $depth++;
            if ($depth > 3) {
                return $depth;
            }

            $originEventId = SignalEvent::query()->whereKey($originEventId)->value('origin_event_id');
        }

        return $depth;
    }

    private function hourlyTypeCount(SignalEvent $event): int
    {
        return SignalEvent::query()
            ->where('type_key', $event->type_key)
            ->where('occurred_at', '>', now()->subHour())
            ->count();
    }

    private function inCooldown(SignalRoute $route, SignalEvent $event): bool
    {
        return SignalDelivery::query()
            ->where('route_id', $route->id)
            ->where('status', '!=', 'suppressed')
            ->where('created_at', '>', now()->subSeconds($route->cooldown_seconds))
            ->whereHas('event', function ($query) use ($event): void {
                $query->where('type_key', $event->type_key)
                    ->where('entity_type', $event->entity_type)
                    ->where('entity_id', $event->entity_id);
            })
            ->exists();
    }
}
