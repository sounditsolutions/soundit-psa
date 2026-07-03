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
        $this->assertLessThanOrEqual(25, count($queries), $sql);
    }

    public function test_tools_list_repairs_dynamic_cipp_schema_before_publishing(): void
    {
        CippMcpTool::create([
            'local_name' => 'cipp_bad_but_repairable_schema',
            'upstream_name' => 'ListBadButRepairable',
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

        $token = McpConfig::rotateStaffToken(['cipp_bad_but_repairable_schema'], 'chet');

        $response = $this->listTools($token);

        $response->assertOk();
        $tool = collect($response->json('result.tools'))->firstWhere('name', 'cipp_bad_but_repairable_schema');
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
