<?php

namespace App\Services\Signals;

/**
 * Registry of derived-recipient kinds for signal route steps.
 *
 * A derived recipient resolves its concrete destination from the event's
 * subject entity at route time (e.g. "the owner of the ticket this event is
 * about"), rather than pointing at a fixed destination. Mirrors the static-map
 * shape of {@see SignalEventTypes} so both read the same way.
 */
class DerivedRecipients
{
    /** Route to the owner (assignee) of the ticket the event is about. */
    public const TICKET_OWNER = 'ticket_owner';

    /**
     * All supported derived-recipient kinds, keyed by storage value.
     *
     * @return array<string, string> kind => human label
     */
    public static function all(): array
    {
        return [
            self::TICKET_OWNER => 'Ticket owner (assignee)',
        ];
    }

    public static function has(string $kind): bool
    {
        return array_key_exists($kind, self::all());
    }

    public static function label(string $kind): string
    {
        return self::all()[$kind] ?? $kind;
    }
}
