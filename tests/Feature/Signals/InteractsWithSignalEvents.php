<?php

namespace Tests\Feature\Signals;

use App\Models\SignalEvent;

trait InteractsWithSignalEvents
{
    protected function assertSingleSignalEvent(string $typeKey): SignalEvent
    {
        $events = SignalEvent::query()->where('type_key', $typeKey)->get();

        $this->assertSame(1, $events->count(), "Expected exactly one {$typeKey} signal event.");

        /** @var SignalEvent $event */
        $event = $events->first();

        return $event;
    }
}
