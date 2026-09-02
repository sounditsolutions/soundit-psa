<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Mesh allow rule this PSA created, and the state machine that ends it.
 *
 * Mesh does not expire its own rules (measured 2026-09-01 — `date_expiry` is
 * display only), so a row here is the sole reason any rule this system writes
 * ever goes away. See the migration for what each state means.
 */
class MeshAllowRule extends Model
{
    public const STATE_ACTIVE = 'active';

    /** Created and scope-proved, but the upstream rule id is not yet known. */
    public const STATE_UNRESOLVED = 'unresolved';

    /** Deleted AND proved absent by a 404 on the detail read. */
    public const STATE_REAPED = 'reaped';

    public const STATE_REAP_FAILED = 'reap_failed';

    /**
     * Default lifetime for a new allow rule. Client-side constant: the value
     * is what the PSA enforces, and it is also sent to Mesh as `date_expiry`
     * so the vendor portal displays the same lifetime a technician was told
     * (#1018 criterion 4).
     */
    public const DEFAULT_LIFETIME_DAYS = 90;

    protected $fillable = [
        'client_id',
        'ticket_id',
        'technician_run_id',
        'mesh_customer_id',
        'sender',
        'comment',
        'mesh_rule_id',
        'expires_at',
        'state',
        'created_by_actor',
        'approver_user_id',
        'upstream_created_by',
        'reaped_at',
        'last_error',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'reaped_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Rows the reaper should act on: past expiry and not already proved gone.
     * `unresolved` is included deliberately — those rules ARE live upstream
     * and expiring them requires resolving the id first, so excluding them
     * would quietly make them permanent.
     */
    public function scopeReapable(Builder $query): Builder
    {
        return $query
            ->whereIn('state', [self::STATE_ACTIVE, self::STATE_UNRESOLVED, self::STATE_REAP_FAILED])
            ->where('expires_at', '<=', now());
    }
}
