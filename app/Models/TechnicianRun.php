<?php

namespace App\Models;

use App\Enums\TechnicianRunState;
use Carbon\CarbonInterface;
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
     * Single-use latch (Plan 1B): atomically move awaiting_approval → executing.
     * Returns true only for the caller that won the race; a replayed grant or a
     * double-tap finds the run no longer awaiting and gets false (no double-send).
     */
    public function claimForExecution(): bool
    {
        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->update(['state' => TechnicianRunState::Executing->value]) === 1;

        if ($claimed) {
            $this->state = TechnicianRunState::Executing;
        }

        return $claimed;
    }

    /** Release a claimed run back to the queue (executing → awaiting_approval). Direct update because claimForExecution bypasses dirty tracking. */
    public function releaseClaim(): void
    {
        $this->releaseClaimTo(TechnicianRunState::AwaitingApproval);
    }

    /**
     * Release a claimed (executing) run back to a specific waiting state. A live
     * approval releases to AwaitingApproval; a reconnect-run that fails releases
     * to QueuedOffline so the queue keeps waiting rather than re-entering the
     * human approval lane. Direct update because the claim bypasses dirty tracking.
     */
    public function releaseClaimTo(TechnicianRunState $state): void
    {
        static::query()->whereKey($this->getKey())
            ->where('state', TechnicianRunState::Executing->value)
            ->update(['state' => $state->value]);
        $this->state = $state;
    }

    /**
     * Park an approved-but-offline action in the queue (executing → queued_offline)
     * with its target agent, coalesce key, and safety window. CAS latch: only the
     * claim winner that is still Executing transitions. queued_at/expires_at are
     * passed in so a failed reconnect-run can re-queue preserving the ORIGINAL
     * window rather than resetting the expiry clock. Returns true for the winner.
     */
    public function queueForOffline(string $agentId, string $dedupKey, CarbonInterface $queuedAt, CarbonInterface $expiresAt): bool
    {
        $queued = static::query()
            ->whereKey($this->getKey())
            ->where('state', TechnicianRunState::Executing->value)
            ->update([
                'state' => TechnicianRunState::QueuedOffline->value,
                'queued_agent_id' => $agentId,
                'queued_dedup_key' => $dedupKey,
                'queued_at' => $queuedAt,
                'expires_at' => $expiresAt,
            ]) === 1;

        if ($queued) {
            $this->state = TechnicianRunState::QueuedOffline;
            $this->queued_agent_id = $agentId;
            $this->queued_dedup_key = $dedupKey;
            $this->queued_at = $queuedAt;
            $this->expires_at = $expiresAt;
        }

        return $queued;
    }

    /**
     * Claim a queued action for a reconnect-run (queued_offline → executing).
     * Single-use latch mirroring claimForExecution so two concurrent sweeps (device
     * sync + webhook fast-path) can never double-run the same queued action.
     */
    public function claimQueuedForExecution(): bool
    {
        return $this->casTransition(TechnicianRunState::QueuedOffline, TechnicianRunState::Executing);
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

    /** Operator re-confirms an expired action (expired → awaiting_approval), re-arming the normal approval flow. */
    public function reconfirmExpired(): bool
    {
        return $this->casTransition(TechnicianRunState::Expired, TechnicianRunState::AwaitingApproval);
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

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
