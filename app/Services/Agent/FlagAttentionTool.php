<?php

namespace App\Services\Agent;

use App\Enums\FlagAttentionCategory;
use App\Enums\TechnicianRunState;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Agent\Escalation\ClientEscalationNoiseGate;
use App\Services\Agent\Escalation\EscalationNotifier;
use App\Services\Technician\TechnicianActionGate;
use App\Support\AgentConfig;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The agent's SECOND action — "this is over my head, a human needs to look."
 *
 * Mirrors ProposeCloseTool's gated-run shape, with one load-bearing difference:
 * a flag has NO execution side-effect. It is a NOTICE, not an executable action.
 * So:
 *  - it records a HELD run in the dedicated Flagged state (its own cockpit lane);
 *  - it routes through the gate for the audit row + kill-switch/exclusion checks,
 *    but with a STRICT NO-OP executor (it must never touch a ticket or client);
 *  - it can NEVER auto-execute (the classifier hard-codes flag_attention to Approve,
 *    so even an operator who maps it to 'auto' cannot make a flag act);
 *  - Increment H: when agent_escalation_enabled is on, calls EscalationNotifier to
 *    deliver the flag to the role-routed human. Dormant when off (flag records exactly
 *    as before). The "already flagged" duplicate returns BEFORE the wire-in, so
 *    re-flagging the same blocker does NOT re-notify (no spam).
 *
 * Resolution happens human-side in the cockpit: acknowledge (→ Done) or dismiss
 * (→ Denied). There is no executor to approve.
 */
class FlagAttentionTool
{
    private const CLIENT_ESCALATION_LOCK_SECONDS = 180;

    public function __construct(
        private readonly TechnicianActionGate $gate,
        private readonly EscalationNotifier $escalation,
        private readonly ClientEscalationNoiseGate $noiseGate,
    ) {}

