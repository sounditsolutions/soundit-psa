<?php

namespace App\Models;

use App\Enums\QuantityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringInvoiceProfileLine extends Model
{
    protected $fillable = [
        'halo_id',
        'profile_id',
        'sku_id',
        'license_type_id',
        'custom_quantity_type_id',
        'usage_license_type_id',
        'base_license_type_id',
        'included_per_base_unit',
        'overage_divisor',
        'description',
        'unit_price',
        'unit_cost_override',
        'prepaid_time_override',
        'quantity_type',
        'fixed_quantity',
        'is_taxable',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity_type' => QuantityType::class,
            'custom_quantity_type_id' => 'integer',
            'unit_price' => 'decimal:2',
            'unit_cost_override' => 'decimal:2',
            'prepaid_time_override' => 'integer',
            'included_per_base_unit' => 'integer',
            'overage_divisor' => 'integer',
            'fixed_quantity' => 'decimal:2',
            'is_taxable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    // ── Relations ──

    public function profile(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoiceProfile::class, 'profile_id');
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    public function licenseType(): BelongsTo
    {
        return $this->belongsTo(LicenseType::class);
    }

    public function customQuantityType(): BelongsTo
    {
        return $this->belongsTo(CustomQuantityType::class);
    }

    public function usageLicenseType(): BelongsTo
    {
        return $this->belongsTo(LicenseType::class, 'usage_license_type_id');
    }

    public function baseLicenseType(): BelongsTo
    {
        return $this->belongsTo(LicenseType::class, 'base_license_type_id');
    }
}
