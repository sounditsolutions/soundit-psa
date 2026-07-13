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
     * ListMailboxRules takes NO user parameter (its only CIPP parameters are tenantFilter
     * and UseReportDB) and returns EVERY mailbox's rules in the tenant. The curated
     * cipp_list_mailbox_rules exists precisely to scope that read to one mailbox.
     *
     * This list is separate from the curated list on purpose. A tool is curated because we
     * hand-wrote it; a tool is blocked because it is dangerous. Conflating those two
     * reasons is what let ListMailboxRules be dropped from the curated list during an
     * earlier fix — which silently made it importable again, and re-opened the very
     * cross-mailbox disclosure that fix was closing (psa-7lgo.1). Naming the reason is the
     * fix.
     */
    public const BLOCKED_UPSTREAM_TOOLS = [
        'ListMailboxRules',
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
