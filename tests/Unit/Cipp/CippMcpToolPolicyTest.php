<?php

namespace Tests\Unit\Cipp;

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

    /** Refusal matching trims incidental whitespace on both names. */
    public function test_refusal_matching_trims_whitespace(): void
    {
        $this->assertSame(
            'blocked upstream tool',
            CippMcpToolPolicy::refusalReason('cipp_anything', '  ListMailboxRules  '),
        );

        $curated = CippMcpToolPolicy::curatedLocalToolNames();
        $this->assertSame(
            'collides with the local name of a curated tool',
            CippMcpToolPolicy::refusalReason('  '.$curated[0].'  ', 'SomeUnrelatedUpstreamTool'),
        );
    }
}
