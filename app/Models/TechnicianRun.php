<?php

namespace App\Models;

use App\Enums\TechnicianRunState;
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
    ];

    protected function casts(): array
    {
        return [
            'state' => TechnicianRunState::class,
            'proposed_meta' => 'array',
            'confidence' => 'float',
            'tokens_used' => 'integer',
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
        static::query()->whereKey($this->getKey())
            ->where('state', TechnicianRunState::Executing->value)
            ->update(['state' => TechnicianRunState::AwaitingApproval->value]);
        $this->state = TechnicianRunState::AwaitingApproval;
    }

    public function deny(): void
    {
        $this->advanceTo(TechnicianRunState::Denied);
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
