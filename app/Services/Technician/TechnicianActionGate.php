<?php

namespace App\Services\Technician;

use App\Enums\TechnicianTier;
use App\Models\TechnicianActionLog;
use App\Models\Ticket;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The normalized outcome of a gate dispatch.
 *
 *  executed           — an AUTO (or validly-approved) action ran.
 *  awaiting_approval  — a non-AUTO action recorded, NOT executed (Phase 0:
 *                       no approval round-trip yet; the run holds).
 *  blocked            — a server-denylisted (BLOCK) action refused.
 *  held               — kill-switch engaged or client excluded; fail-closed.
 */
final class TechnicianActionResult
{
    public function __construct(
        public readonly string $status,
        public readonly TechnicianTier $tier,
        public readonly TechnicianActionLog $log,
    ) {}
}

/**
 * The SOLE entry point for every side-effecting AI-Technician action
 * (spec §4.3). It classifies the resolved action server-side (default-deny),
 * re-checks the kill-switch + per-client flags immediately before execution,
 * executes AUTO/approved actions via the passed $executor, records non-AUTO
 * actions as awaiting_approval WITHOUT executing, stamps the reused AI actor +
 * actor_label:'ai-technician', and writes exactly one append-only audit row on
 * EVERY path. Fail-closed throughout.
 *
 * The Loop holds NO reference to EmailService/TicketService/TacticalActionService
 * — it passes an $executor closure to this gate (asserted by test).
 */
class TechnicianActionGate
{
    public function __construct(
        private readonly TechnicianTierClassifier $classifier = new TechnicianTierClassifier,
    ) {}

    /**
     * @param  callable():void  $executor  the side effect, run ONLY if the gate clears it
     */
    public function dispatch(
        string $actionType,
        int $ticketId,
        ?int $clientId,
        string $contentHash,
        string $summary,
        ?int $runId,
        callable $executor,
        ?string $approvalToken = null,
        ?int $approverUserId = null,
        ?float $confidence = null,
    ): TechnicianActionResult {
        $correlationId = (string) Str::uuid();

        // propose_close needs the Ticket for its deterministic auto-backstop (CO-19);
        // a missing ticket leaves $ticket null → the classifier fails closed to Approve.
        $ticket = $actionType === 'propose_close' ? Ticket::find($ticketId) : null;
        $tier = $this->classifier->classify($actionType, $confidence, $ticket);

        // Kill-switch (pre-execution barrier) — fail-closed.
        if (TechnicianConfig::killSwitchEngaged()) {
            return $this->result('held', $tier, $this->audit($actionType, $tier, 'held', $ticketId, $clientId, $runId, $contentHash, $summary, $correlationId));
        }

        // Per-client exclusion — fail-closed.
        if ($clientId !== null && TechnicianConfig::clientExcluded($clientId)) {
            return $this->result('held', $tier, $this->audit($actionType, $tier, 'held', $ticketId, $clientId, $runId, $contentHash, $summary, $correlationId));
        }

        // BLOCK denylist — server-enforced.
        if ($tier === TechnicianTier::Block) {
            return $this->result('blocked', $tier, $this->audit($actionType, $tier, 'blocked', $ticketId, $clientId, $runId, $contentHash, $summary, $correlationId));
        }

        // Non-AUTO (Approve, or an always-human client) requires a valid grant.
        $requiresApproval = $tier !== TechnicianTier::Auto
            || ($clientId !== null && TechnicianConfig::clientAlwaysHuman($clientId));

        // Forensic attribution (psa-uohr): the approver is recorded on the executed row
        // ONLY when a verified human grant actually gated this execution — never for an
        // AUTO action (which has no human approver, even if a token was incidentally passed).
        $attributedApproverId = null;

        if ($requiresApproval) {
            $granted = $approvalToken !== null && TechnicianApprovalGrant::verify(
                $approvalToken,
                $actionType,
                $ticketId,
                $contentHash,
                $approverUserId,
            );

            if (! $granted) {
                return $this->result('awaiting_approval', $tier, $this->audit($actionType, $tier, 'awaiting_approval', $ticketId, $clientId, $runId, $contentHash, $summary, $correlationId));
            }

            // Grant verified against $approverUserId — bind that identity to the audit row.
            $attributedApproverId = $approverUserId;
        }

        // In-flight kill-switch re-check immediately before execution — fail-closed.
        if (TechnicianConfig::killSwitchEngaged()) {
            return $this->result('held', $tier, $this->audit($actionType, $tier, 'held', $ticketId, $clientId, $runId, $contentHash, $summary, $correlationId));
        }

        // Atomic: the executor's DB side effects and the append-only 'executed'
        // audit row commit together or not at all (review #55 — a committed side
        // effect with no audit row would re-send on retry). Any external send
        // (e.g. the acknowledgment email) is performed by the caller AFTER this
        // returns 'executed', never inside this transaction.
        $log = DB::transaction(function () use ($executor, $actionType, $tier, $ticketId, $clientId, $runId, $contentHash, $summary, $correlationId, $attributedApproverId): TechnicianActionLog {
            $executor();

            return $this->audit($actionType, $tier, 'executed', $ticketId, $clientId, $runId, $contentHash, $summary, $correlationId, $attributedApproverId);
        });

        return $this->result('executed', $tier, $log);
    }

    private function result(string $status, TechnicianTier $tier, TechnicianActionLog $log): TechnicianActionResult
    {
        return new TechnicianActionResult($status, $tier, $log);
    }

    private function audit(
        string $actionType,
        TechnicianTier $tier,
        string $resultStatus,
        int $ticketId,
        ?int $clientId,
        ?int $runId,
        string $contentHash,
        string $summary,
        string $correlationId,
        ?int $approverUserId = null,
    ): TechnicianActionLog {
        return TechnicianActionLog::create([
            'actor_id' => TechnicianConfig::aiActorUserId(),
            'approver_user_id' => $approverUserId,
            'actor_label' => 'ai-technician',
            'action_type' => $actionType,
            'tier' => $tier->value,
            'result_status' => $resultStatus,
            'ticket_id' => $ticketId,
            'client_id' => $clientId,
            'run_id' => $runId,
            'content_hash' => $contentHash,
            'summary' => $summary,
            'correlation_id' => $correlationId,
        ]);
    }
}
