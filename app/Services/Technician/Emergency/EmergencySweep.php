<?php

namespace App\Services\Technician\Emergency;

use App\Enums\EmergencyState;
use App\Enums\NoteType;
use App\Enums\WhoType;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianEmergency;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Support\TechnicianConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The relied-on "never miss a real emergency" backstop (Phase 2 — SAFETY-CRITICAL).
 *
 * Run every minute while the operator is away. One tick does three things, in order:
 *
 *   (1) SCAN candidate tickets (operational clients only — CO-10; never a prospect),
 *       skipping any ticket already in an open emergency (CO-1 — the open-emergency
 *       skip is the re-detection dedup; the grouper's storm window is only the
 *       clustering key) and any ticket opened before coverage_start. Each surviving
 *       candidate is assessed by the deterministic EmergencyDetector and, when it IS
 *       an emergency, grouped/created.
 *
 *   (2) DRIVE each open emergency, fail-soft per emergency (one bad emergency never
 *       aborts the sweep):
 *         - implicit ACK (CO-6): if ANY member ticket shows a genuine human touch
 *           since alerted_at, mark acknowledged + write an emergency_ack audit row.
 *           This is the trustworthy stop signal — escalation halts.
 *         - re-alert a snoozed ack (CO-5): an acknowledged-but-still-untouched
 *           emergency older than the reping interval RESUMES (state → open, escalate
 *           again), so a leaked/forwarded ack link can never permanently silence the
 *           backstop. Within the snooze interval it is left quiet.
 *         - otherwise ESCALATE; and if NOBODY on the chain is reachable and the
 *           honest max-hold has not been sent, send it once on the representative.
 *
 *   (3) RESOLVE emergencies whose member tickets are ALL closed/resolved.
 *
 * No AI is in this loop. There is intentionally NO scan self-throttle (CO-17):
 * Ticket::open() is cheap + indexed, and re-detection is deduped by CO-1, not by a
 * cadence — a throttle would only risk a missed first-detection.
 */
class EmergencySweep
{
    public function __construct(
        private readonly EmergencyDetector $detector,
        private readonly EmergencyGrouper $grouper,
        private readonly EscalationService $escalation,
        private readonly MaxHoldSender $maxHold,
    ) {}

    public function run(): void
    {
        // psa-wmqp/psa-3d7h: anchor the scan to a coverage window. ensureCoverageStart()
        // is a defensive backfill for the upgrade case — a subsystem already enabled
        // before this fix shipped never got a coverage_start stamped by the toggle, so
        // stamp it on the first tick. It is idempotent (no-op once set) and only runs
        // when enabled (the command early-exits on disabled + the schedule ->when()
        // gate), so it can never stamp while the Technician is off.
        TechnicianConfig::ensureCoverageStart();

        $this->scan();
        // Reap closed-out emergencies BEFORE driving, so an emergency whose tickets are
        // all already resolved is never spuriously escalated on its final tick.
        $this->resolveClosedOut();
        $this->driveOpenEmergencies();
    }

    /**
     * (1) Detect: every open ticket on an OPERATIONAL client that is NOT already in
     * an open emergency, NOT on an excluded client, and NOT older than the coverage
     * anchor. Each survivor is assessed; an emergency is grouped/created.
     */
    private function scan(): void
    {
        $coverageStart = TechnicianConfig::coverageStartAt();

        Ticket::query()
            ->open()
            ->whereHas('client', fn ($q) => $q->operational())
            ->each(function (Ticket $ticket) use ($coverageStart): void {
                // CO-1 (HARD): a ticket already in an open emergency is skipped BEFORE
                // the grouper — otherwise a still-aged still-untouched ticket at minute
                // 16 (past the 15m storm window) would spawn a SECOND emergency, i.e. a
                // duplicate escalation + duplicate max-hold. The storm window groups; the
                // open-emergency skip is what dedups re-detection.
                if (TechnicianEmergency::hasOpenEmergency($ticket)) {
                    return;
                }

                // Operator-excluded clients are never auto-handled.
                if ($ticket->client_id !== null && TechnicianConfig::clientExcluded($ticket->client_id)) {
                    return;
                }

                // psa-3d7h: coverage_start is the enable-time boundary for ALL rule
                // signals. Pre-existing backlog was already visible to the operator; do
                // not let keyword or SLA signals retroactively page it on the first tick.
                if ($this->openedBeforeCoverage($ticket, $coverageStart)) {
                    return;
                }

                $assessment = $this->detector->assess($ticket);
                if ($assessment->isEmergency) {
                    $this->grouper->groupOrCreate($ticket, $assessment);
                }
            });
    }

