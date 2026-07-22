<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit + idempotency ledger row for the psa-946hr description repair
 * command. See invoices:repair-double-escaped-descriptions; the unique
 * invoice_line_id is what makes the repair at-most-once per line.
 */
class InvoiceLineDescriptionRepair extends Model
{
    protected $fillable = [
        'invoice_line_id',
        'invoice_id',
        'description_before',
        'description_after',
        'invoice_status_at_repair',
        'reverted_at',
    ];

    protected function casts(): array
    {
        return [
            'reverted_at' => 'datetime',
        ];
    }
}
