<?php

namespace App\Console\Commands;

use App\Services\Technician\Notify\OperatorNotifier;
use App\Support\TechnicianConfig;
use App\Support\TriageConfig;
use App\Support\TriageSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class TechnicianHeartbeat extends Command
{
    protected $signature = 'technician:heartbeat';

    protected $description = "Dead-man's-switch: alert the operator if the AI Technician worker — or the agent review pass — has gone silent.";

    public function handle(OperatorNotifier $notifier): int
    {
        $this->checkWorkerLiveness($notifier);
        $this->checkReviewPassStaleness($notifier);

        return self::SUCCESS;
    }

    /** Alert if the inbound queue worker hasn't checked in (the original dead-man's-switch). */
    private function checkWorkerLiveness(OperatorNotifier $notifier): void
    {
        if (! TechnicianConfig::emergencyBackstopEnabled()) {
            return;
        }

        $seen = TechnicianConfig::workerLastSeen();
        $interval = TechnicianConfig::heartbeatIntervalMinutes();
        $stale = $seen === null || $seen->lt(now()->subMinutes($interval));

        if (! $stale) {
            return;
        }

        // Throttle: at most one alert per interval, so a sustained outage doesn't spam.
        $lastAlert = TechnicianConfig::lastHeartbeatAlertAt();
        if ($lastAlert !== null && $lastAlert->gt(now()->subMinutes($interval))) {
            return;
        }

        $notifier->notify(
            'AI Technician — worker not responding',
            "The AI Technician queue worker hasn't checked in for over {$interval} minutes. "
            .'AI Technician jobs or emergency backstop pings may not be running. Check the soundit-psa-technician-queue worker.',
        );
        TechnicianConfig::recordHeartbeatAlert();
    }

    /**
     * Alert if the agent REVIEW PASS (triage:review-open) has gone silent while auto-review
     * is enabled (psa-lqlu). The whole agent — close, reply, flag, emergency review — rides
     * that pass; a throttle bug once wedged it dead for 12.7h with NOTHING surfacing it
     * (worker alive, 0 failed jobs, no mail). This detects the staleness so it can't again.
     */
    private function checkReviewPassStaleness(OperatorNotifier $notifier): void
    {
        if (! TriageConfig::autoReviewEnabled()) {
            return;
        }

        $lastRun = TriageSchedule::lastRun();
        if ($lastRun === null) {
            // Not yet established (fresh deploy) — the wedge SETS last-run once then freezes
            // it, so the non-null-stale branch below catches the real failure mode.
            return;
        }

        $freq = TriageConfig::reviewFrequencyMinutes();
        // Sign-safe staleness (do NOT reintroduce the diffInMinutes footgun): stale iff the
        // last run is before now − max(2×freq, freq+5) minutes.
        $threshold = now()->subMinutes(max(2 * $freq, $freq + 5));
        if (! $lastRun->lt($threshold)) {
            return; // fresh enough
        }

        // Throttle: at most one stalled-agent alert per frequency window.
        $alertKey = 'triage:review-open:stale-alerted';
        if (Cache::has($alertKey)) {
            return;
        }

        $notifier->notify(
            'AI agent review pass STALLED',
            'The AI-triage review pass (triage:review-open) — which dispatches the agent\'s close, reply, '
            ."flag and emergency review — last ran {$lastRun->diffForHumans()} (frequency is {$freq} min). "
            .'The agent may be silently dead; check the scheduler and the review-pass throttle.',
        );
        Cache::put($alertKey, true, now()->addMinutes($freq));
    }
}
