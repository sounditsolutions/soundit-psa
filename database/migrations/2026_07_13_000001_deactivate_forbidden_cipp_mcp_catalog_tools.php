<?php

use App\Support\CippMcpToolPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deactivate any dynamic CIPP catalog row the policy forbids (psa-7lgo.7).
 *
 * An earlier head briefly left the tenant-wide ListMailboxRules importable, so an
 * environment that ran a catalog sync against it can be carrying an ACTIVE row for it —
 * under the curated tool's own local name, which the dynamic dispatcher would have
 * preferred over the user-scoped implementation.
 *
 * The runtime guard on CippMcpTool already makes such a row inert, so this migration is
 * not what closes the hole. It is here so the stale row does not sit in the table looking
 * live, and so operators see it leave rather than wondering whether the optional weekly
 * catalog sync has run yet.
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
        // the disclosure this migration exists to clear.
    }
};
