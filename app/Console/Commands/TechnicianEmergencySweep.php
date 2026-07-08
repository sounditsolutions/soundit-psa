<?php

namespace App\Console\Commands;

use App\Services\Technician\Emergency\EmergencySweep;
use App\Support\TechnicianConfig;
use Illuminate\Console\Command;

class TechnicianEmergencySweep extends Command
{
    protected $signature = 'technician:emergency-sweep';

    protected $description = 'AI Technician emergency backstop: detect → group → escalate → max-hold for open tickets while the operator is away.';

    public function handle(): int
    {
        // Disabled ⇒ a hard no-op. The schedule guard already gates this; the command
        // re-checks so a manual/queued invocation can never act while the backstop is off.
        if (! TechnicianConfig::emergencyBackstopEnabled()) {
            return self::SUCCESS;
        }

        app(EmergencySweep::class)->run();

        return self::SUCCESS;
    }
}
