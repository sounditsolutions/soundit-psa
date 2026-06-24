<?php

namespace App\Services\Technician\Emergency;

use App\Enums\EmergencyState;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianEmergency;
use App\Services\Technician\Notify\OperatorNotifier;
use App\Support\TechnicianConfig;

/**
 * The deterministic escalation state machine (Phase 2 — SAFETY-CRITICAL).
 *
 * This is how a real emergency reaches a human while the operator is away. It is
 * driven once per sweep tick (Task 10) for each open emergency and decides, with
 * NO AI in the loop, who to page next and when:
 *
 *   - acknowledged / resolved emergencies are PAUSED here (un-snoozing is the
 *     sweep's job, not this service's);
 *   - availability is AUTHORITATIVE — a member marked not-available is skipped
 *     immediately, never paged;
 *   - "escalation_step" indexes the ORDERED FULL chain; the "current target" is
 *     the first AVAILABLE member at/after that index;
 *   - a target that has timed out with no ack causes the step to ADVANCE past it
 *     and the next available member to be paged (no-ack advance);
 *   - PER-TICK IDEMPOTENCY (CO-8): a target already paged within the escalation
 *     timeout is NOT paged again this tick, and the SAME target is re-paged only
 *     on the slower reping cadence;
 *   - when NOBODY is available, the last-known target is re-pinged on the reping
 *     cadence and a single `all_unavailable` audit row is written; the sweep —
 *     not this service — is what then triggers the honest max-hold to the client.
 *
 * Every page writes EXACTLY ONE append-only `emergency_escalate` audit row direct
 * to technician_action_logs (these are INTERNAL operator alerts, not client-facing
 * actions, so they bypass the action gate). INSERT only — the table is append-only.
 *
 * CO-5d/CO-11a: the ack URL is a short-lived bearer credential and MUST NEVER be
 * sent over SMS. It is placed only in the email/Teams body; the SMS carries a
 * non-identifying stub that points the operator at the cockpit.
 */
class EscalationService
{
    public function __construct(private readonly OperatorNotifier $notifier) {}

    public function escalate(TechnicianEmergency $e): void
    {
        // (1) Acknowledged/resolved ⇒ escalation is PAUSED this tick. Un-snoozing
        //     belongs to the sweep (Task 10), not here.
        if (in_array($e->state, [EmergencyState::Acknowledged, EmergencyState::Resolved], true)) {
            return;
        }

        // (2) Resolve the ordered full chain. Nothing to escalate to ⇒ nothing to do.
        $chain = TechnicianConfig::escalationChain();
        if ($chain === []) {
            return;
        }

        // (3) Availability is authoritative. If NOBODY is available, take the
        //     both-unavailable path (re-ping the last target on the reping cadence,
        //     audit `all_unavailable`, let the sweep handle max-hold) and return.
        $anyAvailable = false;
        foreach ($chain as $uid) {
            if (TechnicianConfig::operatorAvailable($uid)) {
                $anyAvailable = true;
                break;
            }
        }

        if (! $anyAvailable) {
            $this->handleAllUnavailable($e);

            return;
        }

        // (5) Current target = first AVAILABLE member at/after escalation_step.
        $step = (int) $e->escalation_step;
        [$targetIndex, $targetUserId] = $this->firstAvailableAtOrAfter($chain, $step);

        // Defensive: someone is available somewhere, but not at/after the current
        // step (e.g. step ran past a now-unavailable tail). Reset to the front.
        if ($targetUserId === null) {
            [$targetIndex, $targetUserId] = $this->firstAvailableAtOrAfter($chain, 0);
            if ($targetUserId === null) {
                return; // unreachable given $anyAvailable, but fail-closed.
            }
        }

        $alreadyPingedThisTarget = $e->current_target_user_id === $targetUserId
            && $e->last_pinged_at !== null;

        if ($alreadyPingedThisTarget) {
            $minsSincePing = $e->last_pinged_at->diffInMinutes(now());

            // (6) PER-TICK IDEMPOTENCY: paged within the escalation timeout ⇒ do
            //     NOT page again this tick, do NOT touch last_pinged_at.
            if ($minsSincePing < TechnicianConfig::escalationTimeoutMinutes()) {
                return;
            }

            // No ack past the timeout ⇒ ADVANCE past this target to the next
            // available member, and page them (a fresh target).
            [$nextIndex, $nextUserId] = $this->firstAvailableAtOrAfter($chain, $targetIndex + 1);

            if ($nextUserId !== null) {
                $this->ping($e, $nextIndex, $nextUserId, ['no_ack_advance']);

                return;
            }

            // No one left to advance to (this was the last available member).
            // Re-page the SAME target, but only on the slower reping cadence.
            if ($minsSincePing < TechnicianConfig::emergencyRepingMinutes()) {
                return;
            }

            $this->ping($e, $targetIndex, $targetUserId, ['reping_last']);

            return;
        }

        // Fresh target (never paged, or the target changed) ⇒ page it now.
        $this->ping($e, $targetIndex, $targetUserId, ['first_available']);
    }

