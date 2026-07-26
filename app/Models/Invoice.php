<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

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
        'pre_void_subtotal',
        'pre_void_tax',
        'pre_void_total',
        'pre_void_total_cost',
        'pre_void_margin',
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
            'pre_void_subtotal' => 'decimal:2',
            'pre_void_tax' => 'decimal:2',
            'pre_void_total' => 'decimal:2',
            'pre_void_total_cost' => 'decimal:2',
            'pre_void_margin' => 'decimal:2',
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

    /**
     * True when this invoice was voided and carries a pre-void snapshot of
     * its original amounts. On void the live money fields are zeroed so
     * aggregates exclude voided invoices structurally; the originals move to
     * pre_void_* columns (see InvoiceVoidService). The detail view uses this
     * to show the originals alongside an explicit "voided" banner.
     */
    public function isVoidWithSnapshot(): bool
    {
        return $this->status === InvoiceStatus::Void && $this->pre_void_total !== null;
    }

    /** Original pre-void value for voided invoices, live value otherwise. */
    public function getDisplaySubtotalAttribute(): ?string
    {
        return $this->isVoidWithSnapshot() ? $this->pre_void_subtotal : $this->subtotal;
    }

    public function getDisplayTaxAttribute(): ?string
    {
        return $this->isVoidWithSnapshot() ? $this->pre_void_tax : $this->tax;
    }

    public function getDisplayTotalAttribute(): ?string
    {
        return $this->isVoidWithSnapshot() ? $this->pre_void_total : $this->total;
    }

    public function getDisplayTotalCostAttribute(): ?string
    {
        return $this->isVoidWithSnapshot() ? $this->pre_void_total_cost : $this->total_cost;
    }

    public function getDisplayMarginAttribute(): ?string
    {
        return $this->isVoidWithSnapshot() ? $this->pre_void_margin : $this->margin;
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
     * True when this invoice may be marked Paid by hand.
     *
     * Manual payment recording exists for standalone (no-backend) invoices:
     * a Posted invoice whose payment can never arrive via Stripe/QBO sync
     * because it carries no billing-backend link. For a backend-synced
     * invoice the backend is the system of record for payment — marking it
     * Paid in PSA would desync the two — so those are excluded and the
     * operator uses "Refresh from Stripe/QBO" instead. Draft (not yet issued),
     * Void (terminal), and Paid (already) are likewise never eligible.
     *
     * The single source of truth consulted by the detail view, the single
     * mark-paid action, and the bulk action so they cannot disagree.
     */
    public function canMarkPaid(): bool
    {
        return $this->status === InvoiceStatus::Posted
            && $this->qbo_invoice_id === null
            && $this->stripe_invoice_id === null;
    }

    /**
     * Atomically record a billing-backend push result at the invoice row.
     *
     * The single guarded write-point every push writer funnels through — QBO
     * create AND update, Stripe create — so no push can clobber a terminal
     * state a concurrent writer committed while the backend API call was in
     * flight. The row is re-read under lock, so this re-check serialises with
     * InvoiceService::markPaid()'s own locked check: psa-946hr's rule that
     * EVERY financial-status writer sharing the invariant re-checks at the
     * write, not only the newest one.
     *
     * Two terminal states are protected, each a different way (psa-8yhp):
     *
     * - Paid (manual Mark-as-Paid won the race): status is preserved, not
     *   overwritten back to Synced. The backend id in $attributes is still
     *   recorded, so the external invoice is not orphaned — a Paid invoice
     *   legitimately carries a backend id (exactly how backend-synced-then-paid
     *   invoices look), and its live tax/total are legitimate too.
     *
     * - Void (a void committed mid-push): status is preserved AND every
     *   reportable money field (subtotal/tax/total/total_cost/margin) is dropped
     *   from the write. InvoiceVoidService has already zeroed those and
     *   snapshotted the originals into pre_void_*, so aggregates exclude the
     *   invoice structurally; writing the backend's live tax/total back would
     *   re-inflate a voided invoice and silently re-enter it into Outstanding /
     *   profitability totals while it still reads as Void (psa-la350 R2). The
     *   backend id/url/timestamp are still recorded, so the external invoice is
     *   flagged for reconciliation, not orphaned — PSA Void state and backend
     *   state cannot silently diverge.
     *
     * @param  array<string, mixed>  $attributes  Backend ids/tax/timestamps to persist. The `status` key is managed here, not by the caller.
     * @param  bool  $transitionToSynced  CREATE (true) transitions a live invoice to Synced; UPDATE/re-push (false) keeps the current status. Terminal Paid/Void are preserved regardless.
     */
    public function recordPushResult(array $attributes, bool $transitionToSynced = true): void
    {
        DB::transaction(function () use ($attributes, $transitionToSynced) {
            $locked = static::whereKey($this->getKey())->lockForUpdate()->first();

            if ($locked->status === InvoiceStatus::Void) {
                // Never re-inflate a zeroed, voided invoice — drop every
                // reportable money field. Also (psa-bl36l R4/B): never store a
                // live hosted payment URL on a void row (a push that finalized
                // just as the void committed must not leave a payable link the
                // client can reach), and never clear a divergence sync error a
                // void propagation recorded. The backend id/timestamp are still
                // recorded below so the external invoice is flagged, not orphaned.
                unset(
                    $attributes['subtotal'],
                    $attributes['tax'],
                    $attributes['total'],
                    $attributes['total_cost'],
                    $attributes['margin'],
                    $attributes['stripe_sync_error'],
                    $attributes['qbo_sync_error'],
                );
                if (array_key_exists('stripe_invoice_url', $attributes)) {
                    $attributes['stripe_invoice_url'] = null;
                }
            } elseif ($transitionToSynced && $locked->status !== InvoiceStatus::Paid) {
                $attributes['status'] = InvoiceStatus::Synced;
            }

            $locked->update($attributes);

            // Keep the caller's in-memory model consistent with the committed row.
            $this->setRawAttributes($locked->getAttributes(), true);
            $this->syncOriginal();
        });
    }

    /**
     * Apply a billing-backend STATUS-PULL result under a row lock, refusing to
     * re-inflate a locally voided invoice (psa-qfhc5).
     *
     * The pull writers (QboSyncService::syncInvoiceStatusFromQbo,
     * StripeSyncService::syncInvoiceStatusFromStripe) check status BEFORE the
     * vendor GET, then write the read-back tax/total — and possibly status=Paid
     * (QBO Balance==0 / Stripe "paid") — AFTER the network round-trip. A local
     * InvoiceVoidService::void() committing mid-round-trip would otherwise be
     * re-inflated: its zeroed money re-entered into sum-safe aggregates, or its
     * Void flipped to Paid. This mirrors the push-path guard (recordPushResult):
     * re-read the row under lock and, when the committed row is Void, drop every
     * reportable money field AND any status write — the void service has already
     * zeroed the money, snapshotted the originals into pre_void_*, and "PSA wins
     * for void". The *_synced_at stamp is kept (the vendor WAS contacted), but a
     * *_sync_error CLEAR is dropped on a Void row (psa-bl36l MF8): a refused pull
     * converged nothing, so it must not erase a divergence error a concurrent
     * void propagation recorded.
     *
     * @param  array<string, mixed>  $attributes  Read-back money/status/provenance to persist.
     * @return bool true if the locked row was Void — money and status were dropped,
     *              and the caller MUST skip any dependent line-amount write.
     */
    public function recordStatusPullResult(array $attributes): bool
    {
        return DB::transaction(function () use ($attributes) {
            $locked = static::whereKey($this->getKey())->lockForUpdate()->first();

            $isVoid = $locked->status === InvoiceStatus::Void;

            if ($isVoid) {
                unset(
                    $attributes['subtotal'],
                    $attributes['tax'],
                    $attributes['total'],
                    $attributes['total_cost'],
                    $attributes['margin'],
                    $attributes['status'],
                    // psa-bl36l MF8: a refused stale pull converged NOTHING, so it
                    // must not clear a sync error. The pull writers set
                    // *_sync_error => null on the happy path; keeping that on a
                    // Void row would erase a divergence signal a concurrent void
                    // propagation (voidInvoiceInStripe / voidInvoiceInQbo) just
                    // recorded — the exact audit TOCTOU that hides a paid-but-voided
                    // invoice. Preserve whatever error is already on the row.
                    $attributes['stripe_sync_error'],
                    $attributes['qbo_sync_error'],
                );
            }

            $locked->update($attributes);

            // Keep the caller's in-memory model consistent with the committed row.
            $this->setRawAttributes($locked->getAttributes(), true);
            $this->syncOriginal();

            return $isVoid;
        });
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
