<?php

namespace App\Models;

use App\Enums\InvoiceStatusChangeSource;
use App\Support\InvoiceStatusChangeContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One invoices.status move: what it was, what it became, who moved it, why,
 * and — on a QuickBooks pull — what QBO said was still owed at that moment
 * (#1173).
 *
 * Written solely by InvoiceObserver on a status change, so every writer — the
 * QBO pull, the Stripe pull, staff Mark Paid, InvoiceVoidService, queued jobs —
 * is captured without opting in (#992 audit-seam ruling, 2026-09-01).
 *
 * SCOPED TO UPDATES, not creates. An invoice's opening status is not a change:
 * the row itself carries it alongside created_at, and nothing has been
 * destroyed. This log exists because a status write overwrites the prior value
 * with no trace — the exact hole T-22802 fell into.
 *
 * AUDIT-ONLY — never an ownership or precedence source. The INSERT runs in
 * InvoiceObserver::updated(), AFTER the UPDATE it describes; for callers
 * outside a transaction the two statements commit separately, so a concurrent
 * reader can see the new status while this row is still pending. Read the
 * invoice for current state, this table only for history. (The QBO pull DOES
 * write inside Invoice::recordStatusPullResult()'s transaction, so on that
 * path the log and the status commit together or not at all.)
 */
class InvoiceStatusChangeLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'invoice_id',
        'previous_status',
        'new_status',
        'source',
        'reason',
        'qbo_balance',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'source' => InvoiceStatusChangeSource::class,
            'qbo_balance' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    // ── relations ────────────────────────────────────────────────────────────

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    // ── recording ────────────────────────────────────────────────────────────

    /**
     * Who a status write happening RIGHT NOW should be attributed to, from
     * execution context alone — never from caller-supplied attributes, so it
     * cannot be forged. An unauthenticated writer is System, and that is a
     * recorded fact rather than a skipped row (#992's ruling: "that context
     * touching descriptions is precisely a thing the record should show, not
     * skip"). Same reasoning, same answer, for statuses.
     */
    public static function attributionSource(): InvoiceStatusChangeSource
    {
        return auth()->check()
            ? InvoiceStatusChangeSource::Staff
            : InvoiceStatusChangeSource::System;
    }

    /**
     * Record a just-saved status change. Called by InvoiceObserver when
     * wasChanged('status') — the single seam every writer passes through.
     *
     * $context is what the writer declared about its own intent, when it
     * declared anything; absent it, the row still gets written with the
     * execution-context attribution above.
     */
    public static function recordFor(Invoice $invoice, ?InvoiceStatusChangeContext $context = null): self
    {
        $source = $context->source ?? self::attributionSource();

        // changed_by is only meaningful for a real authenticated actor. A
        // declared non-Staff source (the QBO pull) can still be running inside
        // a staff request — a technician hitting the per-invoice Sync button —
        // and stamping that user as the one who moved the status would credit
        // QuickBooks's decision to a person who only asked for a refresh.
        $isStaffActor = $source === InvoiceStatusChangeSource::Staff && auth()->check();

        return self::create([
            'invoice_id' => $invoice->id,
            // getRawOriginal, not getOriginal: the latter applies the cast and
            // hands back an InvoiceStatus instance, which this plain string
            // column cannot store. The raw value is what the row held.
            'previous_status' => $invoice->getRawOriginal('status'),
            'new_status' => $invoice->status?->value,
            'source' => $source,
            'reason' => $context?->reason,
            'qbo_balance' => $context?->qboBalance,
            'changed_by' => $isStaffActor ? auth()->id() : null,
        ]);
    }
}
