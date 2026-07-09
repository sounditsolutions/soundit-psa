<?php

namespace App\Enums;

/**
 * Change Advisory Board (CAB) approval state for an ITIL change. Tracks where a
 * change sits in the approval workflow on tickets whose {@see TicketType} is Change.
 */
enum CabApproval: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'Not Required',
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::NotRequired => 'bg-secondary',
            self::Pending => 'bg-warning text-dark',
            self::Approved => 'bg-success',
            self::Rejected => 'bg-danger',
        };
    }
}
