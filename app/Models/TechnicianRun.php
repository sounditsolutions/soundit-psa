<?php

namespace App\Models;

use App\Enums\TechnicianRunState;
use App\Support\TechnicianConfig;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ticket_id
 * @property int|null $client_id
 * @property string $action_type
 * @property string $content_hash
 * @property TechnicianRunState $state
 * @property string|null $proposed_content
 * @property array|null $proposed_meta
 * @property float|null $confidence
 * @property int $tokens_used
 * @property string|null $queued_agent_id
 * @property string|null $queued_dedup_key
 * @property \Illuminate\Support\Carbon|null $queued_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property int $coalesce_count
 */
class TechnicianRun extends Model
{
    protected $fillable = [
        'ticket_id',
        'client_id',
        'action_type',
        'content_hash',
        'state',
        'claimed_at',
        'proposed_content',
        'proposed_meta',
        'confidence',
        'tokens_used',
        'queued_agent_id',
        'queued_dedup_key',
        'queued_at',
        'expires_at',
        'coalesce_count',
    ];

    protected function casts(): array
    {
        return [
            'state' => TechnicianRunState::class,
            'claimed_at' => 'datetime',
            'proposed_meta' => 'array',
            'confidence' => 'float',
            'tokens_used' => 'integer',
            'queued_at' => 'datetime',
            'expires_at' => 'datetime',
            'coalesce_count' => 'integer',
        ];
    }

    public function advanceTo(TechnicianRunState $state): void
    {
        $this->state = $state;
        $this->save();
    }

    /**
     * The persona name that DRAFTED this run — i.e. the AI half of the client-facing
     * credit (psa-u51h). Resolved from the BARE token label the staging path recorded,
     * since the token itself is long out of scope by approval time.
     *
     * ONE home on purpose: both the send (TechnicianApprovalService) and the operator's
     * approval-card preview must name the same drafter. Two copies of this lookup is how
     * the card comes to promise something the client never receives (psa-u51h.2).
     *
     * NOT proposed_meta['drafted_by'] — that is the PREFIXED audit form
     * ('mcp-staff:{label}'), which matches no persona and is not client-facing text.
     * A run staged before psa-u51h (or drafted natively, with no token) has no bare
     * label and degrades to the global actor name.
     */
    public function drafterDisplayName(): string
    {
        $label = data_get($this->proposed_meta, 'drafted_by_token');

        return TechnicianConfig::actorNameForTokenLabel(is_string($label) ? $label : null);
    }

    /**
     * Single-use latch (Plan 1B): atomically move awaiting_approval → executing.
     * Returns true only for the caller that won the race; a replayed grant or a
     * double-tap finds the run no longer awaiting and gets false (no double-send).
     */
    public function claimForExecution(): bool
    {
        // Stamp claimed_at inside the same CAS write so the stale-claim reaper (psa-xz0z) can
        // measure how long a run has been wedged in Executing — a process death between here and
        // releaseClaim()/advanceTo(Done) leaves no other trace.
        $now = now();
        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->update(['state' => TechnicianRunState::Executing->value, 'claimed_at' => $now]) === 1;

        if ($claimed) {
            $this->state = TechnicianRunState::Executing;
            $this->claimed_at = $now;
        }

        return $claimed;
    }

    /**
     * Release a claimed run back to the queue (executing → awaiting_approval). Direct update
     * because claimForExecution bypasses dirty tracking. Returns whether the CAS won — false
     * means the run was no longer Executing (it completed, or another releaser beat us), which
     * lets the stale-claim reaper count only the runs it actually rescued.
     */
    public function releaseClaim(): bool
    {
        return $this->releaseClaimTo(TechnicianRunState::AwaitingApproval);
    }

    /**
     * Release a claimed (executing) run back to a specific waiting state. A live
     * approval releases to AwaitingApproval; a reconnect-run that fails releases
     * to QueuedOffline so the queue keeps waiting rather than re-entering the
     * human approval lane. Direct update because the claim bypasses dirty tracking.
     */
    public function releaseClaimTo(TechnicianRunState $state): bool
    {
        $released = static::query()->whereKey($this->getKey())
            ->where('state', TechnicianRunState::Executing->value)
            ->update(['state' => $state->value]) === 1;
        // Only reflect the transition in memory when the CAS actually won — mirroring the DB so
        // the new bool contract isn't leaky (a lost CAS must not claim it moved the run).
        if ($released) {
            $this->state = $state;
        }

        return $released;
    }

