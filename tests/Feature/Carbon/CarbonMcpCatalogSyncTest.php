<?php

namespace Tests\Feature\Carbon;

use App\Models\CarbonMcpTool;
use App\Models\Setting;
use App\Models\User;
use App\Services\Carbon\CarbonMcpCatalogSyncService;
use App\Services\Carbon\CarbonMcpClient;
use App\Services\Carbon\CarbonMcpConnector;
use App\Services\Carbon\CarbonMcpException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The MCP client (initialize, paginated tools/list, error paths) and the
 * catalogue sync (add, update annotations, deactivate) for Carbon Connect
 * (card PEYvh4NL), on the recorded metadata plus the synthetic tools/list pages.
 */
class CarbonMcpCatalogSyncTest extends TestCase
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
        Log::listen(function ($event): void {
            $this->logs[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
        });
    }

    public function test_list_tools_initializes_carries_the_session_and_follows_next_cursor(): void
    {
        Http::fake($this->tokenFake() + $this->mcpFake());

        $tools = app(CarbonMcpClient::class)->listTools();

        $this->assertSame(
            ['search_products_example', 'getQuoteExample', 'create_draft_order_example', 'update_company_example', 'list_customers_example', 'configure_product_example'],
            array_column($tools, 'name'),
        );
        $mcp = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (HttpRequest $r): bool => $r->url() === 'https://api.carbonsys.com/mcp')->values();
        $this->assertSame(['initialize', 'notifications/initialized', 'tools/list', 'tools/list'], $mcp->map(fn (HttpRequest $r) => $r['method'])->all());
        $this->assertSame('page-2', $mcp[3]['params']['cursor']);
        foreach ($mcp->slice(1) as $r) {
            $this->assertSame('sess-example-1', $r->header('Mcp-Session-Id')[0] ?? null);
            $this->assertSame('Bearer '.self::ACCESS, $r->header('Authorization')[0] ?? null);
        }
    }

    public function test_sync_adds_every_tool_and_maps_annotations_exactly_as_cipp(): void
    {
        Http::fake($this->tokenFake() + $this->mcpFake());

        $result = app(CarbonMcpCatalogSyncService::class)->sync();

        $this->assertSame([6, 6, 6, 0, 0], [$result->seen, $result->active, $result->created, $result->updated, $result->deactivated]);
        $rows = CarbonMcpTool::query()->orderBy('local_name')->get()->keyBy('upstream_name');
        $this->assertSame('carbon_search_products_example', $rows['search_products_example']->local_name);
        $this->assertSame('carbon_get_quote_example', $rows['getQuoteExample']->local_name);

        // readOnlyHint === true is read_only and not sensitive; everything else is a sensitive write.
        $expect = [
            'search_products_example' => [true, false],
            'getQuoteExample' => [true, false],
            'create_draft_order_example' => [false, true],
            'update_company_example' => [false, true],   // destructiveHint, no readOnlyHint
            'list_customers_example' => [false, true],   // no annotations at all
            'configure_product_example' => [false, true], // readOnlyHint "true" is a string, not true
        ];
        foreach ($expect as $upstream => [$readOnly, $sensitive]) {
            $this->assertSame($readOnly, $rows[$upstream]->read_only, $upstream.' read_only');
            $this->assertSame($sensitive, $rows[$upstream]->sensitive, $upstream.' sensitive');
            $this->assertTrue($rows[$upstream]->active, $upstream.' active');
        }
        $this->assertSame(['query'], $rows['search_products_example']->input_schema['required']);
        $this->assertNotNull(Setting::getValue(CarbonMcpConnector::LAST_SYNC_AT));
        $this->assertStringContainsString('6 created', (string) Setting::getValue(CarbonMcpConnector::LAST_SYNC_SUMMARY));
    }

    public function test_the_mapping_is_the_cipp_policy_for_every_annotation_shape(): void
    {
        foreach ([
            [['readOnlyHint' => true], true],
            [['readOnlyHint' => true, 'destructiveHint' => true], true],
            [['readOnlyHint' => false], false],
            [['readOnlyHint' => 'true'], false],
            [['readOnlyHint' => 1], false],
            [['destructiveHint' => false], false],
            [[], false],
            [null, false],
        ] as [$annotations, $readOnly]) {
            // CippMcpCatalogSyncService::catalogRows(): ($tool['annotations']['readOnlyHint'] ?? null) === true
            $cipp = (($annotations['readOnlyHint'] ?? null) === true);
            $this->assertSame($cipp, $readOnly);
            $this->assertSame(['read_only' => $readOnly, 'sensitive' => ! $readOnly], CarbonMcpTool::classify($annotations), json_encode($annotations));
        }
    }

    public function test_a_later_sync_updates_annotations_and_schema_and_deactivates_tools_carbon_dropped(): void
    {
        Http::fake($this->tokenFake() + $this->mcpFake());
        app(CarbonMcpCatalogSyncService::class)->sync();

        $this->carbonFirstPage = 'tools-list-after.json';
        $result = app(CarbonMcpCatalogSyncService::class)->sync(app(CarbonMcpClient::class));

        $this->assertSame([5, 5, 0, 2, 1], [$result->seen, $result->active, $result->created, $result->updated, $result->deactivated]);
        $rows = CarbonMcpTool::query()->get()->keyBy('upstream_name');
        // getQuoteExample lost readOnlyHint: true, so it is now a sensitive write.
        $this->assertFalse($rows['getQuoteExample']->read_only);
        $this->assertTrue($rows['getQuoteExample']->sensitive);
        $this->assertSame(['readOnlyHint' => false, 'destructiveHint' => false], $rows['getQuoteExample']->annotations);
        $this->assertArrayHasKey('category', $rows['search_products_example']->input_schema['properties']);
        // configure_product_example disappeared: deactivated, not deleted.
        $this->assertFalse($rows['configure_product_example']->active);
        $this->assertSame(6, CarbonMcpTool::count());

        // ...and comes back on the next sync that lists it.
        $this->carbonFirstPage = 'tools-list-page-1.json';
        $again = app(CarbonMcpCatalogSyncService::class)->sync(app(CarbonMcpClient::class));
        $this->assertSame(0, $again->deactivated);
        $this->assertTrue(CarbonMcpTool::where('upstream_name', 'configure_product_example')->value('active'));
    }

    public function test_a_failed_tools_list_changes_nothing_and_reports_the_status_only(): void
    {
        Http::fake($this->tokenFake() + $this->mcpFake());
        app(CarbonMcpCatalogSyncService::class)->sync();

        $this->carbonMcpDown = 503;
        try {
            app(CarbonMcpCatalogSyncService::class)->sync(app(CarbonMcpClient::class));
            $this->fail('expected CarbonMcpException');
        } catch (CarbonMcpException $e) {
            $this->assertSame('Carbon MCP initialize failed: HTTP 503', $e->getMessage());
        }

        $this->assertSame(6, CarbonMcpTool::where('active', true)->count());
    }

    public function test_a_json_rpc_error_reports_its_code_never_its_message(): void
    {
        Http::fake($this->tokenFake() + ['https://api.carbonsys.com/mcp' => fn (HttpRequest $r) => $r['method'] === 'initialize'
            ? Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-06-18']], 200)
            : Http::response(['jsonrpc' => '2.0', 'id' => $r['id'] ?? null, 'error' => ['code' => -32603, 'message' => 'failed for '.self::CUSTOMER_CANARY]], 200)]);

        try {
            app(CarbonMcpClient::class)->listTools();
            $this->fail('expected CarbonMcpException');
        } catch (CarbonMcpException $e) {
            $this->assertSame('Carbon MCP tools/list returned JSON-RPC error -32603', $e->getMessage());
        }
    }

    public function test_an_unfinished_pagination_is_refused_rather_than_read_as_disappearance(): void
    {
        Http::fake($this->tokenFake() + ['https://api.carbonsys.com/mcp' => fn (HttpRequest $r) => match ($r['method']) {
            'initialize' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-06-18']], 200),
            'notifications/initialized' => Http::response('', 202),
            default => Http::response(['jsonrpc' => '2.0', 'id' => $r['id'], 'result' => ['tools' => [], 'nextCursor' => 'again']], 200),
        }]);
        CarbonMcpTool::create(['local_name' => 'carbon_keep_me', 'upstream_name' => 'keep_me', 'read_only' => true, 'sensitive' => false, 'active' => true]);

        $this->expectException(CarbonMcpException::class);
        try {
            app(CarbonMcpCatalogSyncService::class)->sync();
        } finally {
            $this->assertTrue(CarbonMcpTool::where('upstream_name', 'keep_me')->value('active'));
        }
    }

    public function test_a_401_retries_once_with_a_fresh_token_then_raises_an_auth_error(): void
    {
        Http::fake($this->tokenFake() + ['https://api.carbonsys.com/mcp' => Http::response('', 401)]);

        try {
            app(CarbonMcpClient::class)->listTools();
            $this->fail('expected an auth exception');
        } catch (\App\Services\Carbon\CarbonMcpAuthException $e) {
            $this->assertSame('Carbon MCP initialize was refused: HTTP 401', $e->getMessage());
        }
        $this->assertCount(2, collect(Http::recorded())->filter(fn ($p) => $p[0]->url() === 'https://api.carbonsys.com/mcp'));
    }

    public function test_the_command_syncs_and_the_schedule_runs_only_while_enabled_and_connected(): void
    {
        Http::fake($this->tokenFake() + $this->mcpFake());

        $this->artisan('carbon:sync-mcp-catalog')->expectsOutputToContain('6 created')->assertSuccessful();
        $this->assertSame(6, CarbonMcpTool::count());

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'carbon:sync-mcp-catalog'));
        $this->assertNotNull($event);
        $this->assertTrue($event->filtersPass($this->app));
        Setting::setValue('carbon_mcp_enabled', '0');
        $this->assertFalse($event->filtersPass($this->app));
        Setting::setValue('carbon_mcp_enabled', '1');
        Setting::where('key', CarbonMcpConnector::REFRESH_TOKEN_SETTING)->delete();
        $this->assertFalse($event->filtersPass($this->app));
        $this->artisan('carbon:sync-mcp-catalog')->assertFailed();
    }

    public function test_sync_now_button_is_admin_only_and_reports_the_summary(): void
    {
        Http::fake($this->tokenFake() + $this->mcpFake());

        $this->actingAs(User::factory()->tech()->create())->post(route('settings.integrations.carbon.sync-mcp-catalog'))->assertForbidden();
        $this->assertSame(0, CarbonMcpTool::count());

        $this->actingAs(User::factory()->admin()->create())->post(route('settings.integrations.carbon.sync-mcp-catalog'))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('success', fn (string $m): bool => str_contains($m, '6 seen'));
        $this->assertSame(6, CarbonMcpTool::count());
        $this->assertStringNotContainsString(self::CUSTOMER_CANARY, (string) json_encode($this->logs));
    }
}
