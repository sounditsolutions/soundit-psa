<?php

namespace App\Enums;

enum TicketStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case PendingClient = 'pending_client';
    case PendingThirdParty = 'pending_third_party';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::InProgress => 'In Progress',
            self::PendingClient => 'Pending Client',
            self::PendingThirdParty => 'Pending Third Party',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::New => 'bg-primary',
            self::InProgress => 'bg-warning text-dark',
            self::PendingClient => 'bg-info text-dark',
            self::PendingThirdParty => 'bg-info text-dark',
            self::Resolved => 'bg-success',
            self::Closed => 'bg-secondary',
        };
    }

    public function isOpen(): bool
    {
        return match ($this) {
            self::New, self::InProgress, self::PendingClient, self::PendingThirdParty => true,
            self::Resolved, self::Closed => false,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Resolved, self::Closed => true,
            default => false,
        };
    }

    public function isPending(): bool
    {
        return match ($this) {
            self::PendingClient, self::PendingThirdParty => true,
            default => false,
        };
    }

    /**
     * @return array<TicketStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::InProgress, self::PendingClient, self::PendingThirdParty, self::Resolved, self::Closed],
            self::InProgress => [self::PendingClient, self::PendingThirdParty, self::Resolved, self::Closed],
            self::PendingClient => [self::InProgress, self::Resolved, self::Closed],
            self::PendingThirdParty => [self::InProgress, self::Resolved, self::Closed],
            self::Resolved => [self::InProgress, self::Closed],
            self::Closed => [self::InProgress],
        };
    }
}
