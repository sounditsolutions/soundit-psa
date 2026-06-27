<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Scheduling decisions for the AI-triage review pass (triage:review-open) — extracted
 * from the routes/console.php ->when() closure so the throttle is UNIT-TESTABLE.
 *
 * The review pass is the trip-critical agent dispatch: close + reply + flag + emergency
 * review all ride it. A throttle bug here silently kills the entire agent (it did — a
 * 12.7h stall on 2026-06-26/27), so the due-decision lives here, behind tests.
 *
 * Carbon footgun (psa-lqlu): `now()->diffInMinutes($past)` is SIGNED in Carbon 3 (returns
 * a NEGATIVE value for a past timestamp), so the old `now()->diffInMinutes($lastRun) < $freq`
 * check was ALWAYS true once last-run was set — the pass never reran. The due-check here is
 * sign-agnostic (`$lastRun + $freq is in the past`) and must stay that way.
 */
class TriageSchedule
{
    private const LAST_RUN_KEY = 'triage:review-open:last-run';

    /**
     * Is the review pass due to run now? True iff auto-review is enabled AND it has either
     * never run or at least the configured frequency has elapsed since the last run.
     */
    public static function reviewPassDue(): bool
    {
        if (! TriageConfig::autoReviewEnabled()) {
            return false;
        }

        $lastRun = self::lastRun();
        if ($lastRun === null) {
            return true; // never run → due
        }

        $freq = TriageConfig::reviewFrequencyMinutes();

        // Sign-safe: due iff (last run + frequency) is now in the past. Unambiguous
        // regardless of diffInMinutes' sign convention across Carbon versions — the
        // footgun that wedged this pass for 12.7h (a negative signed diff is always < freq).
        return $lastRun->copy()->addMinutes($freq)->isPast();
    }

    /** Mark the review pass as having run now (drives the next-due calculation). */
    public static function markReviewPassRun(): void
    {
        Cache::put(self::LAST_RUN_KEY, now(), now()->addHours(24));
    }

    /** When the review pass last ran, or null if never / unset. */
    public static function lastRun(): ?Carbon
    {
        $value = Cache::get(self::LAST_RUN_KEY);
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value);
    }
}
