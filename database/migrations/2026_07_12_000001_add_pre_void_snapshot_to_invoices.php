<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sum-safe void handling: voided invoices keep their reportable money fields
 * at $0 so aggregates (ProfitabilityService, dashboards, ad-hoc SUMs) exclude
 * them structurally, while the original amounts are preserved in pre_void_*
 * snapshot columns for display and audit. QBO exposes no pre-void amount (a
 * void zeroes TotalAmt and every line), so the snapshot is a PSA-only concept.
 *
 * The backfill converts invoices voided before this change (snapshot, then
 * zero). Column adds and backfill are individually guarded so up() is
 * re-runnable — the backfill test relies on this.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoices', 'pre_void_total')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->decimal('pre_void_subtotal', 10, 2)->nullable()->after('margin');
                $table->decimal('pre_void_tax', 10, 2)->nullable()->after('pre_void_subtotal');
                $table->decimal('pre_void_total', 10, 2)->nullable()->after('pre_void_tax');
                $table->decimal('pre_void_total_cost', 10, 2)->nullable()->after('pre_void_total');
                $table->decimal('pre_void_margin', 10, 2)->nullable()->after('pre_void_total_cost');
            });
        }

        if (! Schema::hasColumn('invoice_lines', 'pre_void_amount')) {
            Schema::table('invoice_lines', function (Blueprint $table) {
                $table->decimal('pre_void_amount', 10, 2)->nullable()->after('cost_amount');
                $table->decimal('pre_void_cost_amount', 10, 2)->nullable()->after('pre_void_amount');
            });
        }

        $this->backfillVoidedInvoices();
    }

    public function down(): void
    {
        // Restore the snapshotted amounts before dropping the columns so a
        // rollback does not leave voided invoices stuck at $0 with no record.
        DB::table('invoices')->whereNotNull('pre_void_total')->update([
            'subtotal' => DB::raw('pre_void_subtotal'),
            'tax' => DB::raw('pre_void_tax'),
            'total' => DB::raw('pre_void_total'),
            'total_cost' => DB::raw('pre_void_total_cost'),
            'margin' => DB::raw('pre_void_margin'),
        ]);

        DB::table('invoice_lines')->whereNotNull('pre_void_amount')->update([
            'amount' => DB::raw('pre_void_amount'),
            'cost_amount' => DB::raw('pre_void_cost_amount'),
        ]);

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'pre_void_subtotal',
                'pre_void_tax',
                'pre_void_total',
                'pre_void_total_cost',
                'pre_void_margin',
            ]);
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn(['pre_void_amount', 'pre_void_cost_amount']);
        });
    }

    /**
     * Snapshot-then-zero invoices already voided before this migration.
     * Includes soft-deleted rows (imports query withTrashed) and skips rows
     * already at $0 so a re-run never overwrites a snapshot with zeros.
     *
     * Assignment order matters: MariaDB evaluates UPDATE SET clauses left to
     * right, so the pre_void_* snapshots must be assigned before the zeroing.
     */
    private function backfillVoidedInvoices(): void
    {
        DB::table('invoice_lines')
            ->whereIn('invoice_id', fn ($q) => $q->select('id')->from('invoices')->where('status', 'void'))
            ->whereNull('pre_void_amount')
            ->where(fn ($q) => $q->where('amount', '!=', 0)->orWhere('cost_amount', '!=', 0))
            ->update([
                'pre_void_amount' => DB::raw('amount'),
                'pre_void_cost_amount' => DB::raw('cost_amount'),
                'amount' => 0,
                'cost_amount' => DB::raw('CASE WHEN cost_amount IS NULL THEN NULL ELSE 0 END'),
            ]);

        DB::table('invoices')
            ->where('status', 'void')
            ->whereNull('pre_void_total')
            ->where(function ($q) {
                $q->where('subtotal', '!=', 0)
                    ->orWhere('tax', '!=', 0)
                    ->orWhere('total', '!=', 0)
                    ->orWhere('total_cost', '!=', 0)
                    ->orWhere('margin', '!=', 0);
            })
            ->update([
                'pre_void_subtotal' => DB::raw('subtotal'),
                'pre_void_tax' => DB::raw('tax'),
                'pre_void_total' => DB::raw('total'),
                'pre_void_total_cost' => DB::raw('total_cost'),
                'pre_void_margin' => DB::raw('margin'),
                'subtotal' => 0,
                'tax' => 0,
                'total' => 0,
                'total_cost' => DB::raw('CASE WHEN total_cost IS NULL THEN NULL ELSE 0 END'),
                'margin' => DB::raw('CASE WHEN margin IS NULL THEN NULL ELSE 0 END'),
            ]);
    }
};
