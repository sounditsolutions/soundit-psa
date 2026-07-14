<?php

namespace App\Support;

use App\Services\Triage\TriageToolDefinitions;

/**
 * What may never become a dynamic CIPP catalog tool.
 *
 * The CIPP MCP catalog is imported from whatever the upstream server advertises, and
 * dynamic rows are executed as a RAW PASSTHROUGH: whatever parameters the model supplies,
 * plus the tenant. That is safe for the long tail of read tools, and unsafe for exactly
 * two classes of tool — which is what this policy names.
 *
 * It is consulted in two places, and it has to be both:
 *   - at IMPORT time, by CippMcpCatalogSyncService, so an offending row is never written;
 *   - at RUNTIME, by CippMcpTool's query scopes, so an offending row that is ALREADY in
 *     the table is inert the moment it is read.
 *
 * Import-time alone is not enough. The catalog sync is optional, weekly and config-gated,
 * so "the next sync cleans it up" leaves the hole open across a deploy (psa-7lgo.7).
 */
final class CippMcpToolPolicy
{
    /**
     * Upstream tools that must never reach the agent — not curated, not dynamic.
     *
     * A dynamic catalog row is executed as a RAW PASSTHROUGH: whatever parameters the
     * model supplies, plus the tenant. Both entries below are tools where that is not a
     * missing feature but a security failure, and where a hand-written curated tool
     * already provides the same capability safely.
     *
     * ListMailboxRules takes NO user parameter (its only CIPP parameters are tenantFilter
     * and UseReportDB) and returns EVERY mailbox's rules in the tenant. The curated
     * cipp_list_mailbox_rules exists precisely to scope that read to one mailbox.
     *
     * ListUserSigninLogs filters Microsoft Graph on the signIn `userId` property — an
     * Azure AD OBJECT ID and nothing else (CIPP-API dev, Invoke-ListUserSigninLogs.ps1
     * lines 17-18). A raw passthrough cannot bridge a UPN to an object ID, so a model
     * passing the address it read off a ticket gets a valid filter that matches zero rows:
     * HTTP 200, empty body, and a confident "this account has no sign-ins" during
     * compromise triage. The curated cipp_list_sign_ins now refuses that question rather
     * than answer it falsely (CippToolContract::identityRefusal), and this row would
     * cheerfully answer it anyway. It is reachable in the wild: it is not the tenant-wide
     * ListSignIns the curated skip list names, and it normalises to
     * cipp_list_user_signin_logs, which collides with no curated name — so nothing
     * refused it and any environment that has run a catalog sync is carrying an active
     * row for it today (psa-cipp-p1).
     *
     * This list is separate from the curated list on purpose. A tool is curated because we
     * hand-wrote it; a tool is blocked because it is dangerous. Conflating those two
     * reasons is what let ListMailboxRules be dropped from the curated list during an
     * earlier fix — which silently made it importable again, and re-opened the very
     * cross-mailbox disclosure that fix was closing (psa-7lgo.1). Naming the reason is the
     * fix, and it is why blocking is enforced at RUNTIME (CippMcpTool::scopeDispatchable)
     * and not merely at import.
     */
    public const BLOCKED_UPSTREAM_TOOLS = [
        'ListMailboxRules',
        'ListUserSigninLogs',
    ];

    /**
     * The ONLY upstream tools approved for dynamic raw-passthrough import. DEFAULT-DENY:
     * an upstream tool that is not on this list is never imported, advertised, or
     * executable — even when CIPP's live ExecMCP tools/list starts advertising it.
     *
     * This inverts the import-by-default (deny-by-omission) that let ListMailboxRules and
     * ListUserSigninLogs in: previously any read tool not curated and not blocked was
     * imported and run as a raw passthrough, so the unreviewed long tail was live by
     * default and each new dangerous tool was a fresh hole (psa-3g8y, from the psa-dbrw.8
     * review of PR #276).
     *
     * WHY exactly these two and nothing else: ListGraphRequest / ListGraphBulkRequest are
     * the only dynamic tools with dedicated, security-reviewed handling in
     * CippMcpDynamicToolExecutor (inspectable-GET-only via genericGraphAttempt/
     * isGenericGraphTool, reference-only sanitised output, credential-redacted telemetry),
     * and they are the generic Graph read capability Chet actively uses. psa-ppm7 tracks
     * replacing them with typed curated wrappers, after which this list shrinks toward
     * empty. Every OTHER upstream read tool is an UNREVIEWED raw passthrough — the surface
     * this default-deny refuses.
     *
     * To approve a new tool: review its CIPP source for scoping/identity traps (the
     * psa-7lgo rule — read the producer, don't guess the shape) and add its exact upstream
     * name here. That edit is a security-review event, exactly like editing
     * BLOCKED_UPSTREAM_TOOLS. Deliberately EXPLICIT NAMES, not category/"class" matching:
     * a class allow-list would re-admit danger (ListMailboxRules is itself read-only and
     * category Email).
     */
    public const APPROVED_DYNAMIC_UPSTREAM_TOOLS = [
        'ListGraphRequest',
        'ListGraphBulkRequest',
    ];

    /**
     * May a dynamic catalog row under this (local, upstream) name pair be advertised or
     * executed?
     */
    public static function permitsDynamicTool(string $localName, string $upstreamName): bool
    {
        return self::refusalReason($localName, $upstreamName) === null;
    }

    /**
     * Is this upstream tool approved for dynamic raw-passthrough import? Default-deny: an
     * upstream name not on APPROVED_DYNAMIC_UPSTREAM_TOOLS is refused.
     *
     * Kept separate from refusalReason() on purpose: refusalReason() names DANGER (a
     * blocked tool or a curated-name collision) and is logged at WARNING, because that is
     * an anomaly somebody must look at. "Not on the allow-list" is the EXPECTED default for
     * the entire unreviewed long tail and must stay quiet — folding it into refusalReason()
     * would drown the real warnings in routine long-tail noise.
     */
    public static function approvedDynamicTool(string $upstreamName): bool
    {
        return in_array(trim($upstreamName), self::APPROVED_DYNAMIC_UPSTREAM_TOOLS, true);
    }

    /**
     * Why a dynamic catalog row is refused, or null if it is permitted. Returned rather
     * than a bare bool so the refusal can be logged with its reason.
     */
    public static function refusalReason(string $localName, string $upstreamName): ?string
    {
        if (in_array(trim($upstreamName), self::BLOCKED_UPSTREAM_TOOLS, true)) {
            return 'blocked upstream tool';
        }

        // A dynamic row must never take the local name of a hand-written curated tool.
        // McpStaffController dispatches dynamic catalog tools BEFORE the curated executor,
        // so a name collision is always a privilege downgrade: the reviewed, scoped
        // implementation is replaced by a raw passthrough to whatever upstream endpoint
        // happens to share the name. The curated one always wins.
        if (in_array(trim($localName), self::curatedLocalToolNames(), true)) {
            return 'collides with the local name of a curated tool';
        }

        return null;
    }

    /**
     * The local names of the hand-written CIPP tools, which a dynamic import may never
     * take.
     *
     * @return array<int, string>
     */
    public static function curatedLocalToolNames(): array
    {
        /** @var array<int, string> */
        return array_column(TriageToolDefinitions::cippTools(), 'name');
    }
}
