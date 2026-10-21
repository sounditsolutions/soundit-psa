<?php

namespace Tests\Feature\Carbon;

use App\Models\CarbonMcpTool;
use App\Models\McpAuditLog;
use App\Models\Setting;
use App\Services\Carbon\CarbonMcpCatalogSyncService;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use App\Support\McpToolSurface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Carbon tools on the staff MCP surface (card PEYvh4NL): published through
 * McpToolSurface while enabled + connected, callable only with an explicit
 * per-token grant, no extra approval gate, one audit row per call that holds
 * no Carbon arguments or results.
 */
class CarbonMcpToolsTest extends TestCase
{
    use FakesCarbonMcp;
    use RefreshDatabase;

    /** @var array<int, array{level: string, message: string, context: array}> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        $this->seedConnected();
        Http::fake($this->tokenFake() + $this->mcpFake());
        app(CarbonMcpCatalogSyncService::class)->sync();
        McpToolRegistry::flushMemoized();
        Log::listen(function ($event): void {
            $this->logs[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
        });
    }

    public function test_every_synced_tool_is_in_the_grant_catalog_under_the_carbon_card_with_its_tier(): void
    {
        $groups = McpToolRegistry::groups();
        $integrationReads = array_column($groups['integration']['tools'], 'name');
        $writes = array_column($groups['carbon_write']['tools'], 'name');

        $this->assertContains('carbon_search_products_example', $integrationReads);
        $this->assertContains('carbon_get_quote_example', $integrationReads);
        $this->assertEqualsCanonicalizing(
            ['carbon_create_draft_order_example', 'carbon_update_company_example', 'carbon_list_customers_example', 'carbon_configure_product_example'],
            $writes,
        );
        $this->assertTrue($groups['carbon_write']['sensitive']);

        $card = McpToolRegistry::integrationGroups()['carbon'];
        $this->assertSame(6, $card['total']);
        $this->assertSame(4, $card['sensitive_count']);
        $this->assertSame('carbon', McpToolRegistry::integrationForToolName('carbon_anything'));
    }

    public function test_a_granted_token_lists_and_calls_a_read_tool_and_one_audit_row_is_written(): void
    {
        $token = McpConfig::rotateStaffToken(['carbon_search_products_example'], 'chet');

        $listed = collect($this->listTools($token))->keyBy('name');
        $this->assertTrue($listed->has('carbon_search_products_example'));
        $this->assertFalse($listed->has('carbon_create_draft_order_example'), 'ungranted Carbon tools are not listed');
        $this->assertArrayNotHasKey('client_id', (array) $listed['carbon_search_products_example']['inputSchema']['properties']);

        $response = $this->callTool($token, 'carbon_search_products_example', ['query' => 'laptop 16GB']);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $result = json_decode((string) $response->json('result.content.0.text'), true);
        $this->assertSame('search_products_example', $result['upstream_tool']);
        $this->assertStringContainsString('=== UNTRUSTED CARBON TOOL RESULT', $result['result']);
        $this->assertStringContainsString(self::CUSTOMER_CANARY, $result['result']);

        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.carbonsys.com/mcp'
            && $r['method'] === 'tools/call'
            && $r['params']['name'] === 'search_products_example'
            && $r['params']['arguments'] === ['query' => 'laptop 16GB']);

        $audits = McpAuditLog::where('tool_name', 'carbon_search_products_example')->get();
        $this->assertCount(1, $audits);
        $this->assertSame('success', $audits[0]->status);
        $this->assertSame(['argument_keys' => ['query']], $audits[0]->arguments);
        $this->assertNotNull($audits[0]->duration_ms);
        $this->assertStringNotContainsString('laptop 16GB', (string) json_encode($audits[0]->toArray()));
        $this->assertStringNotContainsString(self::CUSTOMER_CANARY, (string) json_encode($audits[0]->toArray()));
        $this->assertStringNotContainsString(self::CUSTOMER_CANARY, (string) json_encode($this->logs));
    }

    public function test_a_granted_write_tool_runs_with_no_extra_approval_gate(): void
    {
        $token = McpConfig::rotateStaffToken(['carbon_create_draft_order_example'], 'chet');

        $response = $this->callTool($token, 'carbon_create_draft_order_example', [
            'company_id' => 'co-example-1',
            'lines' => [['sku' => 'SKU-EXAMPLE', 'qty' => 2]],
        ]);

        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.carbonsys.com/mcp'
            && $r['method'] === 'tools/call'
            && $r['params']['name'] === 'create_draft_order_example');
        $audit = McpAuditLog::where('tool_name', 'carbon_create_draft_order_example')->sole();
        $this->assertSame(['argument_keys' => ['company_id', 'lines']], $audit->arguments);
        $this->assertStringNotContainsString('co-example-1', (string) json_encode($audit->toArray()));
    }

    public function test_an_ungranted_tool_is_refused_and_nothing_reaches_carbon(): void
    {
        $token = McpConfig::rotateStaffToken(['carbon_search_products_example'], 'chet');

        $response = $this->callTool($token, 'carbon_create_draft_order_example', ['company_id' => 'co-secret-77', 'lines' => []]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringStartsWith('Tool not allowed for this token: carbon_create_draft_order_example', (string) $response->json('result.content.0.text'));
        $this->assertNoToolsCallSent();
        $audit = McpAuditLog::where('tool_name', 'carbon_create_draft_order_example')->sole();
        $this->assertSame('error', $audit->status);
        $this->assertStringNotContainsString('co-secret-77', (string) json_encode($audit->toArray()));
    }

    public function test_the_legacy_full_surface_token_gets_no_carbon_tool(): void
    {
        $token = McpConfig::rotateStaffToken(null);

        $this->assertEmpty(array_filter(array_column($this->listTools($token), 'name'), fn ($n) => str_starts_with((string) $n, 'carbon_')));
        $response = $this->callTool($token, 'carbon_search_products_example', ['query' => 'x']);
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringStartsWith('Tool not allowed for this token: carbon_search_products_example', (string) $response->json('result.content.0.text'));
        $this->assertNoToolsCallSent();
    }

    public function test_off_means_off_switched_off_or_signed_out_tools_are_not_live_and_not_callable(): void
    {
        $token = McpConfig::rotateStaffToken(['carbon_search_products_example'], 'chet');
        $granted = fn (string $t): bool => $t === 'carbon_search_products_example';

        $this->assertSame('granted', McpToolSurface::classifyNames(['carbon_search_products_example'], $granted)['carbon_search_products_example']);

        Setting::setValue('carbon_mcp_enabled', '0');
        $this->assertSame('unavailable_config', McpToolSurface::classifyNames(['carbon_search_products_example'], $granted)['carbon_search_products_example']);
        $this->assertNotContains('carbon_search_products_example', array_column($this->listTools($token), 'name'));
        $off = $this->callTool($token, 'carbon_search_products_example', ['query' => 'x']);
        $this->assertTrue((bool) $off->json('result.isError'));
        $this->assertStringStartsWith('Tool not allowed for this token: carbon_search_products_example', (string) $off->json('result.content.0.text'));

        Setting::setValue('carbon_mcp_enabled', '1');
        Setting::where('key', 'carbon_mcp_refresh_token')->delete();
        $this->assertSame('unavailable_config', McpToolSurface::classifyNames(['carbon_search_products_example'], $granted)['carbon_search_products_example']);
        $this->assertNoToolsCallSent();
    }

    public function test_a_tool_carbon_dropped_stops_being_callable_after_the_sync(): void
    {
        $token = McpConfig::rotateStaffToken(['carbon_configure_product_example'], 'chet');
        $this->carbonFirstPage = 'tools-list-after.json';
        app(CarbonMcpCatalogSyncService::class)->sync();

        $this->assertNotContains('carbon_configure_product_example', array_column($this->listTools($token), 'name'));
        $dropped = $this->callTool($token, 'carbon_configure_product_example', ['sku' => 'x']);
        $this->assertStringStartsWith('Tool not allowed for this token: carbon_configure_product_example', (string) $dropped->json('result.content.0.text'));
        $this->assertNoToolsCallSent();
    }

    public function test_client_id_and_undeclared_arguments_are_refused_before_carbon(): void
    {
        $token = McpConfig::rotateStaffToken(['carbon_search_products_example'], 'chet');

        $withClient = $this->callTool($token, 'carbon_search_products_example', ['query' => 'x', 'client_id' => 5]);
        $this->assertTrue((bool) $withClient->json('result.isError'));
        $this->assertStringContainsString('client_id must be omitted', (string) $withClient->json('result.content.0.text'));

        $undeclared = $this->callTool($token, 'carbon_search_products_example', ['query' => 'x', 'payment_method' => 'card']);
        $this->assertTrue((bool) $undeclared->json('result.isError'));
        $this->assertStringContainsString('payment_method', (string) $undeclared->json('result.content.0.text'));

        $missing = $this->callTool($token, 'carbon_search_products_example', []);
        $this->assertTrue((bool) $missing->json('result.isError'));
        $this->assertStringContainsString('query', (string) $missing->json('result.content.0.text'));

        $this->assertNoToolsCallSent();
        $this->assertSame(3, McpAuditLog::where('tool_name', 'carbon_search_products_example')->where('status', 'error')->count());
    }

    public function test_a_carbon_tool_error_is_returned_fenced_and_the_audit_row_holds_only_psa_text(): void
    {
        $token = McpConfig::rotateStaffToken(['carbon_get_quote_example'], 'chet');
        $this->carbonCallResult = [
            'content' => [['type' => 'text', 'text' => 'Quote not found for '.self::CUSTOMER_CANARY]],
            'isError' => true,
        ];

        $response = $this->callTool($token, 'carbon_get_quote_example', ['quote_id' => 'Q-1']);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString(self::CUSTOMER_CANARY, (string) $response->json('result.content.0.text'));
        $audit = McpAuditLog::where('tool_name', 'carbon_get_quote_example')->sole();
        $this->assertSame('error', $audit->status);
        $this->assertSame('Carbon reported an error for getQuoteExample; its text is in carbon_error.', $audit->error_message);
        $this->assertStringNotContainsString(self::CUSTOMER_CANARY, (string) json_encode($audit->toArray()));
        $this->assertStringNotContainsString(self::CUSTOMER_CANARY, (string) json_encode($this->logs));
    }

    public function test_a_transport_failure_audits_and_logs_status_only(): void
    {
        $token = McpConfig::rotateStaffToken(['carbon_get_quote_example'], 'chet');
        $this->carbonMcpDown = 502;

        $response = $this->callTool($token, 'carbon_get_quote_example', ['quote_id' => 'Q-1']);

        $this->assertTrue((bool) $response->json('result.isError'));
        $audit = McpAuditLog::where('tool_name', 'carbon_get_quote_example')->sole();
        $this->assertStringStartsWith('Carbon tool call failed: Carbon MCP initialize failed: HTTP 502.', (string) $audit->error_message);
        $this->assertStringNotContainsString(self::CUSTOMER_CANARY, (string) json_encode($audit->toArray()));
        $this->assertStringNotContainsString(self::CUSTOMER_CANARY, (string) json_encode($this->logs));
        $this->assertTrue(collect($this->logs)->contains(fn ($l) => ($l['context']['error'] ?? null) === 'Carbon MCP initialize failed: HTTP 502'));
    }

    private function assertNoToolsCallSent(): void
    {
        Http::assertNotSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.carbonsys.com/mcp' && ($r->data()['method'] ?? null) === 'tools/call');
    }

    private function listTools(string $token): array
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []])
            ->assertOk()
            ->json('result.tools') ?? [];
    }

    private function callTool(string $token, string $name, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }
}
