<?php

namespace App\Console\Commands;

use App\Services\Technician\Notify\OperatorNotifier;
use App\Support\TechnicianConfig;
use Illuminate\Console\Command;

class TechnicianHeartbeat extends Command
{
    protected $signature = 'technician:heartbeat';

    protected $description = "Dead-man's-switch: alert the operator if the AI Technician worker is not responding.";

    public function handle(OperatorNotifier $notifier): int
    {
        if (! TechnicianConfig::enabled()) {
            return self::SUCCESS;
        }

        $seen = TechnicianConfig::workerLastSeen();
        $interval = TechnicianConfig::heartbeatIntervalMinutes();
        $stale = $seen === null || $seen->lt(now()->subMinutes($interval));

        if (! $stale) {
            return self::SUCCESS;
        }

        // Throttle: at most one alert per interval, so a sustained outage doesn't spam.
        $lastAlert = TechnicianConfig::lastHeartbeatAlertAt();
        if ($lastAlert !== null && $lastAlert->gt(now()->subMinutes($interval))) {
            return self::SUCCESS;
        }

        $notifier->notify(
            'AI Technician — worker not responding',
            "The AI Technician queue worker hasn't checked in for over {$interval} minutes. "
            .'Inbound tickets may not be getting acknowledged or drafted. Check the soundit-psa-technician-queue worker.',
        );
        TechnicianConfig::recordHeartbeatAlert();

        return self::SUCCESS;
    }
}
