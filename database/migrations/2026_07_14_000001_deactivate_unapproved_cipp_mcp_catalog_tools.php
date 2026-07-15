<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deactivate every dynamic CIPP catalog row whose upstream is NOT on the reviewed
 * allow-list (psa-3g8y).
 *
 * The catalog used to import by default: any read tool that was not curated and not
 * blocked was written as an active raw-passthrough row. So an environment that has run a
 * catalog sync is carrying an ACTIVE row for each tool in that unreviewed long tail right
 * now — the surface the allow-list inversion default-denies going forward.
 *
 * The runtime guard on CippMcpTool (scopeDispatchable now whereIn(APPROVED)) already makes
 * such a row inert on read, so this migration is not what closes the hole. It is here so
 * the stale, now-unapproved rows do not sit in the table looking live, and so operators
 * see them leave on the deploy rather than on whenever the optional weekly catalog sync
 * next runs — the same reasoning as the earlier forbidden/per-user-signin sweeps.
 *
 * HISTORICAL — read the paragraph above as of 2026-07-14, not as current behaviour.
 * psa-pzwv has since RETIRED that allow-list runtime gate: scopeDispatchable now filters on
 * blocked + curated-collisions only, and authorization proper is the per-token explicit
 * grant in McpStaffController::toolAllowed(). What this migration DID on the rows it swept
 * is unchanged; only the surrounding rationale has moved on.
 */
return new class extends Migration
{
    /**
     * The reviewed allow-list (psa-3g8y) as it stood when this migration shipped,
     * inlined ON PURPOSE and FROZEN HERE.
     *
     * It used to read CippMcpToolPolicy::APPROVED_DYNAMIC_UPSTREAM_TOOLS. That coupled an
     * already-shipped migration to a live app constant: editing the constant — entirely
     * reasonable once it was documented as historical with no other callers — would have
     * silently changed what this migration does on every future fresh-DB replay. A
     * migration is a snapshot of intent at a moment; it must carry its own literals.
     * (psa-xty1, on an architecture-lane finding from PR #290.)
     */
    private const APPROVED_UPSTREAM_AT_2026_07_14 = [
        'ListGraphRequest',
        'ListGraphBulkRequest',
    ];

    public function up(): void
    {
        $deactivated = DB::table('cipp_mcp_tools')
            ->where('active', true)
            ->whereNotIn('upstream_name', self::APPROVED_UPSTREAM_AT_2026_07_14)
            ->update(['active' => false]);

        if ($deactivated > 0) {
            Log::warning('[CippMcpCatalogSync] Deactivated unapproved dynamic CIPP catalog rows on migrate (allow-list inversion, psa-3g8y)', [
                'deactivated' => $deactivated,
            ]);
        }
    }

    public function down(): void
    {
        // Deliberately irreversible. Re-activating rows the allow-list default-denies would
        // re-open the import-by-default raw-passthrough surface this migration exists to clear.
    }
};
