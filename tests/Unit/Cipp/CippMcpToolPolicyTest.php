<?php

namespace Tests\Unit\Cipp;

use App\Services\Cipp\CippMcpCatalogSyncService;
use App\Support\CippMcpToolPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The policy's two HARD gates. Neither can be bought back by an explicit operator grant —
 * they name DANGER, not un-review, which is exactly what distinguishes them from the
 * retired APPROVED_DYNAMIC_UPSTREAM_TOOLS allow-list (psa-pzwv). Authorization proper is a
 * per-token grant question and lives in McpStaffController::toolAllowed(); this class only
 * answers what may never be dispatched at all.
 */
class CippMcpToolPolicyTest extends TestCase
{
    /**
     * Both blocked names are refused, with the reason naming the block. These are the two
     * upstream tools where a raw passthrough is a security failure rather than a missing
     * feature: ListMailboxRules takes no user parameter and returns every mailbox's rules in
     * the tenant; ListUserSigninLogs filters Graph on an Azure AD object ID, so a UPN yields
     * a confident false "no sign-ins" during compromise triage.
     */
    public function test_a_blocked_upstream_tool_is_refused(): void
    {
        foreach (CippMcpToolPolicy::BLOCKED_UPSTREAM_TOOLS as $blocked) {
            $this->assertSame(
                'blocked upstream tool',
                CippMcpToolPolicy::refusalReason('cipp_anything', $blocked),
                "{$blocked} is a known hazard and must always be refused.",
            );
            $this->assertFalse(CippMcpToolPolicy::permitsDynamicTool('cipp_anything', $blocked));
        }
    }

    /**
     * A dynamic row may never take a curated tool's local name. The dynamic executor is
     * dispatched BEFORE the curated one, so a collision is always a privilege downgrade: the
     * reviewed, scoped implementation is silently replaced by a raw passthrough.
     */
    public function test_a_curated_name_collision_is_refused(): void
    {
        $curated = CippMcpToolPolicy::curatedLocalToolNames();
        $this->assertNotEmpty($curated, 'The curated CIPP tool list must not be empty.');

        $this->assertSame(
            'collides with the local name of a curated tool',
            CippMcpToolPolicy::refusalReason($curated[0], 'SomeUnrelatedUpstreamTool'),
        );
        $this->assertFalse(CippMcpToolPolicy::permitsDynamicTool($curated[0], 'SomeUnrelatedUpstreamTool'));
    }

    /**
     * The long tail is PERMITTED by the policy — being unreviewed is not a refusal reason.
     * It is not thereby live: it reaches an agent only when an operator explicitly grants
     * that exact tool to a specific token. This is the psa-pzwv inversion, and asserting it
     * here pins the boundary between "not forbidden" (this class) and "authorized" (the
     * per-token grant gate).
     */
    public function test_an_unreviewed_long_tail_tool_is_permitted_by_the_policy(): void
    {
        $this->assertNull(CippMcpToolPolicy::refusalReason('cipp_list_db_cache', 'ListDBCache'));
        $this->assertTrue(CippMcpToolPolicy::permitsDynamicTool('cipp_list_db_cache', 'ListDBCache'));
    }

    /**
     * Refusal matching trims incidental whitespace on both names.
     *
     * The example is ListUserSigninLogs rather than ListMailboxRules because psa-4k6m
     * removed the latter from the blocklist. The property under test — trimming — is
     * unchanged; only an example that happened to be a blocked name went stale.
     */
    public function test_refusal_matching_trims_whitespace(): void
    {
        $this->assertSame(
            'blocked upstream tool',
            CippMcpToolPolicy::refusalReason('cipp_anything', '  ListUserSigninLogs  '),
        );

        $curated = CippMcpToolPolicy::curatedLocalToolNames();
        $this->assertSame(
            'collides with the local name of a curated tool',
            CippMcpToolPolicy::refusalReason('  '.$curated[0].'  ', 'SomeUnrelatedUpstreamTool'),
        );
    }

    /**
     * *** THE LOAD-BEARING CLAIM OF psa-4k6m's BLOCKLIST CHANGE: removing
     * 'ListMailboxRules' from BLOCKED_UPSTREAM_TOOLS changed NOTHING at runtime. ***
     *
     * The whole safety argument for shrinking the list without shipping the sign-ins
     * work alongside it is that this one entry was already redundant: ListMailboxRules
     * normalises to `cipp_list_mailbox_rules`, which is a curated tool's name, so the
     * collision branch refuses the dynamic row regardless of the blocklist.
     *
     * That argument depends on a fact in a DIFFERENT file (the curated tool's name in
     * TriageToolDefinitions). Rename or remove the curated per-mailbox tool and the raw
     * tenant-wide passthrough silently becomes importable and dispatchable — which is
     * exactly how this hole was opened once before: "Conflating those two reasons is
     * what let ListMailboxRules be dropped from the curated list during an earlier fix
     * — which silently made it importable again" (CippMcpToolPolicy, psa-7lgo.1).
     *
     * So the claim is pinned here rather than left as a comment. If this test fails,
     * the passthrough is exposed and the fix is NOT to re-add the blocklist entry —
     * it is to work out why the curated name moved.
     */
    public function test_the_tenant_wide_passthrough_stays_refused_by_the_collision_guard_alone(): void
    {
        $this->assertNotContains(
            'ListMailboxRules',
            CippMcpToolPolicy::BLOCKED_UPSTREAM_TOOLS,
            'psa-4k6m removed this entry deliberately; re-adding it means the blocklist reflex came back.',
        );

        $localName = CippMcpCatalogSyncService::localNameFor('ListMailboxRules');

        $this->assertSame('cipp_list_mailbox_rules', $localName);
        $this->assertContains($localName, CippMcpToolPolicy::curatedLocalToolNames());

        // Refused at import...
        $this->assertSame(
            'collides with the local name of a curated tool',
            CippMcpToolPolicy::refusalReason($localName, 'ListMailboxRules'),
        );
        $this->assertFalse(CippMcpToolPolicy::permitsDynamicTool($localName, 'ListMailboxRules'));

        // ...and the capability an operator actually wants is exposed properly instead.
        $this->assertContains('cipp_list_tenant_mailbox_rules', CippMcpToolPolicy::curatedLocalToolNames());
    }

    /**
     * The other entry is NOT redundant, and that asymmetry is the entire reason the list
     * still exists. cipp_list_user_signin_logs collides with no curated name, so the
     * blocklist is the only thing standing between a granted token and a passthrough
     * that answers compromise triage with a confident empty (psa-4k6m item 2 / the
     * unblock-and-make-correct invariant). Pinned so nobody "finishes the job" by
     * emptying the list without doing the correctness work first.
     */
    public function test_the_signin_passthrough_is_still_refused_only_by_the_blocklist(): void
    {
        $localName = CippMcpCatalogSyncService::localNameFor('ListUserSigninLogs');

        $this->assertSame('cipp_list_user_signin_logs', $localName);
        $this->assertNotContains($localName, CippMcpToolPolicy::curatedLocalToolNames());
        $this->assertSame(
            'blocked upstream tool',
            CippMcpToolPolicy::refusalReason($localName, 'ListUserSigninLogs'),
        );
    }
}
