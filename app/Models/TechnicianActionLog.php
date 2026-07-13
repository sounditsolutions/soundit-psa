<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit row for an AI-Technician action dispatch (spec §4.3/§4.6).
 *
 * Immutable by design: UPDATED_AT is disabled and the boot updating/deleting
 * guards throw. DB triggers (MariaDB/MySQL) are the defence-in-depth layer that
 * also blocks raw query-builder writes; this model guard covers SQLite (tests).
 *
 * @property int $id
 * @property int|null $actor_id
 * @property int|null $approver_user_id
 * @property string $actor_label
 * @property string $action_type
 * @property string $tier
 * @property string $result_status
 * @property int|null $ticket_id
 * @property int|null $client_id
 * @property int|null $run_id
 * @property string $content_hash
 * @property string $summary
 * @property array|null $approved_recipients
 * @property string $correlation_id
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class TechnicianActionLog extends Model
{
    /** Append-only: no updated_at column / timestamp. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'approver_user_id',
        'actor_label',
        'action_type',
        'tier',
        'result_status',
        'ticket_id',
        'client_id',
        'run_id',
        'content_hash',
        'summary',
        'approved_recipients',
        'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'approved_recipients' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Append-only: block any mutation of an existing row. `updating` only
        // fires for rows that already exist, so inserts pass through.
        static::updating(function (self $log): void {
            throw new LogicException('technician_action_logs is append-only');
        });

        static::deleting(function (self $log): void {
            throw new LogicException('technician_action_logs is append-only');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** The human who approved a non-AUTO action (null for AUTO actions). */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
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
