<?php

namespace App\Services\Agent\Intake;

use App\Enums\TechnicianRunState;
use App\Models\TechnicianRun;

/**
 * Channel-neutral recorder for the observational `intake_route` cockpit run.
 *
 * Writes the same TechnicianRun shape the email path records inline
 * (EmailService::recordIntakeRoute) but for ANY intake channel — the call
 * pipeline uses it today; a future channel can reuse it. Purely additive and
 * apply-side: it records what the pipeline already decided/did. It makes no
 * routing, threshold, or dormancy decision of its own, and runs no AI.
 */
class IntakeRecorder
{
    /**
     * Record an observational intake run.
     *
     * - $attachedTicketId non-null  → auto-attach already actioned → Done.
     * - $createdTicketId non-null (attached null) → held suggestion → AwaitingApproval.
     *
     * content_hash is keyed on $contentKey (e.g. 'call:'.$call->id) so re-runs on
     * the same content are idempotent against the (ticket_id, action_type,
     * content_hash) unique key. ticket_id is the attached-or-created ticket — the
     * schema requires a non-null FK, so callers always set exactly one of the two.
     *
     * @param  string  $contentKey  content identity, e.g. 'call:'.$call->id
     * @param  array<string,mixed>  $meta  channel-specific fields merged into proposed_meta
     */
    public function record(
        int $clientId,
        string $contentKey,
        IntakeDecision $decision,
        ?int $attachedTicketId,
        ?int $createdTicketId,
        array $meta = [],
    ): TechnicianRun {
        $attached = $attachedTicketId !== null;

        return TechnicianRun::create([
            'ticket_id' => $attachedTicketId ?? $createdTicketId,
            'client_id' => $clientId,
            'action_type' => 'intake_route',
            'content_hash' => hash('sha256', 'intake:'.$contentKey),
            'state' => $attached ? TechnicianRunState::Done : TechnicianRunState::AwaitingApproval,
            'proposed_content' => mb_substr($decision->reason, 0, 1000),
            'proposed_meta' => array_merge([
                'decision' => $decision->decision,
                'suggested_ticket_id' => $decision->ticketId,
                'confidence' => $decision->confidence,
                'attached' => $attached,
                'created_ticket_id' => $createdTicketId,
            ], $meta),
            'tokens_used' => 0,
        ]);
    }
}
