<?php

namespace App\Services\Teams;

use App\Models\User;
use App\Support\TeamsBotConfig;
use Illuminate\Support\Facades\Log;

/**
 * Resolves an inbound Bot Framework activity's sender to the REAL PSA user (E1).
 *
 * The load-bearing guarantee: an unknown, deactivated, or cross-tenant sender
 * resolves to NULL and is audited — there is NO shared-user fallback, ever. This
 * is deliberately the opposite of the shared-token MCP path, which collapses
 * every Teams user into one system account. Per-person identity is the whole
 * point of E1: the bot must always know it is Charlie or Justin, not "the bot".
 *
 * Teams AI-Staff Personas P1: TeamsBotConfig::appIds() is now a SET (the legacy
 * bot ∪ every enabled persona), so JWT-aud membership alone no longer pins WHICH
 * registered bot a token is for vs which bot the (attacker-influenceable)
 * activity body claims to address via recipient.id. `resolve()` accepts the
 * SIGNED, JWT-validated aud (surfaced by VerifyBotFrameworkJwt as the
 * `teams_bot_app_id` request attribute) and asserts it equals the
 * recipient-derived App ID before resolving anything — a mismatch is a
 * routing-spoof shape and is a hard reject + audit, never a fallback to either
 * value. The persona (or legacy-null) identity is then resolved from the SIGNED
 * claim, not the activity body.
 */
class TeamsIdentityResolver
{
    /**
     * @param  ?string  $validatedAppId  The JWT `aud` claim already verified by
     *                                   VerifyBotFrameworkJwt (the `teams_bot_app_id`
     *                                   request attribute). Null means the caller is
     *                                   not behind the JWT middleware — the pre-P1
     *                                   recipient-derived path is preserved verbatim
     *                                   (no cross-check), for backward compatibility.
     */
    public function resolve(array $activity, ?string $validatedAppId = null): ?ResolvedSender
    {
        // 1. The activity must be addressed to a REGISTERED bot. recipient.id is the
        //    bot's App ID; resolving it picks the MSP context. (Defense in depth — the
        //    JWT audience was already checked, but the activity body is re-validated.)
        //    Teams encodes a bot's channel-account id as "28:<appId>", so strip that
        //    documented prefix to recover the bare App ID before the registered-set
        //    match (a bare id, e.g. from the emulator, passes through unchanged).
        $recipientId = $activity['recipient']['id'] ?? null;
        $appId = is_string($recipientId) ? preg_replace('/^28:/', '', $recipientId) : null;

        // 1a. P1 signed-aud binding: when a validated aud is present, it MUST agree
        //     with what the activity body claims to address. Two independent signals
        //     — one signed (trustworthy), one not — disagreeing is exactly the
        //     routing-spoof shape this task exists to close. Reject + audit; never
        //     resolve using either value on a mismatch.
        if ($validatedAppId !== null && $appId !== $validatedAppId) {
            $this->audit('aud/recipient mismatch', $activity, $validatedAppId);

            return null;
        }

        // Resolve the MSP/persona context from the SIGNED claim, not the activity
        // body. When validated, $appId and $validatedAppId are already asserted
        // equal above; binding the lookup to $validatedAppId itself (rather than
        // $appId) is the security intent, so it holds even if the equality check
        // above is ever refactored.
        $msp = TeamsBotConfig::forAppId($validatedAppId ?? $appId);
        if ($msp === null) {
            $this->audit('unregistered bot recipient', $activity, $validatedAppId);

            return null;
        }

        // 2. Cross-tenant guard: when the activity carries a tenant and the bot is
        //    bound to one, they MUST match — a sender from another tenant never
        //    resolves, even if an object id somehow collided.
        $activityTenant = $activity['channelData']['tenant']['id'] ?? null;
        if ($msp['tenant_id'] !== null && is_string($activityTenant) && $activityTenant !== $msp['tenant_id']) {
            $this->audit('tenant mismatch', $activity, $validatedAppId);

            return null;
        }

        // 3. Per-person identity: from.aadObjectId → an ACTIVE PSA user. Never a
        //    shared/system account; a deactivated user does not resolve.
        $aad = $activity['from']['aadObjectId'] ?? null;
        if (! is_string($aad) || trim($aad) === '') {
            $this->audit('missing aadObjectId', $activity, $validatedAppId);

            return null;
        }

        $user = User::where('microsoft_id', $aad)->active()->first();
        if ($user === null) {
            $this->audit('no active PSA user for aadObjectId', $activity, $validatedAppId);

            return null;
        }

        return new ResolvedSender(
            user: $user,
            appId: $msp['app_id'],
            tenantId: $msp['tenant_id'],
            conversationId: $this->stringOrNull($activity['conversation']['id'] ?? null),
            serviceUrl: $this->stringOrNull($activity['serviceUrl'] ?? null),
            aadObjectId: $aad,
            personaKey: $msp['persona_key'],
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Durable security audit of a refusal. We never act on an unresolved sender.
     * `validatedAppId` (when known) is logged alongside so a mismatch record shows
     * both the signed claim and what the activity body claimed.
     */
    private function audit(string $reason, array $activity, ?string $validatedAppId = null): void
    {
        Log::warning('[Teams Bot] Unresolved sender — refusing to act', [
            'reason' => $reason,
            'aad_object_id' => $activity['from']['aadObjectId'] ?? null,
            'recipient_id' => $activity['recipient']['id'] ?? null,
            'tenant_id' => $activity['channelData']['tenant']['id'] ?? null,
            'conversation_id' => $activity['conversation']['id'] ?? null,
            'validated_aud' => $validatedAppId,
        ]);
    }
}
