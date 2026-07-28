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
    /**
     * Master switch — OFF=OFF, default off. STRICT and fail-closed (=== '1'), matching
     * NinjaConfig/ZorusConfig: only the exact string "1" enables the toolset. A (bool) cast
     * would treat a stray/legacy value such as the string "false" as TRUE — the opposite of
     * fail-closed for a toolset that rides a tenant-wide Graph token (psa-abl0i.2 architecture
     * review). Unset defaults to "0", so a deployment that never opts in stays dark.
     */
    public static function isEnabled(): bool
    {
        return Setting::getValue('calendar_enabled', '0') === '1';
    }

    /**
     * The OFF=OFF publication predicate the MCP tool surface gates on: the toolset is live
     * ONLY when switched on AND the underlying Microsoft Graph transport is configured. A
     * withdrawn switch OR missing Graph app credentials both mean the tools must not publish
     * (they classify as unavailable_config, never granted) — mirrors every other vendor's
     * isAvailable(). The grant CATALOG stays ungated (a tool may be pre-granted before the
     * integration is switched on); only the LIVE surface consults this.
     */
    public static function isAvailable(): bool
    {
        return self::isEnabled() && self::graphConfigured();
    }

    /**
     * Whether the shared Microsoft Graph client has the app credentials it needs to reach a
     * calendar at all. Reads the same config GraphClient is constructed from
     * (config('services.graph')) — there is no calendar-specific credential; calendar reads
     * ride the existing tenant-wide Application-permission token.
     */
    private static function graphConfigured(): bool
    {
        $graph = config('services.graph');

        return is_array($graph)
            && ! empty($graph['tenant_id'])
            && ! empty($graph['client_id'])
            && ! empty($graph['client_secret']);
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