    /** The Anthropic tool definition for `flag_attention`. */
    public static function definition(): array
    {
        return [
            'name' => 'flag_attention',
            'description' => 'Flag this ticket for a human when it is genuinely over your head — a decision you cannot make, something you cannot resolve, or blocking ambiguity that needs a person. This is a NOTICE to a human, not a fix and NOT a close: it does nothing to the ticket. Use it sparingly and only for a real need for human attention; when unsure whether something needs a person, LEAVE IT instead (do not flag low-value or routine tickets).',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'reason' => [
                        'type' => 'string',
                        'description' => 'One to three sentences: what is blocking you and why this needs a person (e.g. "The client is asking for a refund I am not authorised to approve; this needs a decision from the owner").',
                    ],
                    'category' => [
                        'type' => 'string',
                        'enum' => ['needs_decision', 'needs_hands_onsite', 'needs_overflow', 'uncertain', 'other'],
                        'description' => 'Best-fit reason shape: needs_decision (a judgement call), needs_hands_onsite (someone must physically attend), needs_overflow (real work but no capacity), uncertain (you cannot tell what is needed), or other.',
                    ],
                ],
                'required' => ['reason', 'category'],
            ],
        ];
    }

    /**
     * Record a held flag and route it through the gate (audit + fail-closed checks)
     * with a no-op executor. Returns a short string the model sees in its tool_result.
     *
     * @param  array|null  $correctionContext  When the run was correction-driven, merged into
     *                                         proposed_meta as 'informed_by_correction' so the run
     *                                         is traceable to the operator correction. Null on a
     *                                         normal run — key absent from proposed_meta.
     */
    public function execute(Ticket $ticket, array $input, ?array $correctionContext = null): string
    {
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            return "Left ticket #{$ticket->id} (no flag reason given — nothing recorded).";
        }

        $category = FlagAttentionCategory::fromInput($input['category'] ?? null);
        $hash = hash('sha256', 'flag_attention:'.$ticket->id.':'.$reason);

        $existing = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'flag_attention')
            ->where('content_hash', $hash)
            ->first();

        // Idempotency: an identical flag is already held — do not duplicate it.
        if ($existing !== null && $existing->state === TechnicianRunState::Flagged) {
            return "Already flagged ticket #{$ticket->id} for human attention.";
        }

        // Anti-flood: a NEW flag is subject to the pending-flag cap so flags can't
        // flood the cockpit. Reviving an existing run is deliberately NOT re-counted —
        // a recurring need re-surfacing is desirable, and capping a revive could silently
        // drop a real escalation. The transient overshoot is bounded: the agent acts on
        // one ticket per run and the one-action-per-run guard allows at most one
        // create/revive per run, so revives accrue ~one at a time, not in a burst.
        // Honors agent_max_pending, separately from close proposals so neither lane can
        // starve the other.
        if ($existing === null) {
            $pending = TechnicianRun::where('action_type', 'flag_attention')
                ->where('state', TechnicianRunState::Flagged)
                ->count();

            if ($pending >= AgentConfig::maxPendingProposals()) {
                return "Left ticket #{$ticket->id} (the flag queue is full — bounded to avoid flooding the cockpit).";
            }
        }

        // Create a new held flag, or revive a previously resolved one so a recurring
        // need re-surfaces (mirrors ProposeCloseTool's revive-stale behaviour).
        $flagMeta = ['category' => $category->value, 'reason' => $reason];
        if ($correctionContext !== null) {
            $flagMeta['informed_by_correction'] = $correctionContext;
        }

        $run = TechnicianRun::updateOrCreate(
            ['ticket_id' => $ticket->id, 'action_type' => 'flag_attention', 'content_hash' => $hash],
            [
                'client_id' => $ticket->client_id,
                'state' => TechnicianRunState::Flagged,
                'proposed_content' => $reason,
                'proposed_meta' => $flagMeta,
                'confidence' => null,
                'tokens_used' => 0,
            ],
        );

        // Route through the gate for the durable held-audit row and the fail-closed
        // kill-switch / client-exclusion checks. The executor is intentionally EMPTY:
        // a flag has no execution side-effect. The classifier hard-codes flag_attention
        // to Approve, so the gate records awaiting_approval and never runs this anyway.
        $gateResult = $this->gate->dispatch(
            actionType: 'flag_attention',
            ticketId: $ticket->id,
            clientId: $ticket->client_id,
            contentHash: $hash,
            summary: "The AI Technician flagged ticket #{$ticket->id} for human attention: {$reason}",
            runId: $run->id,
            executor: function (): void {
                // Intentionally empty — a flag is a notice, never an executable action.
            },
            confidence: null,
        );

        // Increment H: when escalation notifications are enabled, notify the role-routed human. Dormant when off
        // (flag records exactly as before). Reached only for a NEW/revived flag — the "already flagged" duplicate
        // returned earlier, so re-flagging the same blocker does NOT re-notify (no spam). Fail-soft inside notify().
        if (AgentConfig::escalationEnabled() && $gateResult->status === 'awaiting_approval') {
            $this->notifyOrSuppress($ticket, $run, $category, $reason);
        }

        return "Flagged ticket #{$ticket->id} for human attention ({$category->label()}).";
    }

    private function notifyOrSuppress(
        Ticket $ticket,
        TechnicianRun $run,
        FlagAttentionCategory $category,
        string $reason,
    ): void {
        $lockKey = $this->noiseGate->lockKey($ticket);

        if ($lockKey === null) {
            $this->escalation->notify($ticket, $run, $category, $reason);

            return;
        }

        try {
            Cache::lock($lockKey, self::CLIENT_ESCALATION_LOCK_SECONDS)
                ->betweenBlockedAttemptsSleepFor(100)
                ->block(self::CLIENT_ESCALATION_LOCK_SECONDS, function () use ($ticket, $run, $category, $reason): void {
                    $suppression = $this->noiseGate->suppressionFor($ticket, $run);

                    if ($suppression !== null) {
                        $this->recordSuppression($run, $category, $suppression);

                        return;
                    }

                    $this->escalation->notify($ticket, $run, $category, $reason);
                });
        } catch (LockTimeoutException) {
            Log::warning('[FlagAttentionTool] Client escalation lock timed out; notifying fail-open to avoid a zero-ping escalation', [
                'ticket_id' => $ticket->id,
                'client_id' => $ticket->client_id,
                'run_id' => $run->id,
            ]);

            $this->escalation->notify($ticket, $run, $category, $reason);
        }
    }

    /** @param array<string, mixed> $suppression */
    private function recordSuppression(TechnicianRun $run, FlagAttentionCategory $category, array $suppression): void
    {
        unset($suppression['notified_at']);

        $meta = $run->fresh()->proposed_meta ?? [];
        $existing = $meta['escalation'] ?? [];
        unset($existing['notified_at']);

        $meta['escalation'] = array_merge($existing, $suppression, [
            'category' => $category->value,
            'suppressed_at' => now()->toIso8601String(),
        ]);

        $run->proposed_meta = $meta;
        $run->save();
    }
}
