<?php

namespace App\Services\Mcp;

use App\Models\Person;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the Entra (Azure AD) Object ID of a Teams sender to a portal
 * {@see Person}, fail-closed.
 *
 * This is the portal counterpart of {@see \App\Services\Teams\TeamsIdentityResolver}
 * (which resolves `users.microsoft_id` → staff User). Here we resolve
 * `people.cipp_user_id` — the Azure AD objectId stamped on contacts by the CIPP
 * contact sync — to a Person, and only return one that could actually log into
 * the client portal today. The gate is identical to the portal login
 * middleware ({@see \App\Http\Middleware\PortalAuthenticate}): the Teams surface
 * must never grant access a browser portal login would refuse.
 *
 * Returns null on any failure (no object id, no match, ineligible person). A
 * null result means the caller must refuse to act — never fall back to a
 * shared or ambient identity.
 */
class PortalMcpIdentityResolver
{
    public function resolve(?string $objectId): ?Person
    {
        $objectId = trim((string) $objectId);
        if ($objectId === '') {
            return null;
        }

        $person = Person::query()
            ->where('cipp_user_id', $objectId)
            ->first();

        if ($person === null) {
            Log::info('[MCP/portal] No person matched the supplied Entra Object ID');

            return null;
        }

        // Same predicate the portal login enforces: portal_enabled + is_active +
        // the client is an Active (non-prospect) client, and the contact is a
        // portal-capable person type. A prospect's contact must never reach here.
        if (! $person->canAccessPortal() || ! $person->person_type?->canHavePortal()) {
            Log::info('[MCP/portal] Person is not portal-eligible', ['person_id' => $person->id]);

            return null;
        }

        return $person;
    }
}
