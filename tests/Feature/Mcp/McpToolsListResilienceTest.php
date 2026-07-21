<?php

namespace Tests\Feature\Mcp;

use App\Http\Controllers\Api\McpStaffController;
use App\Models\CippMcpTool;
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

        // Budget raised 25 -> 33 by psa-wzjzz, deliberately and with the trade stated.
        //
        // The six vendor availability predicates now consult their master switch
        // (Setting::getValue is uncached, one query per read), which costs ~6 more queries
        // on this path than the old credentials-only checks did. In exchange the Ninja and
        // Level lanes no longer make a LIVE HTTP round-trip to decide availability, so the
        // path trades two network calls for six single-row indexed reads on a tiny table.
        //
        // The increase is CONSTANT, not proportional to the catalog: this fixture builds 214
        // dynamic CIPP tools and the count moved by a fixed 6, so it is not an N+1. The sharp
        // assertion above — the dynamic CIPP catalog staying at <= 3 queries, which is the
        // actual N+1 this test was written to catch — is untouched and still passing.
        //
        // MEMOIZATION WAS CONSIDERED AND REJECTED. McpToolRegistry::memoized() would collapse
        // these reads, but it keys its reset on spl_object_id(app('request')), which never
        // changes in a long-running queue worker — the memo would never reset. Caching the
        // answer to "is this integration switched off?" in a daemon that runs triage is how
        // you reintroduce exactly the defect this bead fixes: an operator flips the switch
        // off and the worker keeps publishing the vendor's tools until someone restarts it.
        // A few indexed reads are the cheaper mistake.
        $this->assertLessThanOrEqual(33, count($queries), $sql);
    }

    public function test_tools_list_repairs_dynamic_cipp_schema_before_publishing(): void
    {
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
