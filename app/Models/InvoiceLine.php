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
        'pre_void_amount',
        'pre_void_cost_amount',
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
            'pre_void_amount' => 'decimal:2',
            'pre_void_cost_amount' => 'decimal:2',
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

    // ── Accessors ──

    /**
     * Original pre-void amount for lines on voided invoices, live amount
     * otherwise. pre_void_amount is only ever written when the parent
     * invoice is voided (InvoiceVoidService), so a non-null value implies a
     * voided invoice without loading the parent.
     */
    public function getDisplayAmountAttribute(): ?string
    {
        return $this->pre_void_amount ?? $this->amount;
    }

    public function getDisplayCostAmountAttribute(): ?string
    {
        return $this->pre_void_amount !== null ? $this->pre_void_cost_amount : $this->cost_amount;
    }
}
