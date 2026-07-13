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
            $this->tool('ListDBCache', category: 'CIPP'),
        ]);

        $result = app(CippMcpCatalogSyncService::class)->sync($client);

        $this->assertDatabaseMissing('cipp_mcp_tools', ['local_name' => 'cipp_list_mailbox_rules']);
        // The rest of the catalog still syncs — refusing one tool must not break it.
        $this->assertDatabaseHas('cipp_mcp_tools', ['local_name' => 'cipp_list_db_cache', 'active' => true]);
        $this->assertSame(1, $result->active);
    }

    /**
     * The three outcomes of a catalog sync, in one pass: the long tail of read tools is
     * imported, a curated tool's upstream is skipped, and a BLOCKED upstream is refused.
     *
     * This test previously asserted the opposite of its third case — it was named
     * "..._and_imports_user_signin_logs" and pinned cipp_list_user_signin_logs as an
     * active row. That is worth saying out loud: the per-user sign-in passthrough was not
     * an oversight in the skip list, it was a guaranteed behaviour with a green test
     * behind it. It is a raw passthrough onto an endpoint that filters Graph on an Azure
     * AD object ID and nothing else, so it answers a confident "no sign-ins" to any UPN
     * it is handed — the exact false clean the curated cipp_list_sign_ins now refuses
     * (psa-cipp-p1).
     */
    public function test_sync_imports_dynamic_read_tools_and_skips_curated_and_blocked_tools(): void
    {
        $client = Mockery::mock(CippMcpClient::class);
        $client->shouldReceive('listTools')->once()->andReturn([
            $this->tool('ListUsers'),
            $this->tool('ListDBCache', category: 'CIPP'),
            $this->tool('ListUserSigninLogs', category: 'Identity'),
        ]);

        $result = app(CippMcpCatalogSyncService::class)->sync($client);

        $this->assertInstanceOf(CippMcpCatalogSyncResult::class, $result);
        $this->assertSame(3, $result->seen);
        $this->assertSame(1, $result->active);
        $this->assertSame(1, $result->created);

        // Curated: we hand-wrote cipp_list_users, so the raw upstream is skipped.
        $this->assertDatabaseMissing('cipp_mcp_tools', ['local_name' => 'cipp_list_users']);

        // Blocked: dangerous as a raw passthrough, whatever name it arrives under.
        $this->assertDatabaseMissing('cipp_mcp_tools', ['upstream_name' => 'ListUserSigninLogs']);

        // The long tail still syncs — refusing tools must not break the rest of the catalog.
        $this->assertDatabaseHas('cipp_mcp_tools', [
            'local_name' => 'cipp_list_db_cache',
            'upstream_name' => 'ListDBCache',
            'category' => 'CIPP',
            'read_only' => true,
            'sensitive' => false,
            'active' => true,
        ]);
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
            $this->tool('ListAppConsentRequests', category: 'Tenant'),
        ]);

        $result = app(CippMcpCatalogSyncService::class)->sync($client);

        $this->assertSame(1, $result->deactivated);
        $this->assertFalse(CippMcpTool::where('local_name', 'cipp_list_db_cache')->firstOrFail()->active);
        $this->assertTrue(CippMcpTool::where('local_name', 'cipp_list_app_consent_requests')->firstOrFail()->active);
    }

    public function test_sync_collision_fails_closed_without_partial_catalog_corruption(): void
    {
        CippMcpTool::create([
            'local_name' => 'cipp_existing_tool',
            'upstream_name' => 'ExistingTool',
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
            $this->tool('ExistingToolRenamed', name: 'Existing Tool Renamed'),
            $this->tool('Existing_Tool', name: 'Existing Tool'),
            $this->tool('ListDBCache'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CIPP MCP catalog local-name collision');

        try {
            app(CippMcpCatalogSyncService::class)->sync($client);
        } finally {
            $this->assertSame(1, CippMcpTool::count());
            $this->assertTrue(CippMcpTool::where('local_name', 'cipp_existing_tool')->firstOrFail()->active);
            $this->assertDatabaseMissing('cipp_mcp_tools', ['local_name' => 'cipp_list_db_cache']);
        }
    }

    public function test_non_read_only_catalog_entries_land_in_sensitive_write_tier(): void
    {
        $client = Mockery::mock(CippMcpClient::class);
        $client->shouldReceive('listTools')->once()->andReturn([
            $this->tool('SetUserLicense', category: 'Identity', readOnly: false),
        ]);

        app(CippMcpCatalogSyncService::class)->sync($client);

        $this->assertDatabaseHas('cipp_mcp_tools', [
            'local_name' => 'cipp_set_user_license',
            'upstream_name' => 'SetUserLicense',
            'read_only' => false,
            'sensitive' => true,
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
