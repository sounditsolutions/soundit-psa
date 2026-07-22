<?php

namespace App\Enums;

/**
 * ITIL-informed hint for how work under a ticket category tends to behave —
 * an incident (something broke), a request (someone wants something), or a
 * mix. Advisory only (so-0ftg): it colours the UI and can inform routing, it
 * does not constrain the ticket's own type.
 */
enum RecordTypeHint: string
{
    case Incident = 'incident';
    case Request = 'request';
    case Mixed = 'mixed';

    public function label(): string
    {
        return match ($this) {
            self::Incident => 'Incident',
            self::Request => 'Request',
            self::Mixed => 'Mixed',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Incident => 'bg-danger',
            self::Request => 'bg-info text-dark',
            self::Mixed => 'bg-secondary',
        };
    }
}
