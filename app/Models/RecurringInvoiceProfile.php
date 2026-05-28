<?php

namespace App\Models;

use App\Enums\AutoPushMode;
use App\Enums\BillingPeriod;
use App\Enums\BillingSource;
use App\Enums\ContractStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringInvoiceProfile extends Model
{
    protected $fillable = [
        'halo_id',
        'contract_id',
        'name',
        'notes',
        'is_active',
        'billing_period',
        'billing_day',
        'payment_terms_days',
        'next_run_date',
        'last_run_date',
        'skip_zero_invoices',
        'auto_push_mode',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'billing_period' => BillingPeriod::class,
            'billing_day' => 'integer',
            'payment_terms_days' => 'integer',
            'next_run_date' => 'date',
            'last_run_date' => 'date',
            'auto_push_mode' => AutoPushMode::class,
        ];
    }

    // ── Relations ──

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RecurringInvoiceProfileLine::class, 'profile_id')->orderBy('sort_order');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'profile_id');
    }

    // ── Helpers ──

    public function shouldSkipZeroInvoices(): bool
    {
        if ($this->skip_zero_invoices !== null) {
            return (bool) $this->skip_zero_invoices;
        }

        return (bool) Setting::getValue('billing_skip_zero_invoices', false);
    }

    // ── Scopes ──

    public function scopeDue(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('next_run_date', '<=', today())
            ->whereHas('contract', function (Builder $q) {
                $q->where('status', ContractStatus::Active)
                  ->where('billing_source', BillingSource::Psa);
            });
    }
}
