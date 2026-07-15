<?php

namespace Tests\Feature\Cipp;

use App\Models\CippMcpTool;
use App\Services\Cipp\CippMcpCatalogSyncResult;
use App\Services\Cipp\CippMcpCatalogSyncService;
use App\Services\Cipp\CippMcpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CippMcpCatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The dynamic catalog must never resurrect the tenant-wide mailbox-rules
     * endpoint (psa-7lgo.1).
     *
     * CURATED_UPSTREAM_TOOLS is a SKIP list: anything NOT in it gets imported as
     * a dynamic tool. CIPP's ListMailboxRules takes no user parameter and returns
     * every mailbox's rules in the tenant, and it maps to the SAME local name as
     * our user-scoped cipp_list_mailbox_rules. Since McpStaffController dispatches
     * dynamic catalog tools BEFORE the curated executor, importing it would SHADOW
     * the fixed tool with a raw tenant-wide passthrough — re-opening the
     * cross-mailbox disclosure under the very name that was fixed to close it.
     *
     * That is not hypothetical: repointing the curated list at ListUserMailboxRules
     * during the fix removed ListMailboxRules from the skip list and made it
     * importable. Hence a BLOCKED list, distinct from the curated one.
     */
    public function test_sync_never_resurrects_tenant_wide_mailbox_rules(): void
    {
        $client = Mockery::mock(CippMcpClient::class);
        $client->shouldReceive('listTools')->once()->andReturn([
            $this->tool('ListMailboxRules', category: 'Email'),
            $this->tool('ListUserMailboxRules', category: 'Email'),
        ]);

        app(CippMcpCatalogSyncService::class)->sync($client);

        $this->assertDatabaseMissing('cipp_mcp_tools', ['upstream_name' => 'ListMailboxRules']);
        $this->assertDatabaseMissing('cipp_mcp_tools', ['local_name' => 'cipp_list_mailbox_rules']);
        $this->assertDatabaseMissing('cipp_mcp_tools', ['upstream_name' => 'ListUserMailboxRules']);
        $this->assertSame(0, CippMcpTool::query()->where('active', true)->count());
    }

    /**
     * The third door onto the sign-in false clean (psa-cipp-p1).
     *
     * The curated cipp_list_sign_ins tool covers TWO upstream endpoints: the tenant-wide
     * ListSignIns and the per-user ListUserSigninLogs. Only the first was in the curated
     * SKIP list — so ListUserSigninLogs was importable, and it normalises to
     * cipp_list_user_signin_logs, which collides with nothing and therefore sails past
     * the shadowing guard as an ADDITIONAL tool.
     *
     * A dynamic row is a RAW PASSTHROUGH: it forwards whatever the model typed, with no
     * identity bridging whatever. ListUserSigninLogs filters Microsoft Graph on the
     * signIn `userId` property — an Azure AD object ID — so a model passing the UPN it
     * read off the ticket gets HTTP 200 and an empty list, and reports "no sign-ins" for
     * a possibly-compromised account. That is the exact false clean the curated tool now
     * refuses to produce, reachable through a door the curated tool does not guard.
     *
     * Blocked rather than merely curated, deliberately: a tool is curated because we
     * hand-wrote it, and blocked because it is dangerous. Conflating the two is what let
     * ListMailboxRules become importable again (psa-7lgo.1).
     */
    public function test_sync_never_imports_the_per_user_signin_endpoint(): void
    {
        $client = Mockery::mock(CippMcpClient::class);
        $client->shouldReceive('listTools')->once()->andReturn([
            $this->tool('ListUserSigninLogs', category: 'Identity'),
        ]);

        app(CippMcpCatalogSyncService::class)->sync($client);

        $this->assertDatabaseMissing('cipp_mcp_tools', ['upstream_name' => 'ListUserSigninLogs']);
        $this->assertDatabaseMissing('cipp_mcp_tools', ['local_name' => 'cipp_list_user_signin_logs']);
        $this->assertSame(0, CippMcpTool::query()->where('active', true)->count());
    }

    /**
     * The general form of the same hole: no dynamic import may ever take the name
     * of a hand-written curated tool, whatever upstream name it arrives under —
     * because the dynamic executor is a raw passthrough and it dispatches first.
     */
    public function test_sync_refuses_any_dynamic_tool_that_would_shadow_a_curated_tool(): void
    {
        $client = Mockery::mock(CippMcpClient::class);
        $client->shouldReceive('listTools')->once()->andReturn([
            // A hypothetical upstream tool whose name normalises onto a curated
            // local tool. It must be refused, not imported.
            $this->tool('List_Mailbox_Rules', category: 'Email'),
            // An approved tool alongside it: refusing one tool must not break the rest
            // of the catalog. (Under default-deny the surviving tool must itself be
            // approved — an unapproved one would just be default-denied and prove nothing.)
            $this->tool('ListGraphRequest', category: 'CIPP'),
        ]);

        $result = app(CippMcpCatalogSyncService::class)->sync($client);

        $this->assertDatabaseMissing('cipp_mcp_tools', ['local_name' => 'cipp_list_mailbox_rules']);
        // The rest of the catalog still syncs — refusing one tool must not break it.
        $this->assertDatabaseHas('cipp_mcp_tools', ['local_name' => 'cipp_list_graph_request', 'active' => true]);
        $this->assertSame(1, $result->active);
    }

    /**
     * The three outcomes of a catalog sync, in one pass: a CURATED tool's upstream is
     * skipped, a BLOCKED upstream is refused, and everything else — including the
     * unreviewed long tail — is imported so that a tool an operator grants HAS a row.
     *
     * Importing is not advertising. A row reaches an agent only via an explicit per-token
     * grant of that exact tool (McpStaffController::toolAllowed()), so the long tail sits in
     * the catalog GRANTABLE, not live. psa-3g8y also skipped any upstream off
     * APPROVED_DYNAMIC_UPSTREAM_TOOLS here; that starved deliberate grants of their rows and
     * broke a trip-critical agent, and the grant gate already provides the stronger
     * guarantee the skip was reaching for (psa-pzwv).
     */
    public function test_sync_imports_the_long_tail_and_skips_curated_and_blocked_tools(): void
    {
        $client = Mockery::mock(CippMcpClient::class);
        $client->shouldReceive('listTools')->once()->andReturn([
            $this->tool('ListGraphRequest', category: 'CIPP'),   // long tail -> imported
            $this->tool('ListUsers'),                            // curated   -> skipped
            $this->tool('ListUserSigninLogs', category: 'Identity'), // blocked -> refused
            $this->tool('ListDBCache', category: 'CIPP'),        // long tail -> imported
        ]);

        $result = app(CippMcpCatalogSyncService::class)->sync($client);

        $this->assertInstanceOf(CippMcpCatalogSyncResult::class, $result);
        $this->assertSame(4, $result->seen);
        $this->assertSame(2, $result->active);
        $this->assertSame(2, $result->created);

        $this->assertDatabaseHas('cipp_mcp_tools', [
            'local_name' => 'cipp_list_graph_request',
            'upstream_name' => 'ListGraphRequest',
            'category' => 'CIPP',
            'read_only' => true,
            'sensitive' => false,
            'active' => true,
        ]);

        // The unreviewed long tail is imported — grantable, not live.
        $this->assertDatabaseHas('cipp_mcp_tools', [
            'local_name' => 'cipp_list_db_cache',
            'upstream_name' => 'ListDBCache',
            'active' => true,
        ]);

        // Curated: we hand-wrote cipp_list_users, so the raw upstream is skipped.
        $this->assertDatabaseMissing('cipp_mcp_tools', ['local_name' => 'cipp_list_users']);

        // Blocked: dangerous as a raw passthrough, whatever name it arrives under. No grant
        // buys this back — it is never imported, advertised, or executable.
        $this->assertDatabaseMissing('cipp_mcp_tools', ['upstream_name' => 'ListUserSigninLogs']);
    }

    public function test_sync_deactivates_catalog_rows_missing_from_later_import(): void
    {
        CippMcpTool::create([
            'local_name' => 'cipp_list_db_cache',
            'upstream_name' => 'ListDBCache',
            'category' => 'CIPP',
            'description' => 'List cache.',
            'input_schema' => ['type' => 'object', 'properties' => []],
            'annotations' => ['readOnlyHint' => true],
            'read_only' => true,
            'sensitive' => false,
            'active' => true,
            'last_seen_at' => now()->subDay(),
        ]);

        $client = Mockery::mock(CippMcpClient::class);
        $client->shouldReceive('listTools')->once()->andReturn([
            $this->tool('ListGraphRequest', category: 'CIPP'),
        ]);

        $result = app(CippMcpCatalogSyncService::class)->sync($client);

        $this->assertSame(1, $result->deactivated);
        $this->assertFalse(CippMcpTool::where('local_name', 'cipp_list_db_cache')->firstOrFail()->active);
        $this->assertTrue(CippMcpTool::where('local_name', 'cipp_list_graph_request')->firstOrFail()->active);
    }

    public function test_sync_collision_fails_closed_without_partial_catalog_corruption(): void
    {
        // An existing active row already occupies the approved tool's local name under a
        // DIFFERENT upstream — the existing-conflict the collision guard must catch. The
        // colliding import has to be an APPROVED tool: an unapproved one would be
        // default-denied out of the catalog before it ever reached the collision check, so
        // the collision must be built on the reviewed allow-list to still fire (psa-3g8y).
        CippMcpTool::create([
            'local_name' => 'cipp_list_graph_request',
            'upstream_name' => 'ListGraphRequestLegacy',
            'category' => 'CIPP',
            'description' => 'Existing.',
            'input_schema' => ['type' => 'object', 'properties' => []],
            'annotations' => ['readOnlyHint' => true],
            'read_only' => true,
            'sensitive' => false,
            'active' => true,
            'last_seen_at' => now()->subDay(),
        ]);

        $client = Mockery::mock(CippMcpClient::class);
        $client->shouldReceive('listTools')->once()->andReturn([
            // An innocent approved tool that WOULD import — proving the abort is all-or-nothing.
            $this->tool('ListGraphBulkRequest', name: 'Graph Bulk Request'),
            // Collides with the seeded row's local name under a different upstream.
            $this->tool('ListGraphRequest', name: 'Graph Request'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CIPP MCP catalog local-name collision');

        try {
            app(CippMcpCatalogSyncService::class)->sync($client);
        } finally {
            $this->assertSame(1, CippMcpTool::count());
            $this->assertSame('ListGraphRequestLegacy', CippMcpTool::where('local_name', 'cipp_list_graph_request')->firstOrFail()->upstream_name);
            $this->assertTrue(CippMcpTool::where('local_name', 'cipp_list_graph_request')->firstOrFail()->active);
            // The innocent bystander was NOT written — no partial catalog corruption.
            $this->assertDatabaseMissing('cipp_mcp_tools', ['local_name' => 'cipp_list_graph_bulk_request']);
        }
    }

    /**
     * A write-class upstream tool imports as a SENSITIVE write-tier row (readOnlyHint=false
     * => read_only=false => sensitive=true). The tiering is what the executor's write-class
     * execution refusal keys off, so this pins the input to that guard: no dynamic
     * write-class row is ever executed, however it was imported (psa-pzwv restores this;
     * psa-3g8y had default-denied the row before it reached the tiering).
     */
    public function test_sync_imports_a_write_class_tool_as_a_sensitive_row(): void
    {
        $client = Mockery::mock(CippMcpClient::class);
        $client->shouldReceive('listTools')->once()->andReturn([
            $this->tool('SetUserLicense', category: 'Identity', readOnly: false),
        ]);

        $result = app(CippMcpCatalogSyncService::class)->sync($client);

        $this->assertSame(1, $result->active);
        $this->assertDatabaseHas('cipp_mcp_tools', [
            'local_name' => 'cipp_set_user_license',
            'upstream_name' => 'SetUserLicense',
            'read_only' => false,
            'sensitive' => true,
            'active' => true,
        ]);
    }

    /**
     * The complementary read tiering: an unreviewed read-only long-tail tool imports as a
     * non-sensitive read row, which is the tier that is executable AT ALL — and then only
     * for a token that explicitly grants it (psa-pzwv).
     */
    public function test_sync_imports_an_unreviewed_read_tool_as_a_non_sensitive_row(): void
    {
        $client = Mockery::mock(CippMcpClient::class);
        $client->shouldReceive('listTools')->once()->andReturn([
            $this->tool('ListDBCache', category: 'CIPP'),
        ]);

        $result = app(CippMcpCatalogSyncService::class)->sync($client);

        $this->assertSame(1, $result->seen);
        $this->assertSame(1, $result->active);
        $this->assertSame(1, $result->created);
        $this->assertDatabaseHas('cipp_mcp_tools', [
            'local_name' => 'cipp_list_db_cache',
            'upstream_name' => 'ListDBCache',
            'read_only' => true,
            'sensitive' => false,
            'active' => true,
        ]);
    }

    /**
     * The generic Graph passthrough — the one dynamic tool with dedicated, security-reviewed
     * handling in CippMcpDynamicToolExecutor, and the one Chet leans on — syncs as an active
     * row. Named for that handling, not for a policy tier: the APPROVED allow-list it was
     * once named after is retired (psa-pzwv), and it now imports by the same rule as the rest
     * of the permitted catalog.
     */
    public function test_sync_imports_the_generic_graph_passthrough_tool(): void
    {
        $client = Mockery::mock(CippMcpClient::class);
        $client->shouldReceive('listTools')->once()->andReturn([
            $this->tool('ListGraphRequest', category: 'CIPP'),
        ]);

        $result = app(CippMcpCatalogSyncService::class)->sync($client);

        $this->assertSame(1, $result->active);
        $this->assertSame(1, $result->created);
        $this->assertDatabaseHas('cipp_mcp_tools', [
            'upstream_name' => 'ListGraphRequest',
            'local_name' => 'cipp_list_graph_request',
            'active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function tool(string $upstream, ?string $name = null, string $category = 'Identity', bool $readOnly = true): array
    {
        $displayName = $name ?? $upstream;

        return [
            'name' => $upstream,
            'description' => "[{$category}] {$displayName} description.",
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'tenantFilter' => ['type' => 'string', 'description' => 'Tenant domain.'],
                    'type' => ['type' => 'string', 'description' => 'Cache type.'],
                ],
                'required' => ['tenantFilter'],
            ],
            'annotations' => ['title' => $upstream, 'readOnlyHint' => $readOnly],
        ];
    }
}