    /**
     * Action types the stale-claim reaper (psa-xz0z) may safely return to awaiting_approval.
     *
     * SAFE means: the whole side effect is a single gate DB transaction (note/close/merge + audit
     * + advanceTo(Done)), and any EXTERNAL send happens only AFTER that transaction commits — so a
     * run still stuck in 'executing' provably never committed, never sent, and re-approval replays
     * cleanly (TechnicianApprovalService::approveAndSend/approveClose/approveMerge and the shared
     * approveStagedBodyAction, whose sendEmail is after the committed Done, ~:451).
     *
     * DELIBERATELY EXCLUDED (fail-safe — anything not listed is treated as unsafe): the staged
     * VENDOR actions (cipp_stage_*, tactical_stage_*) delegate to
     * Staff{Cipp,Tactical}*ToolExecutor::approveStagedRun, which fire the UPSTREAM call BEFORE the
     * local audit/Done. A crash between that call and Done leaves 'executing' with the side effect
     * already done, so reopening it would let a fresh approval DUPLICATE a create-user / script /
     * wipe. Those strands are surfaced for manual review, never auto-reopened.
     */
    public const RECOVERY_SAFE_ACTION_TYPES = [
        'send_reply',
        'propose_resolution',
        'propose_close',
        'propose_merge',
        'stage_email',
        'stage_public_note',
    ];

    /** Whether the reaper may return this stranded run to the queue without risking a duplicate side effect. */
    public function isRecoverySafeToReopen(): bool
    {
        return in_array($this->action_type, self::RECOVERY_SAFE_ACTION_TYPES, true);
    }

    /**
     * Runs wedged in Executing since before $before — the stale-claim reaper's candidates
     * (psa-xz0z). claimed_at is the authoritative claim time; updated_at is the fallback for
     * rows claimed before claimed_at existed (the claim's CAS ->update() stamped updated_at),
     * so a run stranded by the very DEPLOY that shipped this column is still recoverable.
     *
     * @param  Builder<TechnicianRun>  $query
     */
    public function scopeStaleExecuting(Builder $query, CarbonInterface $before): void
    {
        $query->where('state', TechnicianRunState::Executing->value)
            ->where(function (Builder $q) use ($before): void {
                $q->where('claimed_at', '<=', $before)
                    ->orWhere(function (Builder $inner) use ($before): void {
                        $inner->whereNull('claimed_at')->where('updated_at', '<=', $before);
                    });
            });
    }

    /**
     * Park an approved-but-offline action in the queue (executing → queued_offline)
     * with its target agent, coalesce key, and safety window. CAS latch: only the
     * claim winner that is still Executing transitions. queued_at/expires_at are
     * passed in so a failed reconnect-run can re-queue preserving the ORIGINAL
     * window rather than resetting the expiry clock. Returns true for the winner.
     */
    public function queueForOffline(string $agentId, string $dedupKey, CarbonInterface $queuedAt, CarbonInterface $expiresAt, array $metaPatch = []): bool
    {
        // Merge the meta patch (e.g. queued_approver_id) INTO the single CAS update so
        // there is no second write to race: a separate ->save() would re-persist the
        // whole model and could stomp a concurrent cancel/expire back to queued_offline.
        $meta = array_merge($this->proposed_meta ?? [], $metaPatch);

        $queued = static::query()
            ->whereKey($this->getKey())
            ->where('state', TechnicianRunState::Executing->value)
            ->update([
                'state' => TechnicianRunState::QueuedOffline->value,
                'queued_agent_id' => $agentId,
                'queued_dedup_key' => $dedupKey,
                'queued_at' => $queuedAt,
                'expires_at' => $expiresAt,
                'proposed_meta' => json_encode($meta),
            ]) === 1;

        if ($queued) {
            $this->state = TechnicianRunState::QueuedOffline;
            $this->queued_agent_id = $agentId;
            $this->queued_dedup_key = $dedupKey;
            $this->queued_at = $queuedAt;
            $this->expires_at = $expiresAt;
            $this->proposed_meta = $meta;
        }

        return $queued;
    }

    /**
     * Claim a queued action for a reconnect-run (queued_offline → executing).
     * Single-use latch mirroring claimForExecution so two concurrent sweeps (device
     * sync + webhook fast-path) can never double-run the same queued action. The
     * `expires_at > now()` guard is part of the CAS so a run that crossed its safety
     * window between the sweep's fetch and this claim can never still execute.
     */
    public function claimQueuedForExecution(): bool
    {
        $now = now();
        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where('state', TechnicianRunState::QueuedOffline->value)
            ->where('expires_at', '>', now())
            ->update(['state' => TechnicianRunState::Executing->value, 'claimed_at' => $now]) === 1;

        if ($claimed) {
            $this->state = TechnicianRunState::Executing;
            $this->claimed_at = $now;
        }

        return $claimed;
    }

