<?php

namespace App\Services\Teams;

use App\Models\User;

/**
 * The resolved identity + conversation context for an inbound Teams activity.
 *
 * Always a REAL PSA user (never a shared account). The conversation-reference
 * bits (conversation id, serviceUrl, the Entra object id, the MSP app/tenant)
 * are captured so a later increment (E2) can address a proactive reply back to
 * exactly this person and conversation.
 *
 * `personaKey` (Teams AI-Staff Personas P1): null for the legacy single-bot
 * pilot, otherwise the `teams_personas.persona_key` (e.g. 'gus') this activity's
 * bot resolved to. Always set from TeamsBotConfig::forAppId() keyed by the
 * SIGNED, JWT-validated aud (see TeamsIdentityResolver::resolve()) — never from
 * unvalidated activity data. Placed last (not immediately after `appId`) so an
 * existing positional-argument construction site
 * (TeamsAmbientServiceTest::test_a_conversation_with_no_id_does_not_chime)
 * keeps binding its 6 positional args correctly; every other parameter keeps
 * its original relative order.
 */
final class ResolvedSender
{
    public function __construct(
        public readonly User $user,
        public readonly string $appId,
        public readonly ?string $tenantId,
        public readonly ?string $conversationId,
        public readonly ?string $serviceUrl,
        public readonly string $aadObjectId,
        public readonly ?string $personaKey = null,
    ) {}
}
