<?php

namespace App\Models;

use App\Enums\QuantityType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sku extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'sku_code',
        'category',
        'unit_price',
        'unit_cost',
        'prepaid_time_minutes',
        'included_per_unit',
        'default_quantity_type',
        'default_license_type_id',
        'is_taxable',
        'is_active',
        'qbo_item_id',
        'qbo_income_account_id',
        'qbo_expense_account_id',
        'qbo_sync_hash',
        'qbo_synced_at',
        'qbo_sync_error',
        'stripe_product_id',
        'stripe_price_id',
        'stripe_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'prepaid_time_minutes' => 'integer',
            'included_per_unit' => 'integer',
            'default_quantity_type' => QuantityType::class,
            'is_taxable' => 'boolean',
            'is_active' => 'boolean',
            'qbo_synced_at' => 'datetime',
            'stripe_synced_at' => 'datetime',
        ];
    }

    // ── Relations ──

    public function defaultLicenseType(): BelongsTo
    {
        return $this->belongsTo(LicenseType::class, 'default_license_type_id');
    }

    public function profileLines(): HasMany
    {
        return $this->hasMany(RecurringInvoiceProfileLine::class);
    }

    public function invoiceLines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    // ── Scopes ──

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('sku_code', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%");
        });
    }

    // ── Accessors ──

    public function getMarginAttribute(): ?float
    {
        if ((float) $this->unit_price === 0.0) {
            return null;
        }

        return round(((float) $this->unit_price - (float) $this->unit_cost) / (float) $this->unit_price * 100, 1);
    }
}
