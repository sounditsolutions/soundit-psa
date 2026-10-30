<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One "Sync now" attempt on an asset's Sources panel (append-only audit,
 * refusals included). See the create_asset_source_syncs_table migration.
 *
 * @property int $id
 * @property int $asset_id
 * @property string $source
 * @property ?int $user_id
 * @property string $outcome
 * @property string $reason
 * @property ?int $http_status
 * @property ?int $retry_after_s
 * @property \Illuminate\Support\Carbon $started_at
 * @property int $duration_ms
 */
class AssetSourceSync extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'asset_id',
        'source',
        'user_id',
        'outcome',
        'reason',
        'http_status',
        'retry_after_s',
        'started_at',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'asset_id' => 'integer',
            'user_id' => 'integer',
            'http_status' => 'integer',
            'retry_after_s' => 'integer',
            'started_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('asset_source_syncs is append-only: a row is never updated.');
        });
        static::deleting(function (): void {
            throw new LogicException('asset_source_syncs is append-only: a row is never deleted.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
