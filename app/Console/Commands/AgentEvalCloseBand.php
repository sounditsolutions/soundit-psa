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
 */
class AgentEvalCloseBand extends Command
{
    protected $signature = 'agent:eval-close-band';

    protected $description = 'Calibration report: propose_close approval rate by Chet confidence band (read-only).';

    public function handle(CloseBandEvaluator $evaluator): int
    {
        $bands = $evaluator->evaluate();

        $graded = array_sum(array_map(fn (BandStat $b) => $b->total, $bands));
        if ($graded === 0) {
            $this->info('No held propose_close proposals with a confidence to score yet.');

            return self::SUCCESS;
        }

        $this->info('AI Technician — propose_close calibration by confidence band');
        $this->line('Approve rate = approved / (approved + declined). Corrected & pending are shown but excluded from the rate.');
        $this->newLine();

        $this->table(
            ['Band', 'N', 'Approved', 'Declined', 'Corrected', 'Pending', 'Approve rate'],
            array_map(fn (BandStat $b) => [
                $b->label,
                $b->total,
                $b->approved,
                $b->declined,
                $b->corrected,
                $b->pending,
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
