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
        return ucfirst($this->value);
    }

    // §8.1: badges must pair color with text — callers render label() text inside the badge.
    public function badgeClass(): string
    {
        return match ($this) {
            self::Unverified => 'badge bg-secondary',
            self::Confirmed => 'badge bg-success',
            self::Disputed => 'badge bg-warning text-dark',
            self::Retired => 'badge bg-light text-muted border',
        };
    }
}
