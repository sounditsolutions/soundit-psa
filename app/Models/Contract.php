<?php

namespace App\Models;

use App\Enums\BillingPeriod;
use App\Enums\BillingSource;
use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\TicketPriority;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'halo_id',
        'client_id',
        'name',
        'type',
        'status',
        'billing_source',
        'billing_period',
        'billing_day',
        'payment_terms_days',
        'start_date',
        'end_date',
        'term_length_months',
        'auto_renew',
        'cancelled_at',
        'cancellation_reason',
        'notes',
        'prepay_total',
        'prepay_used',
        'prepay_expired',
        'prepay_balance',
        'prepay_as_amount',
        'prepay_expiry_months',
        'portal_prepay_sku_id',
        'prepay_alert_threshold',
        'prepay_auto_topup_qty',
        'prepay_auto_topup_enabled',
        'prepay_alert_notified_at',
        'sla_terms',
    ];

    protected function casts(): array
    {
        return [
            'type' => ContractType::class,
            'status' => ContractStatus::class,
            'billing_source' => BillingSource::class,
            'billing_period' => BillingPeriod::class,
            'billing_day' => 'integer',
            'payment_terms_days' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'term_length_months' => 'integer',
            'auto_renew' => 'boolean',
            'cancelled_at' => 'datetime',
            'prepay_total' => 'decimal:2',
            'prepay_used' => 'decimal:2',
            'prepay_expired' => 'decimal:2',
            'prepay_balance' => 'decimal:2',
            'prepay_as_amount' => 'boolean',
            'prepay_expiry_months' => 'integer',
            'prepay_alert_threshold' => 'decimal:2',
            'prepay_auto_topup_qty' => 'integer',
            'prepay_auto_topup_enabled' => 'boolean',
            'prepay_alert_notified_at' => 'datetime',
            'sla_terms' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Contract $contract) {
            if ($contract->isForceDeleting()) {
                // Clean up documents (triggers per-model deleting event for disk cleanup)
                $contract->documents()->each(fn (ContractDocument $doc) => $doc->forceDelete());

                return;
            }
            // Deactivate all profiles when soft-deleting a contract
            $contract->profiles()->update(['is_active' => false]);
        });
    }

    public function hasSla(): bool
    {
        return ! empty($this->sla_terms);
    }

    public function slaResponseHours(TicketPriority $priority): ?int
    {
        return $this->sla_terms['response'][$priority->value] ?? null;
    }

    public function slaResolutionHours(TicketPriority $priority): ?int
    {
        return $this->sla_terms['resolution'][$priority->value] ?? null;
    }

    // ── Relations ──

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(RecurringInvoiceProfile::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function prepayTransactions(): HasMany
    {
        return $this->hasMany(PrepayTransaction::class);
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'contract_asset')
            ->using(ContractAsset::class)
            ->withPivot('assigned_at', 'assignment_source', 'rule_id')
            ->withTimestamps();
    }

    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'contract_person')
            ->using(ContractPerson::class)
            ->withPivot('assigned_at', 'assignment_source', 'rule_id')
            ->withTimestamps();
    }

    public function licenses(): BelongsToMany
    {
        return $this->belongsToMany(License::class, 'contract_license')
            ->using(ContractLicense::class)
            ->withPivot('assigned_at', 'assignment_source')
            ->withTimestamps();
    }

    public function assignmentRules(): HasMany
    {
        return $this->hasMany(ContractAssignmentRule::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ContractActivity::class)->orderByDesc('created_at');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ContractDocument::class);
    }

    public function portalPrepaySku(): BelongsTo
    {
        return $this->belongsTo(Sku::class, 'portal_prepay_sku_id');
    }

    // ── Scopes ──

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ContractStatus::Active);
    }

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    public function scopePsaManaged(Builder $query): Builder
    {
        return $query->where('billing_source', BillingSource::Psa);
    }

    // ── Accessors ──

    public function getIsExpiredAttribute(): bool
    {
        return $this->end_date && $this->end_date->isPast();
    }

    public function getHasPrepayAttribute(): bool
    {
        return $this->prepay_balance !== null;
    }

    public function getIsPortalPurchasableAttribute(): bool
    {
        return $this->status === ContractStatus::Active
            && $this->portal_prepay_sku_id !== null
            && ! $this->prepay_as_amount;
    }

    public function getPrepayBalanceFormattedAttribute(): string
    {
        if ($this->prepay_balance === null) {
            return '-';
        }

        return $this->prepay_as_amount
            ? '$'.number_format($this->prepay_balance, 2)
            : number_format($this->prepay_balance, 2).' hrs';
    }

    /**
     * Total prepaid hours forfeited to expiration (hours-based prepay only).
     */
    public function getPrepayExpiredFormattedAttribute(): string
    {
        if (! $this->prepay_expired || $this->prepay_as_amount) {
            return '-';
        }

        return number_format($this->prepay_expired, 2).' hrs';
    }

    /**
     * Average consumption per week over the last 30 days.
     * Memoized to avoid N+1 on list views (dashboard).
     */
    private mixed $cachedBurnRate = false;

    public function getBurnRateAttribute(): ?float
    {
        if ($this->cachedBurnRate !== false) {
            return $this->cachedBurnRate;
        }

        if (! $this->has_prepay) {
            return $this->cachedBurnRate = null;
        }

        $since = now()->subDays(30);
        $consumed = $this->prepayTransactions()
            ->consumption()
            ->where('date', '>=', $since)
            ->sum($this->prepay_as_amount ? 'amount' : 'hours');

        $weeklyRate = $consumed > 0 ? round(($consumed / 30) * 7, 2) : 0;

        return $this->cachedBurnRate = $weeklyRate;
    }

    public function getDaysUntilDepletedAttribute(): ?int
    {
        if (! $this->has_prepay || ! $this->burn_rate || $this->burn_rate <= 0) {
            return null;
        }

        $dailyRate = $this->burn_rate / 7;

        return $dailyRate > 0 ? (int) ceil($this->prepay_balance / $dailyRate) : null;
    }

    public function getBurnRateFormattedAttribute(): string
    {
        if ($this->burn_rate === null || $this->burn_rate <= 0) {
            return '-';
        }

        return $this->prepay_as_amount
            ? '$'.number_format($this->burn_rate, 2).'/wk'
            : number_format($this->burn_rate, 2).' hrs/wk';
    }
}
