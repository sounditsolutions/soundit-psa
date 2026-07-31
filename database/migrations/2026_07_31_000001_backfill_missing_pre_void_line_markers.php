<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * psa-oc5q2.1 — install the MISSING line-level void marker on invoices that
 * were voided before InvoiceVoidService snapshotted every line.
 *
 * InvoiceLine::reportable_amount treats pre_void_amount as a COMPLETE
 * line-local void marker: null means "live money". That invariant is only true
 * going forward. The original snapshot backfill
 * (2026_07_12_000001_add_pre_void_snapshot_to_invoices) skipped lines that were
 * $0 at void time — the same skip the service itself used to have — and that
 * migration is already applied, so its up() will not re-run. Every such
 * historical line still reads as live money the moment the out-of-lock QBO
 * status pull re-inflates its raw amount, and re-voiding could not repair it:
 * void() early-returned on an already-Void all-zero invoice, the bulk action
 * skips an already-Void invoice, and the status pull returns early for Void.
 *
 * So mark EVERY unmarked line on a Void invoice, $0 or not, and zero the live
 * money while we are here (a line carrying money on a Void invoice is either
 * the pre-snapshot legacy shape or an already re-inflated row — both must read
 * $0 for reporting). For a row that was ALREADY re-inflated before this ran we
 * cannot recover the original bill, so the snapshot records what is on the row
 * now; the reportable reading is correct either way, and display_* shows the
 * same figure it already shows for such a row today.
 *
 * Soft-deleted invoices are included (imports query withTrashed), and the
 * whereNull guard makes up() re-runnable: it can never overwrite a snapshot
 * written by InvoiceVoidService.
 *
 * MariaDB (prod) evaluates UPDATE SET clauses left to right, so the pre_void_*
 * assignments must precede the zeroing — the same ordering constraint the
 * original backfill documents. The test suite runs on sqlite, which evaluates
 * every SET clause against the ORIGINAL row, so that ordering hazard and the
 * decimal-column semantics of these money columns are NOT exercised by tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('invoice_lines')
            ->whereIn('invoice_id', fn ($q) => $q->select('id')->from('invoices')->where('status', 'void'))
            ->whereNull('pre_void_amount')
            ->update([
                'pre_void_amount' => DB::raw('amount'),
                'pre_void_cost_amount' => DB::raw('cost_amount'),
                'amount' => 0,
                'cost_amount' => DB::raw('CASE WHEN cost_amount IS NULL THEN NULL ELSE 0 END'),
            ]);
    }

    public function down(): void
    {
        // Deliberately irreversible. A marker written here is indistinguishable
        // from one written by InvoiceVoidService, so removing it would reopen
        // the exact hole this closes. Rolling back the columns themselves still
        // restores the amounts — that lives in the migration that owns them.
    }
};
