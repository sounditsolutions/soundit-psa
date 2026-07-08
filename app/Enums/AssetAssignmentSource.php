<?php

namespace App\Enums;

enum AssetAssignmentSource: string
{
    case Auto = 'auto';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Auto',
            self::Manual => 'Manual',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Auto => 'bg-secondary',
            self::Manual => 'bg-primary',
        };
    }
}
