<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LicenseType extends Model
{
    protected $fillable = [
        'name',
        'vendor',
        'vendor_sku_id',
        'sku_id',
        'default_unit_cost',
        'cost_divisor',
        'minimum_quantity',
        'minimum_cost',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_unit_cost' => 'decimal:4',
            'cost_divisor' => 'integer',
            'minimum_quantity' => 'integer',
            'minimum_cost' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Effective cost per unit, accounting for the divisor.
     * E.g., $6.00 / 1024 = $0.005859 per GB when priced per TB.
     */
    public function getEffectiveUnitCostAttribute(): ?float
    {
        if ($this->default_unit_cost === null) {
            return null;
        }

        $divisor = $this->cost_divisor ?? 1;

        return $divisor > 1
            ? (float) $this->default_unit_cost / $divisor
            : (float) $this->default_unit_cost;
    }

    /**
     * Calculate estimated total cost for a given quantity, applying all cost modifiers:
     * 1. Quantity minimum: max(qty, minimum_quantity)
     * 2. Unit cost with divisor: effective_unit_cost × billable_qty
     * 3. Cost floor: max(calculated, minimum_cost)
     */
    public function estimateCost(int $quantity): ?float
    {
        if ($this->effective_unit_cost === null && $this->minimum_cost === null) {
            return null;
        }

        $billableQty = $this->minimum_quantity
            ? max($quantity, $this->minimum_quantity)
            : $quantity;

        $calculated = $this->effective_unit_cost
            ? $billableQty * $this->effective_unit_cost
            : 0;

        return $this->minimum_cost
            ? max($calculated, (float) $this->minimum_cost)
            : $calculated;
    }

    // ── Relations ──

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    // ── Scopes ──

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForVendor(Builder $query, string $vendor): Builder
    {
        return $query->where('vendor', $vendor);
    }
}