    private function openedBeforeCoverage(Ticket $ticket, ?Carbon $coverageStart): bool
    {
        if ($coverageStart === null) {
            return false;
        }

        $opened = $ticket->opened_at ?? $ticket->created_at;

        return $opened === null || $opened->lt($coverageStart);
    }

    /** (2) Drive every open emergency, isolating failures per emergency. */
    private function driveOpenEmergencies(): void
    {
        TechnicianEmergency::query()->open()->get()->each(function (TechnicianEmergency $e): void {
            try {
                $this->driveOne($e);
            } catch (\Throwable $ex) {
                // Fail-soft: a single failing emergency must never abort the sweep —
                // the OTHER emergencies still need to be driven this tick.
                Log::warning('[Technician] Emergency sweep failed for one emergency; continuing', [
                    'emergency_id' => $e->id,
                    'error' => $ex->getMessage(),
                ]);
            }
        });
    }

    private function driveOne(TechnicianEmergency $e): void
    {
        // CO-6: a genuine human touch is the trustworthy stop signal.
        if ($this->hasHumanTouch($e)) {
            if ($e->state !== EmergencyState::Acknowledged) {
                $e->update([
                    'state' => EmergencyState::Acknowledged,
                    'acknowledged_at' => $e->acknowledged_at ?? now(),
                ]);
                $this->auditAck($e);
            }

            // A human is on it ⇒ escalation halts this tick.
            return;
        }

        // CO-5: ack is a SNOOZE, not a permanent stop. An acknowledged emergency with
        // NO detected human touch (the one-tap link was used, but nobody actually
        // worked the ticket) RESUMES once the snooze interval lapses, so a leaked /
        // forwarded ack link can never silence the backstop. Inside the interval it
        // stays quiet.
        if ($e->state === EmergencyState::Acknowledged) {
            $ackedAt = $e->acknowledged_at;
            $repingMinutes = TechnicianConfig::emergencyRepingMinutes();
            $snoozeLapsed = $ackedAt === null || $ackedAt->lte(now()->subMinutes($repingMinutes));

            if (! $snoozeLapsed) {
                return; // still snoozed
            }

            // Resume: revert to open and fall through to escalate again this tick.
            $e->update(['state' => EmergencyState::Open]);
            $e->state = EmergencyState::Open;
        }

        // Open (or just-resumed) ⇒ escalate. Reload once so escalation works against a
        // model whose unsigned-bigint columns are cast-hydrated (the prod-MariaDB
        // idempotency invariant), and so the max-hold pre-check below reads the page state
        // escalation just wrote (e.g. last_pinged_at, current_target_user_id). fresh()
        // is null only if the row vanished mid-tick — nothing to drive then.
        $e = $e->fresh();
        if ($e === null) {
            return;
        }

        $this->escalation->escalate($e);

        // Honest max-hold (the ONE autonomous client-facing send): only when NOBODY on
        // the chain is reachable AND it has not already been sent. The MaxHoldSender's
        // CAS once-guard is the real idempotency; this is the pre-check on the
        // representative ticket (guard a deleted/missing ticket).
        $e->refresh();
        if ($e->max_hold_sent_at === null && ! $this->anyoneReachable()) {
            $ticket = Ticket::find($e->ticket_id);
            if ($ticket !== null) {
                $this->maxHold->send($e, $ticket);
            }
        }
    }

