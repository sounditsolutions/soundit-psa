<?php

namespace App\Jobs;

use App\Models\SignalDelivery;
use App\Models\SignalEvent;
use App\Models\SignalRoute;
use App\Models\SignalRouteStep;
use Illuminate\Contracts\Queue\ShouldQueue;
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
            $this->handleAckedBranch($route, $event);

            return;
        }

        $this->handleTimedOutBranch($route, $event);
    }

    private function handleTimedOutBranch(SignalRoute $route, SignalEvent $event): void
    {
        SignalDelivery::query()
            ->where('event_id', $event->id)
            ->where('route_id', $route->id)
            ->where('step_order', $this->stepOrder)
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->update(['status' => 'timed_out']);

        $nextOrder = $route->steps
            ->where('step_order', '>', $this->stepOrder)
            ->min('step_order');

        if ($nextOrder === null) {
            return;
        }

        $this->dispatchSteps($route, $event, $route->steps->where('step_order', $nextOrder)->values());
    }

    private function handleAckedBranch(SignalRoute $route, SignalEvent $event): void
    {
        $route->steps
            ->where('step_order', '>', $this->stepOrder)
            ->groupBy('step_order')
            ->each(function ($steps) use ($route, $event): void {
                foreach ($steps as $step) {
                    if ($step->non_suppressible) {
                        $this->createPendingDelivery($route, $event, $step);
                    } else {
                        $this->createSuppressedDelivery($route, $event, $step);
                    }
                }

                if ($steps->contains(fn (SignalRouteStep $step) => $step->non_suppressible)) {
                    self::scheduleIfNeeded($route, $event, (int) $steps->first()->step_order);
                }
            });
    }

    private function dispatchSteps(SignalRoute $route, SignalEvent $event, $steps): void
    {
        foreach ($steps as $step) {
            $this->createPendingDelivery($route, $event, $step);
        }

        self::scheduleIfNeeded($route, $event, (int) $steps->first()->step_order);
    }

    private function createPendingDelivery(SignalRoute $route, SignalEvent $event, SignalRouteStep $step): SignalDelivery
    {
        $delivery = $this->existingDelivery($route, $event, $step);
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

    private function createSuppressedDelivery(SignalRoute $route, SignalEvent $event, SignalRouteStep $step): SignalDelivery
    {
        $delivery = $this->existingDelivery($route, $event, $step);
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

    private function existingDelivery(SignalRoute $route, SignalEvent $event, SignalRouteStep $step): ?SignalDelivery
    {
        return SignalDelivery::query()
            ->where('event_id', $event->id)
            ->where('route_id', $route->id)
            ->where('step_order', $step->step_order)
            ->where('destination_id', $step->destination_id)
            ->first();
    }
}
