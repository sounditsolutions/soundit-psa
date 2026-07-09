<?php

namespace App\Enums;

/**
 * ITIL change management change type. Set on tickets whose {@see TicketType} is
 * Change to record how the change is authorised.
 */
enum ChangeType: string
{
    case Standard = 'standard';
    case Normal = 'normal';
    case Emergency = 'emergency';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard',
            self::Normal => 'Normal',
            self::Emergency => 'Emergency',
        };
    }

    /** One-line description of when each change type applies. */
    public function description(): string
    {
        return match ($this) {
            self::Standard => 'Pre-authorised, low-risk, routine change',
            self::Normal => 'Requires assessment and CAB approval',
            self::Emergency => 'Urgent change needing expedited approval',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Standard => 'bg-secondary',
            self::Normal => 'bg-info text-dark',
            self::Emergency => 'bg-danger',
        };
    }
}
