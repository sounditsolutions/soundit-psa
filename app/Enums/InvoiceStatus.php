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

    /**
     * Ordered value => label map for the invoice-list status filter dropdown.
     *
     * Leads with the derived filters ("outstanding", "overdue") that map to
     * query scopes on the Invoice model rather than stored status values,
     * followed by the concrete statuses. Consumed by the invoice-list dropdown
     * and routed to queries via Invoice::scopeStatusFilter().
     *
     * @return array<string, string>
     */
    public static function filterOptions(): array
    {
        $options = [
            'outstanding' => 'Outstanding',
            'overdue' => 'Overdue',
        ];

        foreach (self::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
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
