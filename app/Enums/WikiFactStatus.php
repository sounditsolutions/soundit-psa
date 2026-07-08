<?php

namespace App\Enums;

enum WikiFactStatus: string
{
    case Unverified = 'unverified';
    case Confirmed = 'confirmed';
    case Disputed = 'disputed';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Unverified',
            self::Confirmed => 'Confirmed',
            self::Disputed => 'Disputed',
            self::Retired => 'Retired',
        };
    }

    // §8.1: badges must pair color with text — callers render label() text inside the badge.
    public function badgeClass(): string
    {
        return match ($this) {
            self::Unverified => 'bg-secondary',
            self::Confirmed => 'bg-success',
            self::Disputed => 'bg-warning text-dark',
            self::Retired => 'bg-light text-muted border',
        };
    }
}
