<?php

namespace App\Console\Commands;

use App\Services\Agent\BandStat;
use App\Services\Agent\CloseBandEvaluator;
use Illuminate\Console\Command;

/**
 * agent:eval-close-band (psa-91f2) — the repeatable close-calibration report.
 *
 * Read-only. Prints propose_close approval rate BY Chet's self-reported
 * confidence band, so an operator can read which band is "auto-safe" (high N,
 * no declines) — the evidence that would defensibly set propose_close_auto_
 * threshold (psa-y4ft). The operator's approve/decline IS the label, so no
 * separate labelled set is needed. Run it against whatever database it points
 * at (a dev fixture, or a prod snapshot for the real numbers).
 *
 * --since=N scopes to proposals created within the last N days: the calibration
 * target is RECENT MCP-Chet judgment, not all-time history that may span
 * pre-quiesce eras and skew the auto-safe band.
 */
class AgentEvalCloseBand extends Command
{
    protected $signature = 'agent:eval-close-band {--since= : Score only proposals created within the last N days (default: all-time)}';

    protected $description = 'Calibration report: propose_close approval rate by Chet confidence band (read-only).';

    public function handle(CloseBandEvaluator $evaluator): int
    {
        $sinceDays = null;
        $sinceRaw = $this->option('since');
        if ($sinceRaw !== null && $sinceRaw !== '') {
            if (! ctype_digit((string) $sinceRaw) || (int) $sinceRaw < 1) {
                $this->error('--since must be a positive integer number of days.');

                return self::FAILURE;
            }
            $sinceDays = (int) $sinceRaw;
        }

        $bands = $evaluator->evaluate($sinceDays);
        $window = $sinceDays !== null ? "last {$sinceDays} days" : 'all-time';

        $graded = array_sum(array_map(fn (BandStat $b) => $b->total, $bands));
        if ($graded === 0) {
            $this->info("No held propose_close proposals with a confidence to score yet ({$window}).");

            return self::SUCCESS;
        }

        $this->info("AI Technician — propose_close calibration by confidence band ({$window})");
        $this->line('Approve rate = approved / (approved + declined). Corrected, pending & other are shown but excluded from the rate.');
        $this->newLine();

        $this->table(
            ['Band', 'N', 'Approved', 'Declined', 'Corrected', 'Pending', 'Other', 'Approve rate'],
            array_map(fn (BandStat $b) => [
                $b->label,
                $b->total,
                $b->approved,
                $b->declined,
                $b->corrected,
                $b->pending,
                $b->other,
                $this->formatRate($b),
            ], $bands),
        );

        return self::SUCCESS;
    }

    private function formatRate(BandStat $b): string
    {
        $rate = $b->approveRate();

        return $rate === null ? '—' : number_format($rate * 100, 1).'%';
    }
}
