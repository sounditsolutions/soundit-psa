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

    // ── Relations ──

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
