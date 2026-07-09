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

    /**
     * True when this active profile's next run date has already passed, so an
     * invoice is overdue to be generated. Mirrors the guard on
     * RecurringProfileController@generate and the profile-detail "Generate Now"
     * affordance, so the contract detail page can flag the same profiles the
     * profile detail page does.
     */
    public function isBehind(): bool
    {
        return $this->is_active
            && $this->next_run_date !== null
            && $this->next_run_date->isPast();
    }

    /**
     * Number of billing cycles this profile is behind (0 when not behind) —
     * how many billing periods have elapsed since the past next run date,
     * counting the one currently due. Steps by the profile's billing period.
     */
    public function cyclesBehind(): int
    {
        if (! $this->isBehind()) {
            return 0;
        }

        $months = max(1, $this->billing_period->months());
        $cycles = 0;
        $cursor = $this->next_run_date->copy();
        while ($cursor->isPast()) {
            $cursor->addMonths($months);
            $cycles++;
        }

        return $cycles;
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
