<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case PendingSync = 'pending_sync';
    case Synced = 'synced';
    case Posted = 'posted';
    case Paid = 'paid';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingSync => 'Pending Sync',
            self::Synced => 'Synced',
            self::Posted => 'Posted',
            self::Paid => 'Paid',
            self::Void => 'Void',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-secondary',
            self::PendingSync => 'bg-warning text-dark',
            self::Synced => 'bg-info text-dark',
            self::Posted => 'bg-info',
            self::Paid => 'bg-success',
            self::Void => 'bg-danger',
        };
    }

    /** Client-friendly label for the portal. */
    public function portalLabel(): string
    {
        return match ($this) {
            self::Paid => 'Paid',
            default => 'Unpaid',
        };
    }

    /** Badge class for portal display. */
    public function portalBadgeClass(): string
    {
        return match ($this) {
            self::Paid => 'bg-success',
            default => 'bg-warning text-dark',
        };
    }

    public function isUnpaidForPortal(): bool
    {
        return $this !== self::Paid;
    }
}
