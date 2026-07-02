<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverSignal implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $deliveryId,
    ) {}

    public function handle(): void
    {
        //
    }
}
