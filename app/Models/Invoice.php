<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'halo_id',
        'client_id',
        'contract_id',
        'profile_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'subtotal',
        'tax',
        'total',
        'total_cost',
        'margin',
        'status',
        'qbo_invoice_id',
        'qbo_doc_number',
        'qbo_synced_at',
        'qbo_sync_error',
        'stripe_invoice_id',
        'stripe_invoice_url',
        'stripe_synced_at',
        'stripe_sync_error',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'invoice_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'margin' => 'decimal:2',
            'qbo_synced_at' => 'datetime',
            'stripe_synced_at' => 'datetime',
        ];
    }

    // ── Relations ──

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoiceProfile::class, 'profile_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort_order');
    }

    // ── Scopes ──

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::Draft);
    }

    public function scopeSynced(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::Synced);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::Paid);
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->whereNotIn('status', [InvoiceStatus::Paid, InvoiceStatus::Void]);
    }

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Invoices awaiting payment (posted or synced to the billing backend).
     * Mirrors the "Outstanding" dashboard stat and list filter.
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [InvoiceStatus::Posted, InvoiceStatus::Synced]);
    }

    /**
     * Posted invoices whose due date has passed. Kept in lockstep with the
     * isOverdue() accessor so the list filter matches the "Overdue" badge.
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::Posted)
            ->whereNotNull('due_date')
            ->where('due_date', '<', now());
    }

    /**
     * Apply an invoice-list status filter value to the query. Routes the
     * derived filters ("outstanding", "overdue") to their scopes and falls
     * back to an exact status match for concrete statuses. Mirrors the values
     * exposed by InvoiceStatus::filterOptions().
     */
    public function scopeStatusFilter(Builder $query, string $status): Builder
    {
        return match ($status) {
            'outstanding' => $query->outstanding(),
            'overdue' => $query->overdue(),
            default => $query->where('status', $status),
        };
    }

    // ── Accessors ──

    public function getFormattedTotalAttribute(): string
    {
        return '$'.number_format((float) $this->total, 2);
    }

    public function getDisplayNumberAttribute(): string
    {
        return $this->invoice_number;
    }

    public function getIsEditableAttribute(): bool
    {
        if (! in_array($this->status, [InvoiceStatus::Draft, InvoiceStatus::Synced, InvoiceStatus::Posted])) {
            return false;
        }

        // Stripe-synced invoices are not editable (no update path yet)
        if ($this->stripe_invoice_id) {
            return false;
        }

        return true;
    }

    public function isOverdue(): bool
    {
        return $this->status === InvoiceStatus::Posted
            && $this->due_date !== null
            && $this->due_date->isPast();
    }

    /**
     * Status label for display, accounting for the computed "Overdue" state.
     *
     * Overdue is not a stored status — an unpaid Posted invoice past its due
     * date reads as "Overdue" wherever it is surfaced. Use this (and
     * displayStatusBadgeClass()) instead of $invoice->status->label() so the
     * detail, list, and contract views cannot disagree about billing state.
     */
    public function displayStatusLabel(): string
    {
        return $this->isOverdue() ? 'Overdue' : $this->status->label();
    }

    /** Badge class matching displayStatusLabel(). */
    public function displayStatusBadgeClass(): string
    {
        return $this->isOverdue() ? 'bg-danger' : $this->status->badgeClass();
    }
}
