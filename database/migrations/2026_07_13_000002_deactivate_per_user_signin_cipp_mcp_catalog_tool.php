<?php

use App\Support\CippMcpToolPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deactivate any dynamic CIPP catalog row the policy now forbids (psa-cipp-p1).
 *
 * The sweep in 2026_07_13_000001 is written against the policy constant, but it has
 * already run wherever it was deployed — so growing BLOCKED_UPSTREAM_TOOLS does not
 * retroactively re-run it. ListUserSigninLogs was importable until now (only the
 * tenant-wide ListSignIns was on the curated skip list, and cipp_list_user_signin_logs
 * collides with no curated name), so any environment that has run a catalog sync is
 * carrying an ACTIVE row for CIPP's per-user sign-in endpoint right now.
 *
 * As with its predecessor: the runtime guard on CippMcpTool (scopeDispatchable) is what
 * actually closes the hole — such a row is already unlistable and undispatchable. This
 * migration is here so it does not sit in the table looking live, and so operators see it
 * leave rather than wondering whether the optional weekly catalog sync has run yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        $deactivated = DB::table('cipp_mcp_tools')
            ->where('active', true)
            ->where(function ($query): void {
                $query->whereIn('upstream_name', CippMcpToolPolicy::BLOCKED_UPSTREAM_TOOLS)
                    ->orWhereIn('local_name', CippMcpToolPolicy::curatedLocalToolNames());
            })
            ->update(['active' => false]);

        if ($deactivated > 0) {
            Log::warning('[CippMcpCatalogSync] Deactivated forbidden dynamic CIPP catalog rows on migrate', [
                'deactivated' => $deactivated,
            ]);
        }
    }

    public function down(): void
    {
        // Deliberately irreversible. Re-activating a row the policy forbids would re-open
        // the false clean this migration exists to clear.
    }
};
