<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupStorageTier extends Model
{
    protected $fillable = [
        'sku_id',
        'up_to_gb',
        'unit_price',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'up_to_gb' => 'integer',
            'unit_price' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /** Bounds ascending, null last, then id; id-less snapshots retain their order. */
    public static function compareRateCard(self|array $a, self|array $b): int
    {
        $left = $a['up_to_gb'];
        $right = $b['up_to_gb'];
        $bounds = ($left === null) <=> ($right === null);
        if ($bounds === 0) {
            $bounds = $left <=> $right;
        }

        return $bounds ?: (($a['id'] ?? 0) <=> ($b['id'] ?? 0));
    }

    // ── Relations ──

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
