<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mark the lines of invoices voided BEFORE the complete-marker rule
 * (psa-oc5q2.1).
 *
 * InvoiceLine::reportable_amount reads pre_void_amount as the line-local void
 * marker — null means "live money". The old InvoiceVoidService and the
 * 2026_07_12 backfill both SKIPPED lines that were already $0 (that backfill's
 * `amount != 0 OR cost_amount != 0` filter), so invoices voided before this
 * change can carry unmarked lines. The QBO status pull writes line money
 * OUTSIDE the void lock (psa-oc5q2), so such a line can be re-inflated on a
 * Void invoice — and there was no path back: re-voiding an already-zeroed
 * invoice returned early, and every operator void door refuses an already-Void
 * invoice. Without this backfill the marker is incomplete on exactly the
 * population the read-side seam was built to protect.
 *
 * The snapshot recorded is 0.00, NOT the current amount: an unmarked line under
 * the old rule was $0 at void time by construction, so 0.00 IS the original
 * bill — writing back a re-inflated value would mint a charge the client was
 * never sent. A null cost_amount stays null (no cost was tracked). The live
 * money fields are zeroed to match the invoice header.
 *
 * Only unmarked lines are touched, so a re-run is a no-op and an existing
 * snapshot is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoice_lines', 'pre_void_amount')) {
            return;
        }

        // Includes soft-deleted invoices (imports query withTrashed), matching
        // the 2026_07_12 backfill's scope.
        DB::table('invoice_lines')
            ->whereIn('invoice_id', fn ($q) => $q->select('id')->from('invoices')->where('status', 'void'))
            ->whereNull('pre_void_amount')
            ->update([
                // Assignment order matters: MariaDB evaluates UPDATE SET
                // clauses left to right, so the snapshots come before the
                // zeroing.
                'pre_void_amount' => 0,
                'pre_void_cost_amount' => DB::raw('CASE WHEN cost_amount IS NULL THEN NULL ELSE 0 END'),
                'amount' => 0,
                'cost_amount' => DB::raw('CASE WHEN cost_amount IS NULL THEN NULL ELSE 0 END'),
            ]);
    }

    public function down(): void
    {
        // Deliberately irreversible: the rows marked here were $0 at void time,
        // so there is no original bill to restore. Un-marking them would only
        // re-open the hole this closes.
    }
};
