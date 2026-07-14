<?php

namespace Tests\Unit\Cipp;

use App\Support\CippMcpToolPolicy;
use PHPUnit\Framework\TestCase;

class CippMcpToolPolicyTest extends TestCase
{
    /**
     * The allow-list is default-deny: only the explicitly reviewed upstream tools
     * are approved for dynamic raw-passthrough import. Anything else — the entire
     * unreviewed long tail — is refused, which is the inversion this bead makes
     * (psa-3g8y). The two approved names are the generic Graph read tools that have
     * dedicated, security-reviewed handling in CippMcpDynamicToolExecutor.
     */
    public function test_approves_only_the_reviewed_graph_passthrough_tools(): void
    {
        $this->assertTrue(CippMcpToolPolicy::approvedDynamicTool('ListGraphRequest'));
        $this->assertTrue(CippMcpToolPolicy::approvedDynamicTool('ListGraphBulkRequest'));
    }

    /**
     * The whole point of the inversion: an arbitrary read-only upstream tool CIPP
     * may start advertising is NOT approved by default. ListDBCache stands in for
     * the long tail — read-only, harmless-looking, and exactly the kind of raw
     * passthrough this default-deny refuses until a human reviews it.
     */
    public function test_default_denies_an_unreviewed_upstream_tool(): void
    {
        $this->assertFalse(CippMcpToolPolicy::approvedDynamicTool('ListDBCache'));
        $this->assertFalse(CippMcpToolPolicy::approvedDynamicTool('ListAppConsentRequests'));
    }

    /**
     * A blocked upstream tool is also not approved — the two lists are consistent,
     * so a name can never be simultaneously blocked and approved.
     */
    public function test_a_blocked_upstream_tool_is_not_approved(): void
    {
        foreach (CippMcpToolPolicy::BLOCKED_UPSTREAM_TOOLS as $blocked) {
            $this->assertFalse(
                CippMcpToolPolicy::approvedDynamicTool($blocked),
                "{$blocked} is blocked and must never be approved.",
            );
        }
    }

    /** The check trims incidental whitespace, mirroring refusalReason()/curated matching. */
    public function test_approval_trims_whitespace(): void
    {
        $this->assertTrue(CippMcpToolPolicy::approvedDynamicTool('  ListGraphRequest  '));
        $this->assertFalse(CippMcpToolPolicy::approvedDynamicTool('  ListDBCache  '));
    }
}
