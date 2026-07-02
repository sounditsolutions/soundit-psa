<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RouteSignalEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $eventId,
    ) {}

    public function handle(): void
    {
        //
    }
}
