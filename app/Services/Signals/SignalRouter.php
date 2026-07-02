<?php

namespace App\Services\Signals;

use App\Jobs\DeliverSignal;
use App\Models\SignalDelivery;
use App\Models\SignalEvent;
use App\Models\SignalRoute;
use Illuminate\Database\Eloquent\Collection;

class SignalRouter
{
    public const MAX_PER_TYPE_PER_HOUR = 60;

    public function route(SignalEvent $event): void
    {
        if (! SignalEventTypes::routable($event->type_key)) {
            return;
        }

        SignalRoute::query()
            ->where('enabled', true)
            ->with('steps')
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

                foreach ($steps as $step) {
                    $delivery = SignalDelivery::create([
                        'event_id' => $event->id,
                        'route_id' => $route->id,
                        'step_order' => $step->step_order,
                        'destination_id' => $step->destination_id,
                        'status' => 'pending',
                    ]);

                    DeliverSignal::dispatch($delivery->id);
                }

                $this->afterStepDispatched($route, $event, $steps->first()->step_order);
            });
    }

    protected function afterStepDispatched(SignalRoute $route, SignalEvent $event, int $stepOrder): void
    {
        //
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
