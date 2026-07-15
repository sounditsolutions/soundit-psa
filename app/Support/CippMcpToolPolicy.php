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
 *
 * ── WHY THERE IS NO APPROVAL ALLOW-LIST HERE (psa-3g8y → psa-pzwv) ───────────────────
 * This policy names only what is FORBIDDEN. It deliberately does not carry an "approved"
 * allow-list, and the reason is worth keeping because the question recurs: if we already
 * had per-token grants, why was psa-3g8y's allow-list ever necessary — and why was it safe
 * to remove?
 *
 * psa-3g8y added APPROVED_DYNAMIC_UPSTREAM_TOOLS as a default-deny allow-list: an upstream
 * tool not named there was never imported, advertised, or executable. The goal was right —
 * kill IMPORT-BY-DEFAULT, where an unreviewed raw passthrough goes live with no human
 * decision — but the mechanism reached too far. It also made tools an operator had
 * DELIBERATELY assigned to a token inert, and on deploy ~210 of Chet's granted tools
 * vanished (psa-pzwv).
 *
 * It could be dropped without re-opening the hole because authorization never rested on it.
 * McpStaffController::toolAllowed() requires `allowedTools !== null && allows($tool)` for
 * any name CippMcpTool::handles() claims — an explicit, per-token, per-name grant governing
 * BOTH tools/list and tools/call. That predates psa-3g8y and is strictly stronger: it admits
 * a tool only when a human picked it for a specific token, whereas an allow-list admits an
 * approved tool to anyone. Grants are stored as resolved name snapshots
 * (McpTokensController::updateTools), so a later catalog sync cannot silently widen one.
 * Against import-by-default the allow-list was redundant; against operator intent it was
 * destructive.
 *
 * ── THE STANDING DEFAULT IS *NOT* A BLOCKLIST (owner directive, 2026-07-15) ─────────
 * An earlier version of this very docblock said the remaining gates "name DANGER, which no
 * grant may buy back." *** That is NOT this office's model, and repeating it here was
 * regenerating the wrong instinct in every next reader. *** Charlie, verbatim: "Don't
 * hard-block those either. I have said this a dozen times. Wire the tools, make sure they
 * work correctly and let me/the MSP decide which ones to allow."
 *
 * So the default is: WIRE the tool → MAKE IT CORRECT → EXPOSE IT GRANTABLE → allow/deny is
 * the operator's, per token. Safety here is CORRECTNESS plus operator grant discretion, not
 * a code-level blocklist. When a tool reads as "dangerous", the answer is almost always
 * make-it-correct + expose-grantable — not block. Do not add to a blocklist by reflex.
 *
 * BLOCKED_UPSTREAM_TOOLS is therefore ON ITS WAY OUT: it holds exactly ListMailboxRules and
 * ListUserSigninLogs, both of which that directive unblocks, so the constant empties and the
 * concept goes with it. It survives only until the correctness work below lands.
 *
 * *** THE ONE THING THAT MUST NOT BE DECOUPLED: unblock and make-correct ship TOGETHER. ***
 * The block is not what makes ListUserSigninLogs safe — CORRECTNESS is. Remove it from the
 * list before the UPN→objectID resolution is wired and the raw passthrough answers a
 * compromise-triage question with HTTP 200 + empty body, i.e. a confident "this account has
 * no sign-ins" that is a lie. Unblocking first is the only way to turn this directive into
 * the exact false all-clear it explicitly forbids. Same for ListMailboxRules: wire it, label
 * the tenant-wide scope, prove no false-empty — then unlist it.
 *
 * Curated-name collisions stay, but read them as CORRECTNESS, not policy: they stop a raw
 * passthrough silently SHADOWING a hand-written curated tool. That is an integrity
 * mechanism, not an allow/deny opinion, and it removes no operator choice.
 *
 * ListGraphRequest / ListGraphBulkRequest — the two names the retired allow-list held — do
 * have dedicated, security-reviewed handling (inspectable-GET-only via
 * genericGraphAttempt/isGenericGraphTool, reference-only sanitised output,
 * credential-redacted telemetry). That handling is keyed off the tool names inside
 * CippMcpDynamicToolExecutor, never off a list here. psa-ppm7 tracks replacing them with
 * typed curated wrappers.
 *
 * The constant itself is gone (psa-xty1). Its last caller was the 2026_07_14_000001 sweep
 * migration, which now carries its own frozen literal — a shipped migration must not read a
 * live app constant, or editing the constant silently rewrites what that migration does on
 * every future fresh-DB replay.
 */
final class CippMcpToolPolicy
{
    /**
     * *** BEING RETIRED — DO NOT ADD TO THIS LIST. *** Read the class docblock first.
     *
     * Owner directive 2026-07-15: hard-blocking is not this office's model. Both entries
     * below are to be UNBLOCKED, wired, made correct and exposed as grantable, with allow/
     * deny left to the operator's per-token grant — which empties this constant and retires
     * the concept. It survives only until that correctness work lands, and it must not
     * outlive it. The paragraphs below explain what the raw passthrough gets WRONG for these
     * two, which is exactly the correctness bar that work has to clear — they are the spec
     * for fixing the tools, NOT a case for keeping them blocked.
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
