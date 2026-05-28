<?php

namespace App\Enums;

enum AlertStatus: string
{
    case Active = 'active';
    case Acknowledged = 'acknowledged';
    case Ticketed = 'ticketed';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Acknowledged => 'Acknowledged',
            self::Ticketed => 'Ticketed',
            self::Resolved => 'Resolved',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'bg-danger',
            self::Acknowledged => 'bg-info',
            self::Ticketed => 'bg-primary',
            self::Resolved => 'bg-secondary',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Active, self::Acknowledged, self::Ticketed]);
    }
}
