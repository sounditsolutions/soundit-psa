<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class License extends Model
{
    /**
     * Vendor-reported statuses that hold a licence out of billing.
     *
     * Mirrors {@see \App\Services\AppRiver\AppRiverLicenseSyncService::INCONCLUSIVE_SUBSCRIPTION_STATUSES}:
     * these are the only values the hold-out path ever writes to `vendor_status`, and a row
     * carries one only because the vendor AFFIRMATIVELY reported it. AppRiver publishes no
     * documentation on whether 'Suspended' is terminal or recoverable — that absence is
     * recorded here deliberately (the sync's constant says the same): the billing rule does
     * not move on the answer, because a vendor-suspended subscription is not billable in
     * either reading until the vendor reports it active again.
     */
    public const VENDOR_HELD_STATUSES = ['Suspended', 'Pending'];

    protected $fillable = [
        'license_type_id',
        'client_id',
        'quantity',
        'assigned_quantity',
        'scheduled_quantity',
        'vendor_ref',
        'status',
        'vendor_status',
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
     *
     * A queued seat change does not survive suspension — see the note on
     * scheduled_quantity in deactivateOrphaned().
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
                'scheduled_quantity' => null,
                'status' => 'suspended',
                'synced_at' => now(),
            ]);
    }

    /**
     * Deactivate licenses where the client no longer has the vendor mapping.
     * Called at the end of each sync service to clean up orphans from removed mappings.
     *
     * scheduled_quantity is cleared with the rest. It holds a seat reduction the
     * vendor refused inside its refundable window, to be retried on a later sync, and
     * removing the mapping is an operator saying this PSA no longer manages the
     * licence at all — there is no subscription left to retry the reduction against.
     *
     * The invariant is module-wide and these two methods are not the interesting case:
     * a licence that has been zeroed and suspended carries no pending seat change,
     * because a queued value standing above quantity 0 is an outbound seat INCREASE
     * waiting for a retry pass to pick it up. AppRiverLicenseSyncService's own stale
     * cleanup holds to it too, and logs each discarded instruction as it goes, since
     * its trigger is a vendor response rather than an operator. Only AppRiver writes
     * the column today; the invariant belongs wherever a licence is zeroed, not only
     * where the hazard has already been demonstrated.
     *
     * Read the direction carefully, because only one of the two survives. ZEROED AND
     * SUSPENDED IMPLIES NO QUEUED CHANGE still holds — every writer of that state
     * clears the column in the same update. The converse does NOT: a suspended
     * SUBSCRIPTION no longer means a zeroed, suspended licence ROW. AppRiver's sync
     * holds a licence out of cleanup while the vendor reports its subscription
     * Suspended or Pending, so the row stays active and at its seat count. Code that
     * wants "this subscription is interrupted" cannot read that off the row's status,
     * and this docblock is not evidence that it can.
     *
     * What does still hold everywhere is the rule underneath: a queued seat change
     * does not survive a change of state in the subscription it was written against.
     * The two bulk methods here enforce it by zeroing; AppRiver's status filter
     * enforces it by clearing the column where it observes the vendor saying so, on a
     * row it otherwise leaves alone. A new consumer needs to say which of the two it
     * is relying on.
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
                'scheduled_quantity' => null,
                'status' => 'suspended',
                'synced_at' => now(),
            ]);
    }

    // ── Vendor billing guard ──

    /**
     * Whether the vendor has affirmatively reported this subscription in a held
     * (inconclusive) state — the row is kept visible and out of stale cleanup, but
     * its quantity must not be billed and must stay out of report totals.
     */
    public function isVendorHeld(): bool
    {
        return in_array($this->vendor_status, self::VENDOR_HELD_STATUSES, true);
    }

    /**
     * Apply the vendor-billable predicate to a query: exclude rows whose
     * `vendor_status` is one of VENDOR_HELD_STATUSES.
     *
     * DELIBERATE FAIL-OPEN ON NULL: `vendor_status` is written only by syncs that
     * report one (AppRiver today). NULL is not the unknown-status case — it is the
     * no-such-vendor case. Every CIPP and Microsoft licence row has NULL here, and
     * excluding NULL would stop invoicing all of them on deploy day. An UNRECOGNISED
     * non-NULL value also bills normally, for the same reason the sync treats an
     * unrecognised SubscriptionStatus as unobserved rather than inactive: only a
     * status the vendor affirmatively reported, and this build affirmatively lists
     * as held, withholds billing.
     *
     * Works on both Eloquent and raw query builders — two of the read sites are
     * `DB::table('licenses')` and no Eloquent scope can reach them, which is why
     * this is a static helper applied EXPLICITLY at every read site rather than a
     * scope. A source-scan guard test fails if a new `licenses.status = 'active'`
     * read site appears without this predicate being considered
     * ({@see \Tests\Feature\Billing\LicenseActiveReadSiteGuardTest}).
     *
     * @template TQuery of \Illuminate\Contracts\Database\Query\Builder|\Illuminate\Contracts\Database\Eloquent\Builder
     *
     * @param  TQuery  $query
     * @param  string|null  $table  qualify the column when the query joins other tables
     * @return TQuery
     */
    public static function vendorBillable($query, ?string $table = null)
    {
        $column = ($table !== null ? $table.'.' : '').'vendor_status';

        return $query->where(function ($q) use ($column) {
            $q->whereNull($column)
                ->orWhereNotIn($column, self::VENDOR_HELD_STATUSES);
        });
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
