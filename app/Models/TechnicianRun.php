<?php

namespace App\Models;

use App\Enums\TechnicianRunState;
use App\Support\TechnicianConfig;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

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

    /**
     * Move the run to $state and save. Returns whether the move was written.
     *
     * #6197 / #6263: when this instance holds a claim (its state is Executing) the move is one
     * compare-and-set UPDATE under the claim-owner fence (whereHoldsClaim()): it lands only while
     * the row is still Executing under this instance's claimed_at. Every other row gets false
     * and is not touched: a run claimed again by another request, and a run that left Executing
     * (queued, closed, reopened, flagged or cancelled by another path). The instance keeps its
     * state and its unsaved changes. A claim-holding caller therefore reads false as "this
     * request did not close the run", never as success.
     *
     * #6267 / #6270: the instance's other unsaved changes ride in the same fenced UPDATE, so no
     * second, unfenced write follows and no model event fires on this path. The observer acts
     * only on a move into AwaitingApproval, which no claimed caller makes.
     *
     * #6305: a run that another path moved out of Executing after this holder's side effect
     * (for example reopened to AwaitingApproval) is left where that path put it; this holder
     * does not land it Done over the other path's decision. The caller gets false and must say
     * that the run was not closed, and its lost-fence record (audit row or log line) is what
     * shows that the side effect ran. No path reopens an Executing vendor-executor run today:
     * the stale-claim reaper reopens only RECOVERY_SAFE_ACTION_TYPES, whose lost fence rolls
     * the side effect back (TechnicianApprovalService::closeOrRollBack()).
     *
     * #6310 / #6262: the bool is the UPDATE's affected-row count. MariaDB reports changed rows,
     * not matched rows (PDO::MYSQL_ATTR_FOUND_ROWS is not set). The fence requires the stored
     * state to be Executing, so for every target other than Executing the state column changes
     * and the owner's UPDATE reports 1; that, not updated_at (stored to the second), is the
     * guarantee. A same-second advance from Executing to Executing with nothing else dirty can
     * report 0, and so false, on MariaDB while SQLite reports 1. No app caller targets
     * Executing; a caller that ever does must not read false there as a lost fence.
     *
     * #6317: an instance that holds no claim (any other in-memory state) moves the run only
     * while the stored state is still the state this instance holds in memory, read under a
     * row lock inside a transaction, and saves through the model so its events still fire
     * (the observer's AwaitingApproval notification). A run another request moved meanwhile
     * (for example claimed it to Executing) is not overwritten: the call returns false.
     */
    public function advanceTo(TechnicianRunState $state): bool
    {
        if (! $this->exists) {
            $this->state = $state;
            $this->save();

            return true;
        }

        if ($this->state !== TechnicianRunState::Executing) {
            return $this->advanceUnclaimedTo($state);
        }

        $now = $this->freshTimestamp();
        $values = array_merge($this->getDirty(), [
            'state' => $state->value,
            $this->getUpdatedAtColumn() => $this->fromDateTime($now),
        ]);
        if ($this->whereHoldsClaim(static::query()->whereKey($this->getKey()))->update($values) !== 1) {
            return false;
        }

        $this->state = $state;
        $this->setUpdatedAt($now);
        $this->syncOriginal();

        return true;
    }

    /** #6317: the unclaimed branch of advanceTo(): a compare-and-set on the in-memory state. */
    private function advanceUnclaimedTo(TechnicianRunState $state): bool
    {
        $expected = $this->state;

        return $this->getConnection()->transaction(function () use ($state, $expected): bool {
            $stored = static::query()->whereKey($this->getKey())->lockForUpdate()->first(['id', 'state']);
            if ($stored === null || $stored->state !== $expected) {
                return false;
            }

            $this->state = $state;
            if (! $this->save()) {
                $this->state = $expected;

                return false;
            }

            return true;
        });
    }

    /**
     * #6125 / #6197: the claim-owner fence. Narrows $query to the run still Executing under the
     * claim this instance holds: the claimed_at stamp its claimForExecution() /
     * claimQueuedForExecution() wrote, or that it was loaded with (null for a legacy claim with
     * no stamp). #6323: the stamp is read from the instance's original attributes, so a
     * claimed_at the caller changed in memory and has not saved does not move the fence key. A run reopened and claimed again carries the new claim's stamp, so a stale
     * holder's write matches no row. The stamp is stored to the second, so a re-claim within the
     * same second is not told apart (#6191), and an instance that reloads the run after another
     * claim carries that claim's stamp (#6190).
     *
     * @param  Builder<TechnicianRun>  $query
     * @return Builder<TechnicianRun>
     */
    private function whereHoldsClaim(Builder $query): Builder
    {
        $claimedAt = $this->getOriginal('claimed_at');

        return $query->where('state', TechnicianRunState::Executing->value)
            ->where(fn (Builder $q) => $claimedAt === null
                ? $q->whereNull('claimed_at')
                : $q->where('claimed_at', $claimedAt));
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
            // #6323: the row now holds this claim, so it is the original the fence keys on.
            $this->syncOriginalAttributes(['state', 'claimed_at']);
        }

        return $claimed;
    }

    /**
     * Release a claimed run back to the queue (executing → awaiting_approval). Direct update
     * because claimForExecution bypasses dirty tracking. Returns whether the CAS won — false
     * means the run was no longer Executing (it completed, or another releaser beat us) or is
     * Executing under a claim this instance does not hold (#6125), which lets the stale-claim
     * reaper count only the runs it actually rescued.
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
        // #6125: claim-owner fence (whereHoldsClaim()). A stale release from the first
        // claimant loses instead of reopening a claim another approver now holds.
        $released = $this->whereHoldsClaim(static::query()->whereKey($this->getKey()))
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
        'propose_asset_merge',
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
     * #6197: the CAS carries the claim-owner fence (whereHoldsClaim()), so a stale holder
     * whose claim was replaced by another gets false and cannot park that claim's run.
     */
    public function queueForOffline(string $agentId, string $dedupKey, CarbonInterface $queuedAt, CarbonInterface $expiresAt, array $metaPatch = []): bool
    {
        // Merge the meta patch (e.g. queued_approver_id) INTO the single CAS update so
        // there is no second write to race: a separate ->save() would re-persist the
        // whole model and could stomp a concurrent cancel/expire back to queued_offline.
        $meta = array_merge($this->proposed_meta ?? [], $metaPatch);

        $queued = $this->whereHoldsClaim(static::query()->whereKey($this->getKey()))
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
            // #6323: as in claimForExecution().
            $this->syncOriginalAttributes(['state', 'claimed_at']);
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

    /**
     * The drafting token withdraws its own still-pending proposal (card XUiMXNEH):
     * awaiting_approval → withdrawn, with the withdrawal recorded in proposed_meta.
     *
     * CAS on the state, in ONE write: claimForExecution() moves an approved run out of
     * awaiting_approval with the same kind of conditional UPDATE, so whichever lands
     * first wins and the other is a no-op. A racing approval therefore never has its run
     * pulled from under it, and a withdrawal never resurrects a run that already left
     * the queue. The meta rides in the same UPDATE (as queueForOffline() does) so there
     * is no second write to race.
     *
     * Withdrawn, never Superseded or Denied: both of those read as a human decision
     * (CloseBandEvaluator counts Superseded as corrected and Denied as declined), and
     * nobody decided anything here — the drafter took its own proposal back.
     *
     * Who may call this is the caller's question (WithdrawStagedActionTool checks the
     * drafting token); this method only guarantees the transition is atomic.
     *
     * @param  array<string, mixed>  $metaPatch
     */
    public function withdrawByDrafter(array $metaPatch): bool
    {
        $meta = array_merge($this->proposed_meta ?? [], $metaPatch);

        $withdrawn = static::query()
            ->whereKey($this->getKey())
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->update([
                'state' => TechnicianRunState::Withdrawn->value,
                'proposed_meta' => json_encode($meta),
            ]) === 1;

        if ($withdrawn) {
            $this->state = TechnicianRunState::Withdrawn;
            $this->proposed_meta = $meta;
            $this->syncOriginalAttributes(['state', 'proposed_meta']);
        }

        return $withdrawn;
    }

    /** Whether the move was written; see advanceTo(). */
    public function markSuperseded(): bool
    {
        return $this->advanceTo(TechnicianRunState::Superseded);
    }

    /**
     * #6306: supersede every run in $runs. Each run is tried on its own: a run another request
     * moved meanwhile (markSuperseded() false) is left where that request put it, and the sweep
     * goes on to the rest. (Collection::each() stops at a callback that returns false, which an
     * arrow function or a higher-order ->each->markSuperseded() would.) Returns the ids that
     * were not superseded, and logs them (ids only) when there are any.
     *
     * @param  iterable<TechnicianRun>  $runs
     * @return list<int>
     */
    public static function supersedeEach(iterable $runs): array
    {
        $kept = [];
        foreach ($runs as $run) {
            if (! $run->markSuperseded()) {
                $kept[] = (int) $run->id;
            }
        }
        if ($kept !== []) {
            Log::info('[TechnicianRun] supersede sweep: runs not superseded', ['run_ids' => $kept]);
        }

        return $kept;
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
