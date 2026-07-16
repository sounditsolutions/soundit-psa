<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reactivate the dynamic CIPP catalog rows psa-3g8y's allow-list sweep deactivated, so
 * explicitly granted tools work again (psa-pzwv).
 *
 * The 2026_07_14_000001 sweep deactivated every row whose upstream was not on
 * APPROVED_DYNAMIC_UPSTREAM_TOOLS — on prod, 210 of 212 rows. That correctly killed
 * import-by-default, but it also killed tools an operator had DELIBERATELY assigned to a
 * token: a row must be active to be dispatchable at all, so honoring explicit grants
 * restores nothing while the swept rows sit inactive. The catalog sync that would re-import
 * them is weekly AND config-gated (routes/console.php), so "wait for the next sync" could
 * leave a trip-critical agent broken for a week. Hence a migration: the rows come back on
 * the DEPLOY, which is the same reasoning the sweep itself used, inverted.
 *
 * This does NOT re-open import-by-default. Being active only makes a row eligible; nothing
 * reaches an agent without an explicit per-token grant of that exact tool
 * (McpStaffController::toolAllowed()), and a legacy full-surface token grants nothing
 * explicitly, so it gains none of these.
 *
 * SCOPE — deliberately narrower than the sweep's inverse:
 *   - BLOCKED_UPSTREAM_TOOLS stay deactivated. They were swept by the earlier
 *     2026_07_13_* migrations for DANGER (tenant-wide mailbox-rule disclosure; the
 *     object-ID false-clean), not for being unreviewed. Runtime hard-gates them regardless
 *     (CippMcpTool::scopeDispatchable), but reactivating them would leave a forbidden row
 *     sitting in the table looking live.
 *   - Curated-name collisions stay deactivated, for the same reason: a dynamic row under a
 *     curated tool's name is a privilege downgrade, hard-gated at runtime, and has no
 *     business looking live either.
 *
 * KNOWN IMPRECISION, accepted: "inactive" does not record WHY. A row deactivated because it
 * vanished upstream (the sync's stale sweep) is indistinguishable by column state from one
 * the psa-3g8y sweep deactivated, so this may also reactivate a tool CIPP no longer
 * advertises. That is self-correcting and harmless: the next catalog sync re-deactivates
 * anything upstream stopped advertising, and until then a phantom row can only be reached by
 * an operator explicitly granting it, whereupon the call returns an upstream error. The
 * alternative — leaving genuinely granted tools dead to avoid a phantom — is far worse.
 */
return new class extends Migration
{
    /**
     * FROZEN AT THIS MIGRATION'S MOMENT (2026-07-15), inlined by psa-4k6m.
     *
     * These two lists were `CippMcpToolPolicy::BLOCKED_UPSTREAM_TOOLS` and
     * `CippMcpToolPolicy::curatedLocalToolNames()` — LIVE app code read from an
     * already-shipped migration. That is a latent bug and it bit within a day: this
     * very change removes 'ListMailboxRules' from the constant and ADDS
     * 'cipp_list_tenant_mailbox_rules' to the curated list, either of which would have
     * silently altered what this shipped migration does on a fresh-DB replay — a
     * different set of rows reactivated than the deploy that ran it intended.
     *
     * Same defect and same fix as psa-xty1, which inlined APPROVED_DYNAMIC_UPSTREAM_TOOLS
     * into 2026_07_14_000001 for exactly this reason. A migration is a FROZEN SNAPSHOT of
     * intent; it must carry its own literals. Do not re-point these at the constants.
     *
     * @var array<int, string>
     */
    private const BLOCKED_AT_2026_07_15 = [
        'ListMailboxRules',
        'ListUserSigninLogs',
    ];

    /** @var array<int, string> */
    private const CURATED_LOCAL_NAMES_AT_2026_07_15 = [
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
        $reactivated = DB::table('cipp_mcp_tools')
            ->where('active', false)
            ->whereNotIn('upstream_name', self::BLOCKED_AT_2026_07_15)
            ->whereNotIn('local_name', self::CURATED_LOCAL_NAMES_AT_2026_07_15)
            ->update(['active' => true]);

        if ($reactivated > 0) {
            Log::info('[CippMcpCatalogSync] Reactivated dynamic CIPP catalog rows on migrate (explicit grants are honored again, psa-pzwv)', [
                'reactivated' => $reactivated,
            ]);
        }
    }

    public function down(): void
    {
        // Deliberately irreversible. Re-deactivating would re-break the explicitly granted
        // tools this migration exists to restore, and the rows carry no record of which
        // sweep first deactivated them, so the inverse cannot be reconstructed anyway.
    }
};
