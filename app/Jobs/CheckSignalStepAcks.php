<?php

namespace App\Jobs;

use App\Models\SignalDelivery;
use App\Models\SignalEvent;
use App\Models\SignalRoute;
use App\Models\SignalRouteStep;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;

class CheckSignalStepAcks implements ShouldQueue
{
    use Queueable;

    private const TERMINAL_STATUSES = ['acked', 'suppressed', 'timed_out', 'failed'];

    public function __construct(
        public int $eventId,
        public int $routeId,
        public int $stepOrder,
    ) {}

    public static function scheduleIfNeeded(SignalRoute $route, SignalEvent $event, int $stepOrder): void
    {
        $wait = $route->steps
            ->where('step_order', $stepOrder)
            ->pluck('wait_for_ack_seconds')
            ->filter(fn ($seconds) => $seconds !== null)
            ->first();

        if ($wait === null) {
            return;
        }

        self::dispatch($event->id, $route->id, $stepOrder)
            ->delay(now()->addSeconds((int) $wait));
    }

    public static function advanceAfterTimeout(SignalRoute $route, SignalEvent $event, int $stepOrder): void
    {
        SignalDelivery::query()
            ->where('event_id', $event->id)
            ->where('route_id', $route->id)
            ->where('step_order', $stepOrder)
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->update(['status' => 'timed_out']);

        $nextOrder = $route->steps
            ->where('step_order', '>', $stepOrder)
            ->min('step_order');

        if ($nextOrder === null) {
            return;
        }

        self::dispatchSteps($route, $event, $route->steps->where('step_order', $nextOrder)->values());
    }

    public function handle(): void
    {
        $route = SignalRoute::with('steps')->find($this->routeId);
        $event = SignalEvent::find($this->eventId);
        if ($route === null || $event === null) {
            return;
        }

        $currentDeliveries = SignalDelivery::query()
            ->where('event_id', $this->eventId)
            ->where('route_id', $this->routeId)
            ->where('step_order', $this->stepOrder)
            ->get();

        if ($currentDeliveries->isEmpty()) {
            return;
        }

        if ($currentDeliveries->contains(fn (SignalDelivery $delivery) => $delivery->status === 'acked')) {
            $hasResolutionDeadline = CheckSignalResolution::scheduleIfNeeded($route, $event, $this->stepOrder);
            $this->handleAckedBranch($route, $event, $hasResolutionDeadline);

            return;
        }

        $this->handleTimedOutBranch($route, $event);
    }

    private function handleTimedOutBranch(SignalRoute $route, SignalEvent $event): void
    {
        self::advanceAfterTimeout($route, $event, $this->stepOrder);
    }

    private function handleAckedBranch(SignalRoute $route, SignalEvent $event, bool $deferSuppressibleSteps): void
    {
        $route->steps
            ->where('step_order', '>', $this->stepOrder)
            ->groupBy('step_order')
            ->each(function ($steps) use ($route, $event, $deferSuppressibleSteps): void {
                foreach ($steps as $step) {
                    if ($step->non_suppressible) {
                        self::createPendingDelivery($route, $event, $step);
                    } elseif (! $deferSuppressibleSteps) {
                        self::createSuppressedDelivery($route, $event, $step);
                    }
                }

                if ($steps->contains(fn (SignalRouteStep $step) => $step->non_suppressible)) {
                    self::scheduleIfNeeded($route, $event, (int) $steps->first()->step_order);
                }
            });
    }

    private static function dispatchSteps(SignalRoute $route, SignalEvent $event, Collection $steps): void
    {
        foreach ($steps as $step) {
            self::createPendingDelivery($route, $event, $step);
        }

        self::scheduleIfNeeded($route, $event, (int) $steps->first()->step_order);
    }

    private static function createPendingDelivery(SignalRoute $route, SignalEvent $event, SignalRouteStep $step): SignalDelivery
    {
        $delivery = self::existingDelivery($route, $event, $step);
        if ($delivery !== null) {
            return $delivery;
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

    private static function createSuppressedDelivery(SignalRoute $route, SignalEvent $event, SignalRouteStep $step): SignalDelivery
    {
        $delivery = self::existingDelivery($route, $event, $step);
        if ($delivery !== null) {
            return $delivery;
        }

        return SignalDelivery::create([
            'event_id' => $event->id,
            'route_id' => $route->id,
            'step_order' => $step->step_order,
            'destination_id' => $step->destination_id,
            'status' => 'suppressed',
            'error' => 'acked-upstream',
        ]);
    }

    private static function existingDelivery(SignalRoute $route, SignalEvent $event, SignalRouteStep $step): ?SignalDelivery
    {
        return SignalDelivery::query()
            ->where('event_id', $event->id)
            ->where('route_id', $route->id)
            ->where('step_order', $step->step_order)
            ->where('destination_id', $step->destination_id)
            ->first();
    }
}