    /** Operator cancelled a queued action from the cockpit (queued_offline → cancelled). CAS: no-op if it already ran/expired. */
    public function cancelQueued(): bool
    {
        return $this->casTransition(TechnicianRunState::QueuedOffline, TechnicianRunState::Cancelled);
    }

    /** Safety-window elapsed (queued_offline → expired). CAS so it can't race a reconnect-run that just claimed it. */
    public function expireQueued(): bool
    {
        return $this->casTransition(TechnicianRunState::QueuedOffline, TechnicianRunState::Expired);
    }

    /**
     * Operator re-confirms an expired action (expired → awaiting_approval), re-arming
     * the normal approval flow. Clears the stale queue window so that re-approving
     * while the device is still offline starts a FRESH safety window instead of
     * inheriting the already-elapsed expires_at and re-expiring immediately.
     */
    public function reconfirmExpired(): bool
    {
        $moved = static::query()
            ->whereKey($this->getKey())
            ->where('state', TechnicianRunState::Expired->value)
            ->update([
                'state' => TechnicianRunState::AwaitingApproval->value,
                'queued_at' => null,
                'expires_at' => null,
            ]) === 1;

        if ($moved) {
            $this->state = TechnicianRunState::AwaitingApproval;
            $this->queued_at = null;
            $this->expires_at = null;
        }

        return $moved;
    }

    /** State-guarded CAS: transition only if currently in $from; returns true for the winner. */
    private function casTransition(TechnicianRunState $from, TechnicianRunState $to): bool
    {
        $moved = static::query()
            ->whereKey($this->getKey())
            ->where('state', $from->value)
            ->update(['state' => $to->value]) === 1;

        if ($moved) {
            $this->state = $to;
        }

        return $moved;
    }

    public function deny(): bool
    {
        $denied = static::query()
            ->whereKey($this->getKey())
            ->whereNotIn('action_type', ['flag_attention', 'intake_route'])
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->update(['state' => TechnicianRunState::Denied->value]) === 1;

        if ($denied) {
            $this->state = TechnicianRunState::Denied;
        }

        return $denied;
    }

    /**
     * Acknowledge a held flag ("a human has got it") — Flagged → Done.
     * A flag has no execution; resolving it is a pure state transition.
     */
    public function acknowledgeFlag(): bool
    {
        return $this->resolveFlag(TechnicianRunState::Done);
    }

    /** Dismiss a held flag ("not something a person needs after all") — Flagged → Denied. */
    public function dismissFlag(): bool
    {
        return $this->resolveFlag(TechnicianRunState::Denied);
    }

    /**
     * CAS-guarded flag resolution: only a run that is BOTH a flag_attention AND
     * still Flagged transitions. So acknowledge/dismiss is a no-op on a proposal
     * (wrong action_type) or an already-resolved flag (wrong state) — and a
     * double-tap can never double-resolve. Returns true only for the winner.
     */
    private function resolveFlag(TechnicianRunState $to): bool
    {
        $resolved = static::query()
            ->whereKey($this->getKey())
            ->where('action_type', 'flag_attention')
            ->where('state', TechnicianRunState::Flagged->value)
            ->update(['state' => $to->value]) === 1;

        if ($resolved) {
            $this->state = $to;
        }

        return $resolved;
    }

    /**
     * Dismiss a held intake suggestion (operator has reviewed the calibration signal).
     * CAS guard: only an intake_route run still in AwaitingApproval transitions → Done.
     * A double-tap or a wrong-type call is a safe no-op. Returns true for the winner.
     */
    public function dismissIntake(): bool
    {
        $resolved = static::query()
            ->whereKey($this->getKey())
            ->where('action_type', 'intake_route')
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->update(['state' => TechnicianRunState::Done->value]) === 1;

        if ($resolved) {
            $this->state = TechnicianRunState::Done;
        }

        return $resolved;
    }

    public function markSuperseded(): void
    {
        $this->advanceTo(TechnicianRunState::Superseded);
    }

    /**
     * Auto-withdraw held close proposals for a ticket that was Closed by someone
     * else (psa-y4ft, part 3). Bulk CAS scoped to awaiting_approval ONLY, so an
     * in-flight approval — which claims its run to Executing BEFORE closing the
     * ticket — is never clobbered. Returns the number of proposals withdrawn.
     */
    public static function withdrawHeldClosesForClosedTicket(int $ticketId): int
    {
        return static::query()
            ->where('ticket_id', $ticketId)
            ->where('action_type', 'propose_close')
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->update(['state' => TechnicianRunState::Withdrawn->value]);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
