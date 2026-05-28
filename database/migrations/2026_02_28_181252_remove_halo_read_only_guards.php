<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Remove Halo read-only guards by flipping billing_source and fixing invoice statuses.
     *
     * This migration:
     * 1. Auto-fixes any Halo invoices stuck in draft/posted status (they were billed through Halo)
     * 2. Flips all contracts from billing_source=halo_legacy to psa
     * 3. Logs the change as contract activity for audit trail
     */
    public function up(): void
    {
        // Fix any Halo invoices in pushable status before code removes the whereNull('halo_id') guards
        $fixed = DB::table('invoices')
            ->whereNotNull('halo_id')
            ->whereIn('status', ['draft', 'posted'])
            ->whereNull('deleted_at')
            ->update(['status' => 'paid']);

        if ($fixed > 0) {
            logger()->warning("[Migration] Fixed {$fixed} Halo invoices from draft/posted to paid");
        }

        // Flip billing_source for all halo_legacy contracts
        $flipped = DB::table('contracts')
            ->where('billing_source', 'halo_legacy')
            ->update(['billing_source' => 'psa']);

        if ($flipped > 0) {
            logger()->info("[Migration] Flipped {$flipped} contracts from halo_legacy to psa");
        }

        // Audit trail: log the flip as contract activity for all Halo-originating contracts
        $haloContractIds = DB::table('contracts')
            ->whereNotNull('halo_id')
            ->pluck('id');

        $now = now();
        foreach ($haloContractIds as $contractId) {
            DB::table('contract_activities')->insert([
                'contract_id' => $contractId,
                'user_id' => null,
                'action' => 'migration_halo_unlock',
                'changes' => json_encode(['billing_source' => ['old' => 'halo_legacy', 'new' => 'psa']]),
                'created_at' => $now,
            ]);
        }
    }

    /**
     * This migration is not reversible — we cannot determine which contracts
     * were originally halo_legacy vs already psa.
     */
    public function down(): void
    {
        // Irreversible data migration
    }
};
