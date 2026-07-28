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
        return self::isEnabled() && self::isGraphConfigured();
    }

    /**
     * Whether the shared Microsoft Graph client has the app credentials it needs to reach a
     * calendar at all. Reads the same config GraphClient is constructed from
     * (config('services.graph')) — there is no calendar-specific credential; calendar reads
     * ride the existing tenant-wide Application-permission token. Public so the Settings door can
     * show a "Graph not configured" warning from the SAME predicate isAvailable() uses (single
     * source — the door must not re-implement this check and drift from the reader).
     */
    public static function isGraphConfigured(): bool
    {
        $graph = config('services.graph');

        return is_array($graph)
            && ! empty($graph['tenant_id'])
            && ! empty($graph['client_id'])
            && ! empty($graph['client_secret']);
    }

    /**
     * The configured owner/organizer allowlist — the read side of the SOLE mailbox boundary, so it
     * fails CLOSED on ANY malformed storage (psa-abl0i.5/.7 spine re-review). It requires a genuine
     * JSON LIST of VALID UPN/email strings and denies the WHOLE value if the shape is wrong or any
     * member is malformed:
     *  - IDENTITY-PRESERVING OBJECT-MODE decode (json_decode without assoc), matching CalendarGraphShapes:
     *    a JSON list stays a PHP array, a JSON OBJECT stays a stdClass. Assoc-mode decode collapses
     *    object-vs-list, so a numeric-key object such as {"0":"billing@x"} decodes to [0=>"billing@x"]
     *    — which array_is_list() wrongly calls a list, silently widening the boundary. Object mode
     *    keeps it a stdClass, which is_array() rejects. An empty object {} is likewise denied as a
     *    non-list (distinct from a genuine empty list []).
     *  - every member must be a VALID UPN/email, not merely non-blank: the read seam is the invariant,
     *    so a non-UPN string admitted from corrupt/legacy/hand-edited storage (bypassing the Settings
     *    email rule) must be refused. One invalid/non-string/blank member denies the entire list — a
     *    partial list is not a trustworthy allowlist and must not admit its well-formed siblings.
     * Members are normalized (trimmed) on return. The security invariant lives HERE (the read-side
     * choke point), not only at the Settings write door — a single writer cannot be the only proof. A
     * missing/blank/bad-JSON setting yields [], which through ownerUpnAllowed() denies everyone. Never
     * throws.
     *
     * @return list<string>
     */
    public static function allowedOwnerUpns(): array
    {
        $raw = Setting::getValue('calendar_allowed_owner_upns');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return [];
        }

        $upns = [];
        foreach ($decoded as $entry) {
            if (! is_string($entry)) {
                return []; // one malformed member denies the whole allowlist (fail-closed)
            }
            $normalized = trim($entry);
            if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
                return []; // a blank or non-UPN member denies the whole allowlist (fail-closed)
            }
            $upns[] = $normalized;
        }

        return $upns;
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
