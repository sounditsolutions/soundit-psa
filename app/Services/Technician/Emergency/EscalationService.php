<?php

namespace App\Services\Technician\Emergency;

use App\Enums\EmergencyState;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianEmergency;
use App\Models\User;
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
 *   - reachability is AUTHORITATIVE — a member marked not-reachable is skipped
 *     immediately, never paged;
 *   - "escalation_step" indexes the ORDERED FULL chain; the "current target" is
 *     the first REACHABLE member at/after that index;
 *   - a target that has timed out with no ack causes the step to ADVANCE past it
 *     and the next available member to be paged (no-ack advance);
 *   - PER-TICK IDEMPOTENCY (CO-8): a target already paged within the escalation
 *     timeout is NOT paged again this tick, and the SAME target is re-paged only
 *     on the slower reping cadence;
 *   - when NOBODY is reachable (empty chain, or every member missing/inactive/away),
 *     the last-known target is re-pinged on the reping cadence and a single throttled
 *     audit row is written (`no_chain_configured` for an empty chain, else
 *     `all_unavailable`); the sweep — not this service — is what then triggers the
 *     honest max-hold to the client. "Reachable" means the User EXISTS and is active
 *     AND is not toggled away — a stale/deleted chain member is skipped, never paged
 *     into the void, so the chain advances to a real human in the SAME tick.
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

        // (2) Resolve the ordered full chain. An EMPTY chain still needs a preceding
        //     audit row before the sweep sends the client max-hold (enable-readiness):
        //     route it through the same throttled no-ping audit path as all-unavailable,
        //     stamped `no_chain_configured`, so the trail exists and the audit log is
        //     NOT spammed every minute.
        $chain = TechnicianConfig::escalationChain();
        if ($chain === []) {
            $this->handleNoneReachable($e, ['no_chain_configured']);

            return;
        }

        // (3) Reachability is authoritative. A chain member is reachable only if their
        //     User actually EXISTS and is active AND is not toggled away (isReachable).
        //     If NOBODY on the chain is reachable, take the throttled no-progress path
        //     (re-ping the last KNOWN target on the reping cadence, audit, let the sweep
        //     handle the client max-hold) and return.
        $anyReachable = false;
        foreach ($chain as $uid) {
            if ($this->isReachable($uid)) {
                $anyReachable = true;
                break;
            }
        }

        if (! $anyReachable) {
            $this->handleNoneReachable($e, ['all_unavailable']);

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

        // Belt-and-suspenders: coerce before the strict compare. current_target_user_id
        // is an unsigned bigint; under the prod MariaDB driver (emulated prepares) an
        // uncast column hydrates as a STRING ("5"), so "5" === 5 would be false and the
        // per-tick idempotency guard would FAIL — re-paging the operator and writing a
        // fresh audit row every single sweep minute until ack. The $casts entry on the
        // model is the primary fix; this local (int) cast guards the invariant even if
        // that cast is ever removed (SQLite returns int, so no test can catch the drift).
        $alreadyPingedThisTarget = (int) $e->current_target_user_id === $targetUserId
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
     * No-progress path (invariant #4 + enable-readiness): NOBODY on the chain is
     * reachable — either the chain is empty (`no_chain_configured`) or every member
     * is missing/inactive/away (`all_unavailable`). In BOTH cases the sweep, not this
     * service, sends the honest client max-hold; this method only guarantees a
     * preceding, THROTTLED audit trail (and re-pings the last known target where one
     * exists).
     *
     * THROTTLE (CO — no per-minute spam): only act when last_pinged_at is null or
     * older than the reping cadence. When acting:
     *   - if a last-known target id exists AND that user is still resolvable, re-ping
     *     them on the reping cadence (an empty chain has no target ⇒ never notifies
     *     a null/absent user);
     *   - write EXACTLY ONE audit row and stamp last_pinged_at so the next tick within
     *     the window is suppressed.
     *
     * @param  array<int, string>  $reasons
     */
    private function handleNoneReachable(TechnicianEmergency $e, array $reasons): void
    {
        // Throttle to the reping cadence so an open emergency with no reachable human
        // does not append an audit row (or re-ping) every single sweep minute.
        $due = $e->last_pinged_at === null
            || $e->last_pinged_at->diffInMinutes(now()) >= TechnicianConfig::emergencyRepingMinutes();

        if (! $due) {
            return;
        }

        // Re-ping the last-known target ONLY when one exists and is still resolvable.
        // The empty-chain case has no target ⇒ this is skipped (never notifyUser(null)).
        $lastTarget = $e->current_target_user_id;
        if ($lastTarget !== null && User::find($lastTarget) !== null) {
            $url = $this->ackUrl($e, $lastTarget);
            $this->notifier->notifyUser(
                $lastTarget,
                $this->subject($e),
                $this->body($e, $url, $reasons),
                sms: true,
                smsText: $this->smsStub($e),
            );
        }

        // Stamp last_pinged_at so the throttle suppresses the next within-window tick,
        // then write exactly ONE audit row explaining why nobody was paged.
        $e->last_pinged_at = now();
        $e->save();

        $this->audit($e, $e->escalation_step, $reasons);
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
     * The first REACHABLE chain member at index >= $from, skipping unreachable
     * members (missing/inactive/away). Returns [index, userId] or [-1, null] when
     * none qualifies. Using isReachable() here means a deleted or deactivated member
     * is skipped in this same scan rather than paged into the void — so the chain
     * advances to a genuinely-reachable human without burning an escalation timeout
     * on a dead slot.
     *
     * @param  array<int, int>  $chain
     * @return array{0: int, 1: int|null}
     */
    private function firstAvailableAtOrAfter(array $chain, int $from): array
    {
        $from = max(0, $from);
        for ($i = $from; $i < count($chain); $i++) {
            if ($this->isReachable($chain[$i])) {
                return [$i, $chain[$i]];
            }
        }

        return [-1, null];
    }

    /**
     * Enable-readiness reachability gate. A chain member is reachable only if their
     * User row actually EXISTS and is ACTIVE (this app's `is_active` flag) AND they
     * are not manually toggled away (operatorAvailable — the away switch). ANDing
     * existence/active onto the away-toggle is what stops a later-deleted or
     * -deactivated chain member from being treated as the current target: without
     * it, OperatorNotifier::notifyUser would silently no-op on User::find() === null
     * while escalation_step / last_pinged_at still advanced and an audit row was
     * written — burning a full escalation timeout before reaching a real human.
     *
     * PUBLIC because this is the SINGLE SOURCE OF TRUTH for "is this chain member
     * actually reachable" — EmergencySweep::anyoneReachable() (the gate that decides
     * whether to send the client max-hold) calls THIS so the two can never disagree.
     * If the sweep used a weaker check (e.g. operatorAvailable() alone, which defaults
     * an unset/deleted user to "available"), an all-deleted/-inactive chain would page
     * nobody here AND withhold the max-hold there: a silent missed emergency.
     */
    public function isReachable(int $userId): bool
    {
        $user = User::find($userId);
        if ($user === null || ! $user->is_active) {
            return false;
        }

        return TechnicianConfig::operatorAvailable($userId);
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
