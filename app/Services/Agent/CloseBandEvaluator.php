<?php

namespace App\Services\Agent;

use App\Enums\TechnicianRunState;
use App\Models\TechnicianRun;

/**
 * The repeatable close-calibration instrument (psa-91f2).
 *
 * Reads held propose_close TechnicianRuns and buckets them by Chet's
 * self-reported confidence, tallying the operator outcome (terminal run state)
 * per band. The per-band approval rate is the empirical signal for which
 * confidence band is "auto-safe" — the evidence that would defensibly set
 * propose_close_auto_threshold (psa-y4ft). The operator's approve/decline IS
 * the label, so this instrument needs no separate labelled set.
 *
 * Read-only: no writes, no side effects. Run against whatever database it is
 * pointed at (dev fixture or a prod snapshot).
 */
class CloseBandEvaluator
{
    /**
     * Confidence bands, [low, high). Boundaries sit on the decision points:
     * the approve floor (0.50 — nothing weaker is ever recorded), the auto-close
     * floor (0.90), and the tool's "use ≥0.95 only when unambiguous" line. The
     * top band's high is 1.01 so a confidence of exactly 1.00 falls inside it.
     */
    public const BANDS = [
        [0.50, 0.70],
        [0.70, 0.80],
        [0.80, 0.90],
        [0.90, 0.95],
        [0.95, 1.01],
    ];

    /** @return list<BandStat> ordered low → high */
    public function evaluate(): array
    {
        $bands = [];
        foreach (self::BANDS as [$low, $high]) {
            $bands[] = new BandStat($this->label($low, $high), $low, $high);
        }

        TechnicianRun::query()
            ->where('action_type', 'propose_close')
            ->whereNotNull('confidence')
            ->get(['confidence', 'state'])
            ->each(function (TechnicianRun $run) use ($bands): void {
                $stat = $this->bandFor($bands, (float) $run->confidence);
                if ($stat === null) {
                    return; // out of the surfaced range (below the approve floor) — ignore
                }
                $stat->total++;
                match ($run->state) {
                    TechnicianRunState::Done => $stat->approved++,
                    TechnicianRunState::Denied => $stat->declined++,
                    TechnicianRunState::Superseded => $stat->corrected++,
                    TechnicianRunState::Gathering,
                    TechnicianRunState::Drafting,
                    TechnicianRunState::AwaitingApproval,
                    TechnicianRunState::Executing,
                    TechnicianRunState::QueuedOffline => $stat->pending++,
                    default => $stat->other++, // Expired, Cancelled, Flagged
                };
            });

        return $bands;
    }

    private function bandFor(array $bands, float $confidence): ?BandStat
    {
        foreach ($bands as $b) {
            if ($confidence >= $b->low && $confidence < $b->high) {
                return $b;
            }
        }

        return null;
    }

    private function label(float $low, float $high): string
    {
        // The top band stores high = 1.01 (to include 1.00) but prints as "0.95–1.00".
        return number_format($low, 2).'–'.number_format(min($high, 1.00), 2);
    }
}
