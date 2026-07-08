<?php

namespace App\Enums;

use Carbon\CarbonInterface;

enum BillingPeriod: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Bimonthly = 'bimonthly';
    case Quarterly = 'quarterly';
    case Semiannual = 'semiannual';
    case Annually = 'annually';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
            self::Bimonthly => 'Bimonthly',
            self::Quarterly => 'Quarterly',
            self::Semiannual => 'Semi-Annually',
            self::Annually => 'Annually',
        };
    }

    /**
     * Advance a date by one billing cycle.
     *
     * Weekly is a fixed 7-day step; every other cadence is a whole number of
     * calendar months and keeps the existing addMonths() overflow behaviour
     * (e.g. Jan 31 + 1 month = Mar 3) that Monthly/Quarterly/Annually relied on.
     * The input is not mutated — a copy is advanced and returned.
     */
    public function advance(CarbonInterface $date): CarbonInterface
    {
        return match ($this) {
            self::Weekly => $date->copy()->addWeek(),
            self::Monthly => $date->copy()->addMonths(1),
            self::Bimonthly => $date->copy()->addMonths(2),
            self::Quarterly => $date->copy()->addMonths(3),
            self::Semiannual => $date->copy()->addMonths(6),
            self::Annually => $date->copy()->addMonths(12),
        };
    }

    /**
     * Months spanned by one billing cycle, used to normalise a profile's
     * per-cycle amount to a monthly figure (MRR).
     *
     * Weekly spans ~0.23 of a month (52 weeks annualised across 12 months), so
     * this is a float and is never 0 — a 0 here would divide-by-zero the MRR
     * rollup and loop forever in the "cycles behind" catch-up math.
     */
    public function monthsPerCycle(): float
    {
        return match ($this) {
            self::Weekly => 12 / 52,
            self::Monthly => 1.0,
            self::Bimonthly => 2.0,
            self::Quarterly => 3.0,
            self::Semiannual => 6.0,
            self::Annually => 12.0,
        };
    }
}
