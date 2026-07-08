<?php

namespace App\Enums;

enum ChargeClassification: string
{
    case Billable = 'billable';
    case NoCharge = 'no_charge';

    public function label(): string
    {
        return match ($this) {
            self::Billable => 'Billable',
            self::NoCharge => 'No Charge',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Billable => 'bg-warning text-dark',
            self::NoCharge => 'bg-secondary',
        };
    }
}
