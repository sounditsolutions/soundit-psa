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
    ) {}
}
