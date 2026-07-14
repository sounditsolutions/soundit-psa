<?php

namespace App\Console\Commands;

use App\Services\Technician\StaleClaimReaper;
use Illuminate\Console\Command;

/**
 * psa-xz0z — scheduled safety net that returns TechnicianRuns stranded in 'executing' (by a
 * process death or a deploy restarting PHP-FPM mid-approval) back to the approval queue, so an
 * approvable action is never silently lost behind an "already handled" message. Not gated by any
 * integration config: the approval path is core and stranding is a pure-PSA reliability gap.
 */
class TechnicianReapStaleClaims extends Command
{
    protected $signature = 'technician:reap-stale-claims';

    protected $description = 'Return TechnicianRuns stranded in "executing" (process death / deploy) to the approval queue.';

    public function handle(StaleClaimReaper $reaper): int
    {
        $summary = $reaper->reap();
        $this->info("Stale-claim reaper: returned {$summary['reaped']} stranded run(s) to the approval queue.");

        return self::SUCCESS;
    }
}
