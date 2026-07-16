<?php

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
    /**
     * FROZEN AT THIS MIGRATION'S MOMENT (commit 5c40897, 2026-07-13), inlined by psa-4k6m.
     *
     * *** THIS ONE IS NOT A HYPOTHETICAL FIX — THE DRIFT ALREADY HAPPENED. *** This list
     * was `CippMcpToolPolicy::BLOCKED_UPSTREAM_TOOLS`, live app code read from a shipped
     * migration. At 5c40897 that constant held ONE name (verified with `git show`).
     * Today it holds TWO, so a fresh-DB replay of this migration has been sweeping a
     * WIDER set than the deploy that ran it ever swept. Nobody noticed only because the
     * 2026_07_13_000002 migration sweeps the second name moments later and the end state
     * matches — the divergence was masked, not absent.
     *
     * Its sibling's own docblock names the hazard exactly ("growing BLOCKED_UPSTREAM_TOOLS
     * does not retroactively re-run it") and worked around it with a second migration
     * rather than decoupling. This is the decoupling. Same defect and same fix as
     * psa-xty1. A migration is a FROZEN SNAPSHOT of intent; it carries its own literals.
     * Do not re-point these at the constants.
     *
     * @var array<int, string>
     */
    private const BLOCKED_AT_2026_07_13 = [
        'ListMailboxRules',
    ];

    /** @var array<int, string> */
    private const CURATED_LOCAL_NAMES_AT_2026_07_13 = [
        'cipp_list_users',
        'cipp_list_mailboxes',
        'cipp_list_licenses',
        'cipp_list_devices',
        'cipp_list_groups',
        'cipp_list_user_groups',
        'cipp_list_mailbox_permissions',
        'cipp_list_mailbox_rules',
        'cipp_list_defender_state',
        'cipp_list_conditional_access_policies',
        'cipp_list_user_conditional_access',
        'cipp_list_audit_logs',
        'cipp_list_message_trace',
        'cipp_list_mail_quarantine',
        'cipp_list_user_mfa_methods',
        'cipp_list_oauth_apps',
        'cipp_list_sign_ins',
    ];

    public function up(): void
    {
        $deactivated = DB::table('cipp_mcp_tools')
            ->where('active', true)
            ->where(function ($query): void {
                $query->whereIn('upstream_name', self::BLOCKED_AT_2026_07_13)
                    ->orWhereIn('local_name', self::CURATED_LOCAL_NAMES_AT_2026_07_13);
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
