<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    protected $fillable = [
        'halo_id',
        'invoice_id',
        'sku_id',
        'description',
        'quantity',
        'unit_price',
        'unit_cost',
        'amount',
        'cost_amount',
        'prepaid_time_minutes',
        'quantity_source',
        'is_taxable',
        'qbo_item_ref',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'amount' => 'decimal:2',
            'cost_amount' => 'decimal:2',
            'prepaid_time_minutes' => 'integer',
            'is_taxable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    // ── Relations ──

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
