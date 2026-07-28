<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Static config helper for the staff Calendar/scheduling MCP toolset (psa-abl0i), following the
 * ZorusConfig / TechnicianConfig pattern.
 *
 * THE SECURITY SPINE — the server-side UPN allowlist. Charlie dropped the Azure Application
 * Access Policy (owner decision, verified at source), so this allowlist is now THE ONLY
 * constraint on which mailboxes the tenant-wide Graph token may act as calendar owner /
 * organizer / respond-as for. It fails CLOSED: an unset or empty list denies every mailbox, and
 * only explicitly listed mailboxes are permitted. External addresses may be event ATTENDEES but
 * never an owner/organizer — attendees are never checked here.
 *
 * Settings keys (both PLAIN — the allowlist is not a secret):
 *  - calendar_enabled             master OFF=OFF switch (default off).
 *  - calendar_allowed_owner_upns  JSON array of allowed owner/organizer UPNs.
 */
class CalendarConfig
{
    /** Master switch — OFF=OFF. Unset or "0" ⇒ the toolset is not live and must not be published. */
    public static function isEnabled(): bool
    {
        return (bool) Setting::getValue('calendar_enabled');
    }

    /**
     * The configured owner/organizer allowlist. A missing or malformed (non-array / bad JSON)
     * setting yields [] — which, through ownerUpnAllowed(), denies everyone. Never throws.
     *
     * @return list<string>
     */
    public static function allowedOwnerUpns(): array
    {
        $raw = Setting::getValue('calendar_allowed_owner_upns');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * True iff $upn is an explicitly-allowed calendar owner/organizer/respond-as identity.
     * Fail-closed: a blank input, an empty allowlist, or no match all return false. UPNs are
     * email-like, so the comparison is case-insensitive and whitespace-insensitive on both sides.
     */
    public static function ownerUpnAllowed(string $upn): bool
    {
        $needle = mb_strtolower(trim($upn));
        if ($needle === '') {
            return false;
        }

        foreach (self::allowedOwnerUpns() as $allowed) {
            if (is_string($allowed) && mb_strtolower(trim($allowed)) === $needle) {
                return true;
            }
        }

        return false;
    }
}
