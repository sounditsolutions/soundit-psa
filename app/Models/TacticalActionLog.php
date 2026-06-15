<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit row for a Tactical action dispatch (spec §5.2, §11).
 *
 * Immutable by design: UPDATED_AT is disabled and the boot updating/deleting
 * guards throw. The DB triggers (MariaDB/MySQL) are the defence-in-depth layer
 * that also blocks raw query-builder writes; this model guard is what covers
 * SQLite (tests) and catches accidental Eloquent mutation everywhere.
 *
 * @property int $id
 * @property int|null $actor_id
 * @property string $actor_label
 * @property string $action_key
 * @property string|null $agent_id
 * @property int|null $asset_id
 * @property int|null $ticket_id
 * @property string $target_label
 * @property array $params
 * @property string $result_status
 * @property int|null $retcode
 * @property string|null $output
 * @property string|null $message
 * @property string $correlation_id
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class TacticalActionLog extends Model
{
    /** Append-only: no updated_at column / timestamp. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'actor_label',
        'action_key',
        'agent_id',
        'asset_id',
        'ticket_id',
        'target_label',
        'params',
        'result_status',
        'retcode',
        'output',
        'message',
        'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'retcode' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Append-only: block any mutation of an existing row. `updating` only
        // fires for rows that already exist, so inserts pass through.
        static::updating(function (self $log): void {
            throw new LogicException('tactical_action_logs is append-only');
        });

        static::deleting(function (self $log): void {
            throw new LogicException('tactical_action_logs is append-only');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
