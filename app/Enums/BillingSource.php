<?php

namespace App\Enums;

enum BillingSource: string
{
    case Psa = 'psa';

    public function label(): string
    {
        return match ($this) {
            self::Psa => 'PSA',
        };
    }
}
