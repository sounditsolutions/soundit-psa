<?php

namespace App\Services\Agent;

/**
 * One confidence band's outcome tally for the close-calibration instrument
 * (psa-91f2). The counters are mutable so the evaluator can accumulate rows
 * into a fixed set of band objects in a single pass.
 */
class BandStat
{
    public function __construct(
        public readonly string $label,
        public readonly float $low,
        public readonly float $high,
        public int $total = 0,
        public int $approved = 0,
        public int $declined = 0,
        public int $corrected = 0,
        public int $pending = 0,
        public int $other = 0,
    ) {}

    /** Decided = a clean operator verdict (approved or declined); the approveRate denominator. */
    public function decided(): int
    {
        return $this->approved + $this->declined;
    }

    /**
     * Share of decided proposals the operator approved. NULL when nothing in the
     * band has been decided yet (no denominator — reported as "—", never 0%).
     */
    public function approveRate(): ?float
    {
        return $this->decided() === 0 ? null : (float) $this->approved / $this->decided();
    }
}