    /**
     * Both-unavailable path (invariant #4): re-ping the last-known target ONLY on
     * the reping cadence, and record exactly one `all_unavailable` audit row. The
     * sweep is what triggers the honest max-hold to the client — not this service.
     */
    private function handleAllUnavailable(TechnicianEmergency $e): void
    {
        $lastTarget = $e->current_target_user_id;

        $due = $e->last_pinged_at === null
            || $e->last_pinged_at->diffInMinutes(now()) >= TechnicianConfig::emergencyRepingMinutes();

        if ($lastTarget !== null && $due) {
            $url = $this->ackUrl($e, $lastTarget);
            $this->notifier->notifyUser(
                $lastTarget,
                $this->subject($e),
                $this->body($e, $url, ['all_unavailable']),
                sms: true,
                smsText: $this->smsStub($e),
            );
            $e->last_pinged_at = now();
            $e->save();
        }

        // Exactly one audit row per all-unavailable tick.
        $this->audit($e, $e->escalation_step, ['all_unavailable']);
    }

    /**
     * Page a target: notify (with the ack URL in the body, a stub over SMS),
     * stamp current_target_user_id / last_pinged_at / escalation_step, and write
     * EXACTLY ONE append-only audit row.
     *
     * @param  array<int, string>  $reasons
     */
    private function ping(TechnicianEmergency $e, int $stepIndex, int $targetUserId, array $reasons): void
    {
        $url = $this->ackUrl($e, $targetUserId);

        $this->notifier->notifyUser(
            $targetUserId,
            $this->subject($e),
            $this->body($e, $url, $reasons),
            sms: true,
            smsText: $this->smsStub($e),
        );

        $e->current_target_user_id = $targetUserId;
        $e->escalation_step = $stepIndex;
        $e->last_pinged_at = now();
        $e->save();

        $this->audit($e, $stepIndex, $reasons);
    }

    /**
     * The first AVAILABLE chain member at index >= $from, skipping unavailable
     * members. Returns [index, userId] or [-1, null] when none qualifies.
     *
     * @param  array<int, int>  $chain
     * @return array{0: int, 1: int|null}
     */
    private function firstAvailableAtOrAfter(array $chain, int $from): array
    {
        $from = max(0, $from);
        for ($i = $from; $i < count($chain); $i++) {
            if (TechnicianConfig::operatorAvailable($chain[$i])) {
                return [$i, $chain[$i]];
            }
        }

        return [-1, null];
    }

    /**
     * Append exactly one immutable `emergency_escalate` row. CO-2: supply the FULL
     * NOT-NULL set so this passes on MariaDB/prod, not just SQLite. INSERT only.
     *
     * @param  array<int, string>  $reasons
     */
    private function audit(TechnicianEmergency $e, int $stepIndex, array $reasons): void
    {
        $reasonText = implode(',', $reasons);

        TechnicianActionLog::create([
            'actor_id' => TechnicianConfig::aiActorUserId(),
            'actor_label' => 'ai-technician',
            'action_type' => 'emergency_escalate',
            'tier' => 'auto',
            'result_status' => 'executed',
            'ticket_id' => $e->ticket_id,
            'client_id' => $e->client_id,
            'run_id' => null,
            'content_hash' => hash('sha256', 'emergency_escalate:'.$e->id.':'.$stepIndex),
            'summary' => "Emergency #{$e->id} escalation (step {$stepIndex}) — {$reasonText}",
            'correlation_id' => 'emergency:'.$e->id,
        ]);
    }

    /** Build the signed one-tap ack URL for the target (named route emergency.ack). */
    private function ackUrl(TechnicianEmergency $e, int $targetUserId): string
    {
        return route('emergency.ack', ['token' => EmergencyAckToken::issue($e->id, $targetUserId)]);
    }

    private function subject(TechnicianEmergency $e): string
    {
        return "AI Technician — emergency on ticket #{$e->ticket_id}";
    }

    /**
     * The rich notification body — carries the ack URL and the escalation reason.
     * Delivered to email + Teams only; NEVER to SMS.
     *
     * @param  array<int, string>  $reasons
     */
    private function body(TechnicianEmergency $e, string $ackUrl, array $reasons): string
    {
        $reasonText = implode(', ', $reasons);

        return "An emergency needs a human on ticket #{$e->ticket_id}"
            .($e->client_id ? " (client #{$e->client_id})" : '')
            .". Reason: {$reasonText}.\n\n"
            ."Tap to acknowledge (you are still on call until you touch the ticket): {$ackUrl}";
    }

    /**
     * The SMS stub (CO-11a): a non-identifying nudge that points the operator at
     * the cockpit. It MUST NOT contain the ack URL or any link.
     */
    private function smsStub(TechnicianEmergency $e): string
    {
        return "AI Technician needs you on ticket #{$e->ticket_id} — open the cockpit";
    }
}
