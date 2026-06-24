<?php

namespace App\Jobs;

use App\Support\TechnicianConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A tiny liveness ping dispatched onto the dedicated 'technician' queue on a
 * schedule. The worker processing it proves it is draining its queue even when no
 * tickets are flowing; it records the heartbeat the dead-man's-switch watches.
 */
class TechnicianPing implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('technician');
    }

    public function handle(): void
    {
        TechnicianConfig::recordWorkerSeen();
    }
}
