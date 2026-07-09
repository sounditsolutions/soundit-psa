<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user-defined quantity type: a named counter over assets whose asset_type
 * matches a configured set. Extends the built-in Per Workstation / Per Server
 * asset counters (which are limited to the workstation/server type mappings)
 * to arbitrary, operator-defined asset categories (firewalls, switches,
 * printers, access points, etc.).
 *
 * Resolution is contract-scoped like the built-in asset counters — see
 * BillingService::resolveQuantity() / countAssets().
 */
class CustomQuantityType extends Model
{
    protected $fillable = [
        'name',
        'description',
        'asset_types',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'asset_types' => 'array',
            'is_active' => 'boolean',
        ];
    }

    // ── Relations ──

    public function profileLines(): HasMany
    {
        return $this->hasMany(RecurringInvoiceProfileLine::class);
    }

    // ── Scopes ──

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
