<?php

namespace App\Jobs;

use App\Models\SignalEvent;
use App\Models\SignalRoute;
use App\Services\Signals\SignalResolutions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckSignalResolution implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $eventId,
        public int $routeId,
        public int $stepOrder,
    ) {}

    public static function scheduleIfNeeded(SignalRoute $route, SignalEvent $event, int $stepOrder): bool
    {
        $resolveWithin = $route->steps
            ->where('step_order', $stepOrder)
            ->pluck('resolve_within_seconds')
            ->filter(fn ($seconds) => $seconds !== null)
            ->first();

        if ($resolveWithin === null) {
            return false;
        }

        self::dispatch($event->id, $route->id, $stepOrder)
            ->delay(now()->addSeconds((int) $resolveWithin));

        return true;
    }

    public function handle(): void
    {
        $route = SignalRoute::with('steps')->find($this->routeId);
        $event = SignalEvent::find($this->eventId);
        if ($route === null || $event === null) {
            return;
        }

        if (app(SignalResolutions::class)->isResolved($event)) {
            return;
        }

        CheckSignalStepAcks::advanceAfterTimeout($route, $event, $this->stepOrder);
    }
}
