<?php

namespace App\Models;

use App\Enums\PrepayTransactionSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrepayTransaction extends Model
{
    protected $fillable = [
        'contract_id',
        'halo_id',
        'source',
        'invoice_id',
        'ticket_note_id',
        'phone_call_id',
        'user_id',
        'date',
        'hours',
        'amount',
        'description',
        'note',
        'invoice_number',
        'invoice_date',
        'expiry_date',
        'expired_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'source' => PrepayTransactionSource::class,
            'date' => 'datetime',
            'hours' => 'decimal:4',
            'amount' => 'decimal:2',
            'invoice_date' => 'datetime',
            'expiry_date' => 'datetime',
        ];
    }

    // ── Relations ──

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ticketNote(): BelongsTo
    {
        return $this->belongsTo(TicketNote::class);
    }

    public function phoneCall(): BelongsTo
    {
        return $this->belongsTo(PhoneCall::class);
    }

    /**
     * The credit "lot" this Expiration debit forfeits (self-reference).
     * Null for non-expiration rows.
     */
    public function expiredTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'expired_transaction_id');
    }

    // ── Scopes ──

    /**
     * Consumption transactions only — excludes deposits, credits, expirations,
     * and inter-contract transfers. Used for burn rate calculation and usage
     * reporting: forfeited (expired) hours are not work consumed, and a transfer
     * is a one-time balance movement between contracts (not ongoing work), so
     * neither must skew the burn rate or days-until-depleted estimate. Note this
     * scope is distinct from the raw-sign replay in PrepayExpirationService,
     * which intentionally treats a transfer-out as a lot-drawing debit.
     */
    public function scopeConsumption(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNotIn('source', [
                PrepayTransactionSource::InvoiceDeposit->value,
                PrepayTransactionSource::InvoiceReversal->value,
                PrepayTransactionSource::ManualCredit->value,
                PrepayTransactionSource::Expiration->value,
                PrepayTransactionSource::TransferOut->value,
                PrepayTransactionSource::TransferIn->value,
            ])->orWhereNull('source');
        });
    }

    /**
     * Format the value for display. Caller passes the flag explicitly
     * to avoid N+1 loading the parent contract on every row.
     */
    public function formatValue(bool $asAmount): string
    {
        if ($asAmount) {
            return $this->amount !== null ? '$'.number_format($this->amount, 2) : '-';
        }

        return $this->hours !== null ? number_format($this->hours, 2).' hrs' : '-';
    }
}
