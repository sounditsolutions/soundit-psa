<?php

namespace App\Enums;

/**
 * ITIL change management risk assessment. Records the assessed impact/likelihood
 * of a change on tickets whose {@see TicketType} is Change.
 */
enum RiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Low => 'bg-success',
            self::Medium => 'bg-warning text-dark',
            self::High => 'bg-danger',
        };
    }
}
