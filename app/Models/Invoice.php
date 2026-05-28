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

    // ── Accessors ──

    public function getFormattedTotalAttribute(): string
    {
        return '$' . number_format((float) $this->total, 2);
    }

    public function getDisplayNumberAttribute(): string
    {
        return $this->invoice_number;
    }

    public function getIsEditableAttribute(): bool
    {
        if (!in_array($this->status, [InvoiceStatus::Draft, InvoiceStatus::Synced, InvoiceStatus::Posted])) {
            return false;
        }

        // Stripe-synced invoices are not editable (no update path yet)
        if ($this->stripe_invoice_id) {
            return false;
        }

        return true;
    }
}
