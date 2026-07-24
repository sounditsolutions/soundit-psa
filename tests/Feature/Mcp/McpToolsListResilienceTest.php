<?php

namespace Tests\Feature\Mcp;

use App\Http\Controllers\Api\McpStaffController;
use App\Models\CippMcpTool;
use App\Models\Setting;
use App\Support\McpConfig;
use App\Support\McpInputSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class McpToolsListResilienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoped_tools_list_keeps_dynamic_cipp_catalog_queries_bounded(): void
    {
        $this->configureCippMcpRelay();
        $toolNames = $this->createDynamicCippCatalog(214);
        $token = McpConfig::rotateStaffToken($toolNames, 'chet');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->listTools($token);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertIsArray($response->json('result.tools'));
        $payload = json_decode($response->getContent(), false, flags: JSON_THROW_ON_ERROR);
        foreach ($payload->result->tools as $tool) {
            $this->assertSame([], McpInputSchema::validationErrors($tool->inputSchema ?? null), (string) ($tool->name ?? '(unknown)'));
        }

        $sql = collect($queries)->pluck('query')->implode("\n");
        $cippQueries = collect($queries)
            ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'cipp_mcp_tools'))
            ->count();

        $this->assertLessThanOrEqual(3, $cippQueries, $sql);

        // Coarse total-query backstop behind the precise CIPP guard above. It is a
        // CONSTANT budget, not a per-tool one — this list is built from a 214-tool
        // dynamic catalog, so anything that scales with catalog size blows past it
        // immediately rather than nudging it.
        //
        // Raised 70 -> 72 for the UniFi read surface (psa-1ynqc): every vendor config
        // helper reads its settings uncached, and ChetDataSurfaceTools consults
        // UnifiConfig::isAvailable() once in generalTools() and once in clientTools().
        // That is +2 flat and stays +2 whatever the catalog size — verified by this
        // test still passing against 214 tools. Only ever raise this for a similarly
        // constant, understood cost; a jump that tracks catalog size is the N+1 this
        // guard exists to catch.
        //
        // Raised 72 -> 74 for the ScreenConnect read surface (psa-mjf6x):
        // ScreenConnectConfig::isAvailable() is consulted in clientTools() only (no
        // general ScreenConnect tools) and short-circuits after the single
        // screenconnect_enabled read while the integration is off — but the request
        // assembles the client-scoped surface twice (the published list and the
        // liveness lookup), so the flat cost is +2, independent of catalog size.
        //
        // Raised 74 -> 78 for the Zorus read surface (psa-5wg2i): ZorusConfig::
        // isAvailable() is consulted in clientTools(), which tools/list assembles
        // twice (the published list + the liveness lookup), and zorus_enabled
        // defaults ON so the encrypted-key read always follows the switch read —
        // 2 queries x 2 assemblies = +4 flat, independent of catalog size. The two
        // surfaces are additive: 72 base + 2 (ScreenConnect) + 4 (Zorus) = 78.
        $this->assertLessThanOrEqual(78, count($queries), $sql);
    }

    public function test_tools_list_repairs_dynamic_cipp_schema_before_publishing(): void
    {
        $this->configureCippMcpRelay();

        // Schema repair is tool-agnostic, but under default-deny only an approved tool is
        // published, so the deliberately malformed schema rides the approved
        // ListGraphRequest (psa-3g8y).
        CippMcpTool::create([
            'local_name' => 'cipp_list_graph_request',
            'upstream_name' => 'ListGraphRequest',
            'category' => 'CIPP',
            'description' => 'Repairable dynamic schema.',
            'input_schema' => [
                'type' => 'object',
                'format' => ['not-a-string'],
                'properties' => [
                    'tenantFilter' => ['type' => 'string'],
                    'filter' => [
                        'type' => 'definitely-not-valid',
                        'description' => ['not-a-string'],
                        'additionalProperties' => 'sometimes',
                    ],
                    'options' => [
                        'type' => 'object',
                        'properties' => [],
                    ],
                    'itemsWrapper' => [
                        'type' => 'array',
                        'items' => ['type' => 'invalid-item-type'],
                    ],
                ],
                'required' => ['tenantFilter', 'filter', 'missing', 123],
                'additionalProperties' => 'nope',
            ],
            'annotations' => ['readOnlyHint' => true],
            'read_only' => true,
            'sensitive' => false,
            'active' => true,
            'last_seen_at' => now(),
        ]);

        $token = McpConfig::rotateStaffToken(['cipp_list_graph_request'], 'chet');

        $response = $this->listTools($token);

        $response->assertOk();
        $tool = collect($response->json('result.tools'))->firstWhere('name', 'cipp_list_graph_request');
        $this->assertIsArray($tool);

        $schema = $tool['inputSchema'];
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('client_id', $schema['properties']);
        $this->assertArrayNotHasKey('tenantFilter', $schema['properties']);
        $this->assertContains('client_id', $schema['required']);
        $this->assertContains('filter', $schema['required']);
        $this->assertNotContains('tenantFilter', $schema['required']);
        $this->assertNotContains('missing', $schema['required']);
        $this->assertArrayNotHasKey('format', $schema);
        $this->assertArrayNotHasKey('additionalProperties', $schema);
        $this->assertArrayNotHasKey('type', $schema['properties']['filter']);
        $this->assertArrayNotHasKey('description', $schema['properties']['filter']);
        $this->assertArrayNotHasKey('additionalProperties', $schema['properties']['filter']);
        $this->assertArrayNotHasKey('type', $schema['properties']['itemsWrapper']['items']);
        $this->assertStringNotContainsString('"properties":[]', $response->getContent());
    }

    public function test_publish_guard_drops_invalid_tool_schema_and_logs_tool_name(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Dropping invalid MCP tool schema')
                && ($context['tool'] ?? null) === 'bad_schema_tool'
                && ($context['errors'] ?? []) !== []);

        $method = new \ReflectionMethod(McpStaffController::class, 'publishableTool');
        $method->setAccessible(true);

        $tool = $method->invoke(app(McpStaffController::class), [
            'name' => 'bad_schema_tool',
            'description' => 'Bad schema.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    ['type' => 'string'],
                ],
            ],
        ]);

        $this->assertNull($tool);
    }

    /** @return array<int, string> */

    /**
     * Configure the CIPP MCP relay so a dynamic catalog row is genuinely LIVE.
     *
     * psa-wzjzz re-review (psa-wzjzz.6): the dynamic catalog is now published on
     * CippConfig::isMcpRelayEnabled() — the SAME predicate its executor uses — not on the
     * weaker isEnabled() default. Both tests below build dynamic rows and depend on them
     * being published; without this they were relying on cipp_enabled defaulting to '1'
     * while the relay was never configured, so they exercised a tool the executor would
     * have refused. The bounded-queries test would silently go VACUOUS (its N+1 guard never
     * runs if the catalog is not assembled); the schema-repair test fails loudly. Both are
     * fixed by configuring what "live" actually requires.
     */
    private function configureCippMcpRelay(): void
    {
        Setting::setValue('cipp_enabled', '1');
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_mcp_client_id', 'mcp-client');
        Setting::setEncrypted('cipp_mcp_client_secret', 'mcp-secret');
        Setting::setValue('cipp_mcp_enabled', '1');
    }

    private function createDynamicCippCatalog(int $count): array
    {
        $toolNames = [];

        foreach (range(1, $count) as $index) {
            $name = sprintf('cipp_catalog_tool_%03d', $index);
            $toolNames[] = $name;

            CippMcpTool::create([
                'local_name' => $name,
                'upstream_name' => sprintf('ListCatalogTool%03d', $index),
                'category' => 'CIPP',
                'description' => 'Catalog tool '.$index,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'tenantFilter' => ['type' => 'string'],
                        'type' => ['type' => 'string'],
                    ],
                    'required' => ['tenantFilter'],
                ],
                'annotations' => ['readOnlyHint' => true],
                'read_only' => true,
                'sensitive' => false,
                'active' => true,
                'last_seen_at' => now(),
            ]);
        }

        return $toolNames;
    }

    private function listTools(string $token): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ]);
    }
}
