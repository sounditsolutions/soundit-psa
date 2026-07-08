<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class License extends Model
{
    protected $fillable = [
        'license_type_id',
        'client_id',
        'quantity',
        'assigned_quantity',
        'scheduled_quantity',
        'vendor_ref',
        'status',
        'synced_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'assigned_quantity' => 'integer',
            'scheduled_quantity' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    // ── Relations ──

    public function licenseType(): BelongsTo
    {
        return $this->belongsTo(LicenseType::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function contracts(): BelongsToMany
    {
        return $this->belongsToMany(Contract::class, 'contract_license')
            ->using(ContractLicense::class)
            ->withPivot('assigned_at', 'assignment_source')
            ->withTimestamps();
    }

    // ── Utilization Accessors (vendor-agnostic) ──

    public function getUnassignedQuantityAttribute(): ?int
    {
        if ($this->assigned_quantity === null) {
            return null;
        }

        return max(0, $this->quantity - $this->assigned_quantity);
    }

    public function getUtilizationPercentAttribute(): ?float
    {
        if ($this->assigned_quantity === null || $this->quantity <= 0) {
            return null;
        }

        return min(100, round(($this->assigned_quantity / $this->quantity) * 100, 1));
    }

    /**
     * Utilization status: 'good' (≥90%), 'warning' (70-89%), 'waste' (<70%).
     */
    public function getUtilizationStatusAttribute(): ?string
    {
        $pct = $this->utilization_percent;
        if ($pct === null) {
            return null;
        }

        if ($pct >= 90) {
            return 'good';
        }
        if ($pct >= 70) {
            return 'warning';
        }

        return 'waste';
    }

    /**
     * Whether this license supports seat management (write-back to vendor API).
     */
    public function getSeatManageableAttribute(): bool
    {
        return $this->vendor_ref
            && $this->licenseType
            && $this->licenseType->vendor === 'appriver'
            && $this->client?->appriver_customer_id;
    }

    /**
     * Manual licenses (not synced from any integration) can be edited directly.
     */
    public function getIsManualAttribute(): bool
    {
        return $this->synced_at === null;
    }

    // ── Bulk Operations ──

    /**
     * Deactivate (suspend + zero) all licenses for the given clients and vendor(s).
     * Used when integration mappings are removed from clients.
     */
    public static function deactivateForClients($clientIds, string|array $vendors): int
    {
        $clientIds = collect($clientIds)->values()->all();
        if (empty($clientIds)) {
            return 0;
        }

        $vendorTypeIds = LicenseType::whereIn('vendor', (array) $vendors)->pluck('id');
        if ($vendorTypeIds->isEmpty()) {
            return 0;
        }

        return static::whereIn('license_type_id', $vendorTypeIds)
            ->whereIn('client_id', $clientIds)
            ->where(fn ($q) => $q->where('quantity', '>', 0)->orWhere('status', 'active'))
            ->update([
                'quantity' => 0,
                'status' => 'suspended',
                'synced_at' => now(),
            ]);
    }

    /**
     * Deactivate licenses where the client no longer has the vendor mapping.
     * Called at the end of each sync service to clean up orphans from removed mappings.
     */
    public static function deactivateOrphaned(string|array $vendors, string $mappingColumn): int
    {
        $vendorTypeIds = LicenseType::whereIn('vendor', (array) $vendors)->pluck('id');
        if ($vendorTypeIds->isEmpty()) {
            return 0;
        }

        return static::whereIn('license_type_id', $vendorTypeIds)
            ->whereHas('client', fn ($q) => $q->whereNull($mappingColumn))
            ->where(fn ($q) => $q->where('quantity', '>', 0)->orWhere('status', 'active'))
            ->update([
                'quantity' => 0,
                'status' => 'suspended',
                'synced_at' => now(),
            ]);
    }

    // ── Scopes ──

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }
}
