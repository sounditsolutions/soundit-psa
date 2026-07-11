<?php

namespace App\Console\Commands;

use App\Services\Briefing\DailyBriefingService;
use App\Support\BriefingConfig;
use Illuminate\Console\Command;

class SendDailyBriefings extends Command
{
    protected $signature = 'briefing:send-daily
                            {--dry-run : Preview recipients without sending or recording}
                            {--user= : Send only to a single technician by user ID}';

    protected $description = 'Email each active technician their personalized daily briefing '
        .'(open tickets, SLA risks, overnight alerts, voicemails, and AI-suggested next actions).';

    public function handle(DailyBriefingService $service): int
    {
        // Re-check inside handle so a manual invocation respects the toggle too
        // (the schedule guard is only a cheap early-exit).
        if (! BriefingConfig::isEnabled()) {
            $this->warn('Daily briefings are disabled (briefing_enabled setting is off).');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $onlyUser = $this->option('user') !== null ? (int) $this->option('user') : null;

        if ($dryRun) {
            $this->info('[DRY RUN] No emails will be sent and nothing will be recorded.');
        }

        $stats = $service->run($dryRun, $onlyUser);

        foreach ($stats['previews'] as $line) {
            $this->line('  Would brief: '.$line);
        }

        $this->info(sprintf(
            'Briefings — %d sent, %d already sent today, %d nothing-to-report, %d opted-out, %d failed (%d candidates).',
            $stats['sent'],
            $stats['skipped_already'],
            $stats['skipped_empty'],
            $stats['skipped_optout'],
            $stats['failed'],
            $stats['candidates'],
        ));

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
