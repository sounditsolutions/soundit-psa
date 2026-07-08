<?php

namespace App\Console\Commands;

use App\Services\Agent\Escalation\EscalationSweep;
use App\Support\AgentConfig;
use Illuminate\Console\Command;

/**
 * Agent escalation sweep — re-delivers unacked flag_attention escalations and
 * advances them up the operator chain so nothing is ever silently stuck.
 *
 * Dormant when agent_escalation_enabled is off: the ->when() schedule guard
 * prevents the job from even being dispatched, and the command itself also
 * early-exits so manual invocations are safe.
 */
class AgentEscalationSweep extends Command
{
    protected $signature = 'agent:escalation-sweep';

    protected $description = 'Re-deliver unacked AI Technician flag escalations up the operator chain.';

    public function handle(EscalationSweep $sweep): int
    {
        if (! AgentConfig::escalationEnabled()) {
            $this->info('Agent escalation is disabled — nothing to do.');

            return self::SUCCESS;
        }

        $n = $sweep->sweep();
        $this->info("Re-nudged {$n} unacked escalation(s).");

        return self::SUCCESS;
    }
}
