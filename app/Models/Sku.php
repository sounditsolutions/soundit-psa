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

    /**
     * Backup-storage volume-pricing tiers. Ordered as a rate card:
     * bounded tiers ascending by GB, the unbounded (null) tier last.
     */
    public function backupStorageTiers(): HasMany
    {
        return $this->hasMany(BackupStorageTier::class)
            ->orderByRaw('up_to_gb IS NULL, up_to_gb ASC');
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

    /**
     * Resolve the volume-pricing rate (price per GB) for a measured backup
     * storage total, using this SKU's tier rate card.
     *
     * Volume pricing: the whole quantity is billed at the rate of the first
     * tier whose upper bound covers it. A tier with a null `up_to_gb` is the
     * unbounded catch-all. If usage exceeds every bounded tier and no
     * unbounded tier exists, the highest bounded tier's rate applies (we
     * never silently drop to an unpriced fallback once tiers are defined).
     *
     * Returns null when the SKU has no tiers configured, so callers can fall
     * back to the flat line/SKU unit price.
     */
    public function priceForStorageGb(int $gb): ?float
    {
        $tiers = $this->relationLoaded('backupStorageTiers')
            ? $this->backupStorageTiers
            : $this->backupStorageTiers()->get();

        if ($tiers->isEmpty()) {
            return null;
        }

        // Defensive re-sort in case the collection arrived unordered (e.g.
        // hydrated without the relation's orderBy): bounded ascending, the
        // unbounded tier last.
        $sorted = $tiers->sort(function (BackupStorageTier $a, BackupStorageTier $b) {
            if ($a->up_to_gb === null) {
                return 1;
            }
            if ($b->up_to_gb === null) {
                return -1;
            }

            return $a->up_to_gb <=> $b->up_to_gb;
        })->values();

        foreach ($sorted as $tier) {
            if ($tier->up_to_gb === null || $gb <= $tier->up_to_gb) {
                return (float) $tier->unit_price;
            }
        }

        return (float) $sorted->last()->unit_price;
    }
}