    /**
     * CO-6 — BROAD human-touch detection across ALL member tickets (ticket_ids plus
     * the representative ticket_id). A member is "humanly touched since alerted_at" if:
     *   (a) responded_at > alerted_at; OR
     *   (b) a TicketNote authored by a human Agent (who_type = Agent, ai_authored =
     *       false, a NON-system note_type) with noted_at > alerted_at; OR
     *   (c) APPROXIMATE — assignee_id set and the ticket's updated_at > alerted_at.
     *       (There is no assigned_at timestamp on tickets, so (c) cannot be exact; it
     *       is deliberately conservative — a stray unrelated update on an assigned
     *       ticket can read as a touch, which only ever errs toward NOT paging.)
     */
    private function hasHumanTouch(TechnicianEmergency $e): bool
    {
        $alertedAt = $e->alerted_at;
        if ($alertedAt === null) {
            return false;
        }

        $ticketIds = $this->memberTicketIds($e);
        if ($ticketIds === []) {
            return false;
        }

        $systemTypes = array_map(fn (NoteType $t) => $t->value, NoteType::systemGenerated());

        foreach (Ticket::query()->whereIn('id', $ticketIds)->get() as $ticket) {
            // (a) the operator/agent responded after the alert.
            if ($ticket->responded_at !== null && $ticket->responded_at->gt($alertedAt)) {
                return true;
            }

            // (c) APPROXIMATE: assigned + touched since the alert (no assigned_at exists).
            if ($ticket->assignee_id !== null && $ticket->updated_at !== null && $ticket->updated_at->gt($alertedAt)) {
                return true;
            }
        }

        // (b) a genuine human Agent note (non-AI, non-system) since the alert.
        return TicketNote::query()
            ->whereIn('ticket_id', $ticketIds)
            ->where('who_type', WhoType::Agent->value)
            ->where('ai_authored', false)
            ->whereNotIn('note_type', $systemTypes)
            ->where('noted_at', '>', $alertedAt)
            ->exists();
    }

    /** @return array<int, int> the emergency's member ticket ids (storm members + representative). */
    private function memberTicketIds(TechnicianEmergency $e): array
    {
        $ids = is_array($e->ticket_ids) ? $e->ticket_ids : [];
        if ($e->ticket_id !== null) {
            $ids[] = $e->ticket_id;
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * True iff at least one escalation-chain member is GENUINELY reachable.
     *
     * This gates the honest client max-hold, so it MUST use the exact same definition
     * of "reachable" as EscalationService — otherwise the two can disagree and leave a
     * missed-emergency hole: if the whole chain is deleted/inactive users, the escalation
     * pages no one (correctly), and a weaker gate here (bare operatorAvailable(), which
     * defaults an unset/deleted user to "available") would WITHHOLD the max-hold — nobody
     * paged AND no client comms, emergency open forever. Delegating to the injected
     * EscalationService::isReachable() keeps a SINGLE source of truth (existence + active
     * + the operator away-toggle, ANDed) so the sweep and escalation can never diverge.
     */
    private function anyoneReachable(): bool
    {
        foreach (TechnicianConfig::escalationChain() as $uid) {
            if ($this->escalation->isReachable($uid)) {
                return true;
            }
        }

        return false;
    }

    /**
     * (3) Resolve emergencies whose member tickets are ALL closed/resolved (terminal,
     * i.e. not in scopeOpen). An emergency with no resolvable member ticket left open
     * is done.
     */
    private function resolveClosedOut(): void
    {
        TechnicianEmergency::query()->open()->get()->each(function (TechnicianEmergency $e): void {
            try {
                $ids = $this->memberTicketIds($e);
                if ($ids === []) {
                    return;
                }

                // Any member still open ⇒ the emergency is still live.
                $anyOpen = Ticket::query()->whereIn('id', $ids)->open()->exists();
                if ($anyOpen) {
                    return;
                }

                $e->update(['state' => EmergencyState::Resolved, 'resolved_at' => $e->resolved_at ?? now()]);
            } catch (\Throwable $ex) {
                Log::warning('[Technician] Emergency resolve check failed; continuing', [
                    'emergency_id' => $e->id,
                    'error' => $ex->getMessage(),
                ]);
            }
        });
    }

    /**
     * Write the implicit-ack audit row. CO-2: supply the FULL NOT-NULL column set so
     * this passes on MariaDB/prod, not just SQLite. INSERT only (append-only table).
     * Mirrors EscalationService/MaxHoldSender's audit shape exactly.
     */
    private function auditAck(TechnicianEmergency $e): void
    {
        TechnicianActionLog::create([
            'actor_id' => TechnicianConfig::aiActorUserId(),
            'actor_label' => 'ai-technician',
            'action_type' => 'emergency_ack',
            'tier' => 'auto',
            'result_status' => 'executed',
            'ticket_id' => $e->ticket_id,
            'client_id' => $e->client_id,
            'run_id' => null,
            'content_hash' => hash('sha256', 'emergency_ack:'.$e->id),
            'summary' => "Emergency #{$e->id} acknowledged — a human touched a member ticket since the alert.",
            'correlation_id' => 'emergency:'.$e->id,
        ]);
    }
}
