<?php

namespace App\Enums;

enum ContractType: string
{
    case Managed = 'managed';
    case BreakFix = 'breakfix';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Managed => 'Managed Services',
            self::BreakFix => 'Break-Fix',
            self::Custom => 'Custom',
        };
    }
}
