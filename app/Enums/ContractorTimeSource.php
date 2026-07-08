<?php

namespace App\Enums;

enum ContractorTimeSource: string
{
    case ManualCredit = 'manual_credit';
    case ManualDebit = 'manual_debit';
    case InitialBalance = 'initial_balance';

    public function label(): string
    {
        return match ($this) {
            self::ManualCredit => 'Credit',
            self::ManualDebit => 'Debit',
            self::InitialBalance => 'Initial Balance',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::ManualCredit, self::InitialBalance => 'bg-success',
            self::ManualDebit => 'bg-danger',
        };
    }

    public function isCredit(): bool
    {
        return in_array($this, [self::ManualCredit, self::InitialBalance]);
    }
}
