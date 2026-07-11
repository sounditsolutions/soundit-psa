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

    /**
     * psa-za3g: sort weight for review surfaces — lower sorts first. Actionable
     * facts (Unverified needs Confirm/Correct/Retire; Disputed needs an AI-challenge
     * decision) come before Confirmed history so a technician isn't forced to scroll
     * a long stack of confirmed entries to find what still needs a decision. The
     * order mirrors the ambient section summary ("unverified · disputed"). Retired
     * is excluded from those surfaces but ranked last for completeness.
     */
    public function reviewSortOrder(): int
    {
        return match ($this) {
            self::Unverified => 0,
            self::Disputed => 1,
            self::Confirmed => 2,
            self::Retired => 3,
        };
    }
}
