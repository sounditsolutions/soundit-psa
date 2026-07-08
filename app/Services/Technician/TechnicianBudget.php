<?php

namespace App\Services\Technician;

use App\Models\TechnicianRun;
use App\Support\TechnicianConfig;

/**
 * The Technician's daily-token ceiling (spec §11). tokens_used is recorded per
 * run by the pipeline; this sums today's runs and reports when the ceiling is
 * hit so the pipeline can hold before making any further AI calls (fail-closed).
 */
class TechnicianBudget
{
    public function usedToday(): int
    {
        return (int) TechnicianRun::whereDate('created_at', today())->sum('tokens_used');
    }

    public function dailyLimitReached(): bool
    {
        return $this->usedToday() >= TechnicianConfig::dailyTokenLimit();
    }
}
