<?php

namespace App\Enums;

enum TicketPriority: string
{
    case P1 = 'p1';
    case P2 = 'p2';
    case P3 = 'p3';
    case P4 = 'p4';

    public function label(): string
    {
        return match ($this) {
            self::P1 => 'P1 - Critical',
            self::P2 => 'P2 - High',
            self::P3 => 'P3 - Medium',
            self::P4 => 'P4 - Low',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::P1 => 'bg-danger',
            self::P2 => 'bg-warning text-dark',
            self::P3 => 'bg-info text-dark',
            self::P4 => 'bg-secondary',
        };
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::P1 => 1,
            self::P2 => 2,
            self::P3 => 3,
            self::P4 => 4,
        };
    }

    public function defaultSlaHours(): int
    {
        return match ($this) {
            self::P1 => config('tickets.sla_hours.p1', 4),
            self::P2 => config('tickets.sla_hours.p2', 8),
            self::P3 => config('tickets.sla_hours.p3', 24),
            self::P4 => config('tickets.sla_hours.p4', 72),
        };
    }
}
