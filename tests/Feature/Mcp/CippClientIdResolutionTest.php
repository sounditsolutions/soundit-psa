<?php

namespace Tests\Feature\Mcp;

use App\Models\CippMcpTool;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Cipp\CippClient;
use App\Services\Cipp\CippMcpClient;
use App\Services\Cipp\CippTenantScope;
use App\Services\Triage\TriageToolDefinitions;
use App\Services\Triage\TriageToolExecutor;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * Card 6abdcac2, second integration: CIPP read tools take client_id and resolve the
 * tenant through the client's stored mapping (clients.cipp_tenant_domain), the
 * Huntress pattern from #4486. Synthetic clients only: Alpha (alpha.example), Bravo
 * (bravo.example) and Unmapped. Every transport is a fake that records what would
 * have been sent upstream; no vendor is called.
 */
class CippClientIdResolutionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    private array $sent = [];

    /**
     * CIPP's tenant list as REST ListTenants answers the resolver's alias check (null:
     * the read throws). Synthetic rows in the Get-Tenants shape.
     *
     * @var array<int|string, mixed>|null
     */
    private ?array $tenantList = [
        ['customerId' => '11111111-1111-1111-1111-111111111111', 'defaultDomainName' => 'alpha.example', 'initialDomainName' => 'alpha.onmicrosoft.example', 'displayName' => 'Alpha'],
        ['customerId' => '22222222-2222-2222-2222-222222222222', 'defaultDomainName' => 'bravo.example', 'initialDomainName' => 'bravo.onmicrosoft.example', 'displayName' => 'Bravo'],
    ];

    private Client $alpha;

    private Client $bravo;

    private Client $unmapped;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = Client::factory()->create(['name' => 'Alpha', 'cipp_tenant_domain' => 'alpha.example']);
        $this->bravo = Client::factory()->create(['name' => 'Bravo', 'cipp_tenant_domain' => 'bravo.example']);
        $this->unmapped = Client::factory()->create(['name' => 'Unmapped', 'cipp_tenant_domain' => null]);
    }

    private function configure(bool $relay): void
    {
        Setting::setValue('cipp_enabled', '1');
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_client_id', 'rest-client');
        Setting::setEncrypted('cipp_client_secret', 'rest-secret');
        Setting::setValue('cipp_mcp_client_id', 'mcp-client');
        Setting::setEncrypted('cipp_mcp_client_secret', 'mcp-secret');
        Setting::setValue('cipp_mcp_enabled', $relay ? '1' : '0');
    }

    /**
     * Record-only fakes for BOTH transports; $rows is what CIPP "answers". The
     * ListTenants read the resolver makes for its alias check (REST, or MCP when the
     * REST read fails and the relay is on) answers $tenantList and is kept out of
     * $sent: it carries no tenant.
     */
    private function fakeTransports(array $rows = [['id' => 'row-1', 'displayName' => 'Row']]): void
    {
        $mcp = Mockery::mock(CippMcpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(function (string $tool, array $args) use ($rows): array {
            if ($tool === 'ListTenants' && $args === []) {
                if ($this->tenantList === null) {
                    throw new \RuntimeException('CIPP MCP ListTenants failed: synthetic outage');
                }

                return $this->tenantList;
            }
            $this->sent[] = [$tool, $args];

            return $rows;
        });
        $this->app->instance(CippMcpClient::class, $mcp);

        $rest = Mockery::mock(CippClient::class);
        $rest->shouldReceive('get')->andReturnUsing(function (string $endpoint, array $query = []) use ($rows): array {
            if ($endpoint === 'api/ListTenants') {
                if ($this->tenantList === null) {
                    throw new \RuntimeException('CIPP API error: synthetic outage');
                }

                return $this->tenantList;
            }
            $this->sent[] = [$endpoint, $query];

            return $rows;
        });
        $this->app->instance(CippClient::class, $rest);
    }

    /** @param  array<string, mixed>  $props */
    private function catalogTool(string $local, string $upstream, array $props): void
    {
        CippMcpTool::create([
            'local_name' => $local,
            'upstream_name' => $upstream,
            'category' => 'CIPP',
            'description' => "[CIPP] {$upstream}.",
            'input_schema' => ['type' => 'object', 'properties' => $props, 'required' => array_keys($props) === [] ? [] : ['tenantFilter']],
            'annotations' => ['readOnlyHint' => true],
            'read_only' => true,
            'sensitive' => false,
            'active' => true,
            'last_seen_at' => now(),
        ]);
        McpToolRegistry::flushMemoized();
    }

    /** The prod catalog's tenant-bearing rows, with their vendor parameter names. */
    private function prodCatalog(): void
    {
        $this->catalogTool('cipp_list_graph_request', 'ListGraphRequest', [
            'tenantFilter' => ['type' => 'string'], 'Endpoint' => ['type' => 'string'], 'QueueId' => ['type' => 'string'],
            'ReverseTenantLookup' => ['type' => 'string'], 'ReverseTenantLookupProperty' => ['type' => 'string'], 'odata_select' => ['type' => 'string'],
        ]);
        $this->catalogTool('cipp_list_tenants', 'ListTenants', [
            'AllTenantSelector' => ['type' => 'string'], 'Mode' => ['type' => 'string'], 'TenantFilter' => ['type' => 'string'], 'TenantsOnly' => ['type' => 'string'],
        ]);
        $this->catalogTool('cipp_exec_tool', 'ExecTool', ['name' => ['type' => 'string'], 'arguments' => ['type' => 'object']]);
        $this->catalogTool('cipp_search_tools', 'SearchTools', ['query' => ['type' => 'string']]);
    }

    private function token(array $tools): string
    {
        return McpConfig::rotateStaffToken($tools, 'cipp-clientid');
    }

    private function mcpCall(string $token, string $name, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function listTools(string $token): array
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []])
            ->assertOk()
            ->json('result.tools') ?? [];
    }

    private function text(TestResponse $response): string
    {
        return (string) $response->json('result.content.0.text');
    }

    /** @return array<int, string> every tenant value that reached a transport */
    private function tenantsSent(): array
    {
        $tenants = [];
        foreach ($this->sent as [, $args]) {
            array_walk_recursive($args, function (mixed $value, mixed $key) use (&$tenants): void {
                if (is_string($key) && CippTenantScope::isTenantSelectorKey($key) && is_scalar($value)) {
                    $tenants[] = (string) $value;
                }
            });
        }

        return $tenants;
    }

    // ── Curated cipp_list_* reads (relay and REST) ──

    /** @return array<string, array{0: bool}> */
    public static function transports(): array
    {
        return ['mcp relay' => [true], 'rest direct' => [false]];
    }

    /** @dataProvider transports */
    public function test_a_mapped_client_resolves_its_tenant_through_the_stored_key(bool $relay): void
    {
        $this->configure($relay);
        $this->fakeTransports();
        $token = $this->token(['cipp_list_users', 'cipp_list_conditional_access_policies']);

        foreach (['cipp_list_users', 'cipp_list_conditional_access_policies'] as $tool) {
            $response = $this->mcpCall($token, $tool, ['client_id' => $this->alpha->id]);
            $this->assertFalse((bool) $response->json('result.isError'), $this->text($response));
        }

        $this->assertCount(2, $this->sent);
        $this->assertSame(['alpha.example', 'alpha.example'], $this->tenantsSent());
    }

    /** @dataProvider transports */
    public function test_an_unmapped_client_fails_closed_before_any_upstream_call(bool $relay): void
    {
        $this->configure($relay);
        $this->fakeTransports();
        $token = $this->token(['cipp_list_users']);

        $response = $this->mcpCall($token, 'cipp_list_users', ['client_id' => $this->unmapped->id]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString("PSA client {$this->unmapped->id} is not mapped to CIPP", $this->text($response));
        $this->assertStringContainsString("does not search other clients' tenants", $this->text($response));
        $this->assertSame([], $this->sent);
    }

    /** @dataProvider transports */
    public function test_a_tenant_mapped_to_two_clients_is_ambiguous_and_fails_closed(bool $relay): void
    {
        $this->configure($relay);
        $this->fakeTransports();
        // Another client carries Alpha's key with different case and padding: the same
        // tenant to CIPP (Get-Tenants matches domains case-insensitively).
        Client::factory()->create(['name' => 'Charlie', 'cipp_tenant_domain' => ' ALPHA.example ']);
        $token = $this->token(['cipp_list_users']);

        $response = $this->mcpCall($token, 'cipp_list_users', ['client_id' => $this->alpha->id]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('also mapped to another PSA client', $this->text($response));
        $this->assertSame([], $this->sent);
    }

    /** @dataProvider transports */
    public function test_client_a_with_client_bs_tenant_typed_by_the_agent_is_refused(bool $relay): void
    {
        $this->configure($relay);
        $this->fakeTransports();
        $token = $this->token(['cipp_list_users']);

        foreach (['tenantFilter', 'TenantFilter', 'tenant'] as $key) {
            $response = $this->mcpCall($token, 'cipp_list_users', ['client_id' => $this->alpha->id, $key => 'bravo.example']);
            $this->assertTrue((bool) $response->json('result.isError'), $key);
            $this->assertStringContainsString($key, $this->text($response));
        }

        $this->assertSame([], $this->sent);
    }

    public function test_the_executors_resolve_through_the_same_key_and_refuse_a_foreign_client_object(): void
    {
        $this->configure(false);
        $this->fakeTransports();

        $alphaTicket = Ticket::factory()->create(['client_id' => $this->alpha->id]);
        $unmappedTicket = Ticket::factory()->create(['client_id' => $this->unmapped->id]);

        $this->assertArrayNotHasKey('error', (new TriageToolExecutor($alphaTicket))->execute('cipp_list_users', []));
        $this->assertArrayNotHasKey('error', (new AssistantToolExecutor(null, $this->alpha->id, null))->execute('cipp_list_users', []));
        $this->assertSame(['alpha.example', 'alpha.example'], $this->tenantsSent());

        $this->sent = [];
        $triage = (new TriageToolExecutor($unmappedTicket))->execute('cipp_list_users', []);
        $assistant = (new AssistantToolExecutor(null, $this->unmapped->id, null))->execute('cipp_list_users', []);
        $this->assertStringContainsString('is not mapped to CIPP', (string) ($triage['error'] ?? ''));
        $this->assertStringContainsString('is not mapped to CIPP', (string) ($assistant['error'] ?? ''));
        $this->assertSame([], $this->sent);

        // The resolver is bound to the client_id, not to whichever Client object it is
        // handed. A record that is not client_id's client is refused even when its
        // tenant is mapped to nobody else (so the shared-mapping check cannot be what
        // refuses it), and Bravo's record under Alpha's id is refused too.
        $this->assertSame('alpha.example', CippTenantScope::resolve($this->alpha, $this->alpha->id));
        $stray = new Client(['name' => 'Stray', 'cipp_tenant_domain' => 'zulu.example']);
        $this->assertSame("PSA client {$this->alpha->id} was not found.", CippTenantScope::resolve($stray, $this->alpha->id)['error'] ?? null);
        $this->assertArrayHasKey('error', (array) CippTenantScope::resolve($this->bravo, $this->alpha->id));
        $this->assertArrayHasKey('error', (array) CippTenantScope::resolve($this->alpha, null));
    }

    /**
     * The relay re-resolves through the same CippTenantScope rather than trusting its
     * caller (defence in depth: the MCP surface reaches it only after cippDispatch()
     * already resolved, so this is asserted on the relay directly).
     */
    public function test_the_mcp_relay_itself_refuses_an_unmapped_or_shared_client_without_calling_cipp(): void
    {
        $mcp = Mockery::mock(CippMcpClient::class);
        $mcp->shouldNotReceive('callTool');
        $relay = new \App\Services\Cipp\CippMcpToolRelay($mcp, app(\App\Services\Chet\ChetDataSurfaceTextSanitizer::class));

        $unmapped = $relay->execute('cipp_list_users', [], $this->unmapped, $this->unmapped->id);
        $this->assertStringContainsString('is not mapped to CIPP', (string) ($unmapped['error'] ?? ''));

        Client::factory()->create(['name' => 'Delta', 'cipp_tenant_domain' => 'Alpha.Example']);
        $shared = $relay->execute('cipp_list_users', [], $this->alpha, $this->alpha->id);
        $this->assertStringContainsString('also mapped to another PSA client', (string) ($shared['error'] ?? ''));
    }

    public function test_every_curated_cipp_read_describes_client_id_as_the_key_with_no_name_fallback(): void
    {
        $definitions = TriageToolDefinitions::cippTools();
        $this->assertCount(19, $definitions);
        foreach ($definitions as $tool) {
            $this->assertStringContainsString(CippTenantScope::KEY_NOTE, (string) $tool['description'], $tool['name']);
        }
        $this->assertStringContainsString('client_id is the primary key', CippTenantScope::KEY_NOTE);
        $this->assertStringContainsString('there is no tenant-name fallback', CippTenantScope::KEY_NOTE);

        $this->configure(false);
        $listed = collect($this->listTools($this->token(['cipp_list_users'])))->firstWhere('name', 'cipp_list_users');
        $this->assertIsArray($listed);
        $this->assertStringContainsString('client_id is the primary key', (string) $listed['description']);
        $this->assertContains('client_id', $listed['inputSchema']['required']);
    }

    /** @return array<string, array{0: bool, 1: string}> */
    public static function aliasDuplicates(): array
    {
        return [
            'relay, initial domain' => [true, 'alpha.onmicrosoft.example'],
            'rest, initial domain' => [false, ' ALPHA.onmicrosoft.example '],
            'relay, customer id' => [true, '11111111-1111-1111-1111-111111111111'],
            'rest, customer id' => [false, '11111111-1111-1111-1111-111111111111'],
        ];
    }

    /**
     * Charlie mapped to one of Alpha's OTHER aliases is Alpha's tenant to CIPP
     * (Get-Tenants matches customerId, defaultDomainName and initialDomainName), so
     * neither client may read it, whichever key each one stores.
     *
     * @dataProvider aliasDuplicates
     */
    public function test_a_tenant_mapped_to_two_clients_under_different_aliases_fails_closed(bool $relay, string $alias): void
    {
        $this->configure($relay);
        $this->fakeTransports();
        $charlie = Client::factory()->create(['name' => 'Charlie', 'cipp_tenant_domain' => $alias]);
        $token = $this->token(['cipp_list_users']);

        foreach ([$this->alpha, $charlie] as $client) {
            $response = $this->mcpCall($token, 'cipp_list_users', ['client_id' => $client->id]);
            $this->assertTrue((bool) $response->json('result.isError'), $client->name);
            $this->assertStringContainsString('also mapped, under another of its domains, to another PSA client', $this->text($response), $client->name);
        }
        $this->assertSame([], $this->sent);

        // Bravo shares no alias with Alpha or Charlie and still reads.
        $bravo = $this->mcpCall($token, 'cipp_list_users', ['client_id' => $this->bravo->id]);
        $this->assertFalse((bool) $bravo->json('result.isError'), $this->text($bravo));
        $this->assertSame(['bravo.example'], $this->tenantsSent());
    }

    /** @return array<string, array{0: bool, 1: array<int|string, mixed>|null}> */
    public static function unusableTenantLists(): array
    {
        // CIPP's answer when it could not list tenants (Invoke-ListTenants.ps1).
        $failed = ['Results' => 'Failed to retrieve tenants', 'defaultDomainName' => '', 'displayName' => 'Failed', 'customerId' => ''];

        return [
            'relay, read throws' => [true, null],
            'rest, read throws' => [false, null],
            'relay, vendor failure row' => [true, [$failed]],
            'rest, vendor failure row' => [false, [$failed]],
            'rest, empty list' => [false, []],
        ];
    }

    /**
     * With another client mapped, the alias check needs CIPP's tenant list. When that
     * cannot be read as tenants (the read throws, as CippClient::get() does on the
     * vendor's failure object, whose Results is a string; or the rows are the vendor's
     * failure row) the read fails closed rather than skipping the check.
     *
     * @dataProvider unusableTenantLists
     */
    public function test_an_unreadable_tenant_list_fails_closed_without_a_tenant_read(bool $relay, ?array $tenantList): void
    {
        $this->configure($relay);
        $this->tenantList = $tenantList;
        $this->fakeTransports();

        $response = $this->mcpCall($this->token(['cipp_list_users']), 'cipp_list_users', ['client_id' => $this->alpha->id]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('could not read a usable CIPP tenant list', $this->text($response));
        $this->assertStringNotContainsString('Check the mapping', $this->text($response));
        $this->assertSame([], $this->sent);
    }

    /**
     * Settings > CIPP Tenants maps a client from CIPP's live tenant list, while the
     * resolver's copy is cached. A tenant added in CIPP after that copy was taken is
     * re-read before the mapping is reported as wrong; a mapping CIPP really does not
     * know still fails closed.
     */
    public function test_a_stale_cached_tenant_list_is_re_read_before_a_new_mapping_is_refused(): void
    {
        $this->configure(false);
        $this->fakeTransports();
        $token = $this->token(['cipp_list_users']);

        // Alpha's read caches CIPP's list as it is now (Alpha and Bravo).
        $alpha = $this->mcpCall($token, 'cipp_list_users', ['client_id' => $this->alpha->id]);
        $this->assertFalse((bool) $alpha->json('result.isError'), $this->text($alpha));

        // Charlie is then onboarded in CIPP and mapped from the live list.
        $this->tenantList[] = ['customerId' => '33333333-3333-3333-3333-333333333333', 'defaultDomainName' => 'charlie.example', 'initialDomainName' => 'charlie.onmicrosoft.example', 'displayName' => 'Charlie'];
        $charlie = Client::factory()->create(['name' => 'Charlie', 'cipp_tenant_domain' => 'charlie.example']);
        $this->sent = [];

        $response = $this->mcpCall($token, 'cipp_list_users', ['client_id' => $charlie->id]);
        $this->assertFalse((bool) $response->json('result.isError'), $this->text($response));
        $this->assertSame(['charlie.example'], $this->tenantsSent());

        $this->sent = [];
        $wrong = Client::factory()->create(['name' => 'Wrong', 'cipp_tenant_domain' => 'nowhere.example']);
        $response = $this->mcpCall($token, 'cipp_list_users', ['client_id' => $wrong->id]);
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('has no tenant matching', $this->text($response));
        $this->assertSame([], $this->sent);
    }

    /**
     * CIPP may run over MCP alone (CippConfig::isMcpRelayEnabled() needs no REST
     * credentials). The alias check then reads ListTenants over MCP, REST is never
     * asked, and a mapped client's dynamic read still runs.
     */
    public function test_an_mcp_only_deployment_reads_the_tenant_list_over_mcp(): void
    {
        Setting::setValue('cipp_enabled', '1');
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_mcp_client_id', 'mcp-client');
        Setting::setEncrypted('cipp_mcp_client_secret', 'mcp-secret');
        Setting::setValue('cipp_mcp_enabled', '1');

        $listReads = 0;
        $mcp = Mockery::mock(CippMcpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(function (string $tool, array $args) use (&$listReads): array {
            if ($tool === 'ListTenants' && $args === []) {
                $listReads++;

                return $this->tenantList;
            }
            $this->sent[] = [$tool, $args];

            return [['id' => 'row-1', 'displayName' => 'Row']];
        });
        $this->app->instance(CippMcpClient::class, $mcp);
        $rest = Mockery::mock(CippClient::class);
        $rest->shouldNotReceive('get');
        $this->app->instance(CippClient::class, $rest);
        $this->prodCatalog();

        $response = $this->mcpCall($this->token(['cipp_list_graph_request']), 'cipp_list_graph_request', ['client_id' => $this->alpha->id, 'Endpoint' => 'users']);

        $this->assertFalse((bool) $response->json('result.isError'), $this->text($response));
        $this->assertSame(1, $listReads);
        $this->assertSame(['alpha.example'], $this->tenantsSent());
    }

    // ── Dynamic catalog tools ──

    public function test_dynamic_graph_request_is_bound_to_the_mapped_tenant_and_unmapped_fails_closed(): void
    {
        $this->configure(true);
        $this->fakeTransports();
        $this->prodCatalog();
        $token = $this->token(['cipp_list_graph_request']);

        $ok = $this->mcpCall($token, 'cipp_list_graph_request', ['client_id' => $this->alpha->id, 'Endpoint' => 'users']);
        $this->assertFalse((bool) $ok->json('result.isError'), $this->text($ok));
        $this->assertSame(['alpha.example'], $this->tenantsSent());

        $this->sent = [];
        $unmapped = $this->mcpCall($token, 'cipp_list_graph_request', ['client_id' => $this->unmapped->id, 'Endpoint' => 'users']);
        $this->assertTrue((bool) $unmapped->json('result.isError'));
        $this->assertStringContainsString('is not mapped to CIPP', $this->text($unmapped));
        $this->assertSame([], $this->sent);
    }

    public function test_dynamic_tools_refuse_every_tenant_selector_and_queue_id_from_the_agent(): void
    {
        $this->configure(true);
        $this->fakeTransports();
        $this->prodCatalog();
        $token = $this->token(['cipp_list_graph_request', 'cipp_list_tenants']);

        $cases = [
            ['cipp_list_graph_request', 'tenantFilter', 'bravo.example'],
            ['cipp_list_graph_request', 'TENANTFILTER', 'bravo.example'],
            ['cipp_list_graph_request', 'QueueId', 'queue-of-bravo'],
            ['cipp_list_graph_request', 'ReverseTenantLookup', 'true'],
            ['cipp_list_tenants', 'AllTenantSelector', 'true'],
            ['cipp_list_tenants', 'TenantsOnly', 'true'],
        ];
        foreach ($cases as [$tool, $key, $value]) {
            $response = $this->mcpCall($token, $tool, ['client_id' => $this->alpha->id, 'Endpoint' => 'users', $key => $value]);
            $this->assertTrue((bool) $response->json('result.isError'), "{$tool} {$key}");
            $this->assertStringContainsString($key, $this->text($response), "{$tool} {$key}");
        }
        $this->assertSame([], $this->sent);

        $published = collect($this->listTools($token))->keyBy('name');
        foreach (['cipp_list_graph_request', 'cipp_list_tenants'] as $tool) {
            $keys = array_keys((array) $published[$tool]['inputSchema']['properties']);
            $this->assertSame([], array_values(array_filter($keys, fn (string $k): bool => $k !== 'client_id' && CippTenantScope::isTenantSelectorKey($k))), $tool);
            $this->assertStringContainsString('client_id is the primary key', (string) $published[$tool]['description'], $tool);
        }
        $this->assertContains('Endpoint', array_keys((array) $published['cipp_list_graph_request']['inputSchema']['properties']));
    }

    public function test_dynamic_tools_refuse_a_tenant_mapped_to_another_client_under_another_alias(): void
    {
        $this->configure(true);
        $this->fakeTransports();
        $this->prodCatalog();
        $charlie = Client::factory()->create(['name' => 'Charlie', 'cipp_tenant_domain' => 'alpha.onmicrosoft.example']);
        $token = $this->token(['cipp_list_graph_request']);

        foreach ([$this->alpha, $charlie] as $client) {
            $response = $this->mcpCall($token, 'cipp_list_graph_request', ['client_id' => $client->id, 'Endpoint' => 'users']);
            $this->assertTrue((bool) $response->json('result.isError'), $client->name);
            $this->assertStringContainsString('also mapped, under another of its domains, to another PSA client', $this->text($response), $client->name);
        }
        $this->assertSame([], $this->sent);
    }

    public function test_list_tenants_returns_only_the_mapped_clients_own_tenant_row(): void
    {
        $this->configure(true);
        // CIPP ignores the tenantFilter we send ListTenants (POST body; it reads the
        // query) and answers every managed tenant. Row shape from Get-Tenants.ps1.
        $this->fakeTransports([
            ['customerId' => '11111111-1111-1111-1111-111111111111', 'defaultDomainName' => 'alpha.example', 'initialDomainName' => 'alpha.onmicrosoft.example', 'displayName' => 'Alpha'],
            ['customerId' => '22222222-2222-2222-2222-222222222222', 'defaultDomainName' => 'bravo.example', 'initialDomainName' => 'bravo.onmicrosoft.example', 'displayName' => 'Bravo'],
            ['customerId' => '33333333-3333-3333-3333-333333333333', 'defaultDomainName' => 'other.example', 'initialDomainName' => 'other.onmicrosoft.example', 'displayName' => 'Other'],
        ]);
        $this->prodCatalog();
        $token = $this->token(['cipp_list_tenants']);

        $response = $this->mcpCall($token, 'cipp_list_tenants', ['client_id' => $this->alpha->id]);

        $this->assertFalse((bool) $response->json('result.isError'), $this->text($response));
        $result = json_decode($this->text($response), true);
        $this->assertSame(1, $result['summary']['count']);
        $this->assertStringNotContainsString('bravo.example', $this->text($response));
        $this->assertStringNotContainsString('other.example', $this->text($response));
        $this->assertStringContainsString('Alpha', $this->text($response));
    }

    /** @return array<string, array{0: array<int|string, mixed>, 1: string}> */
    public static function tenantListFailures(): array
    {
        $alpha = ['customerId' => '11111111-1111-1111-1111-111111111111', 'defaultDomainName' => 'alpha.example', 'initialDomainName' => 'alpha.onmicrosoft.example'];

        return [
            'no row for the mapping' => [[['customerId' => 'x', 'defaultDomainName' => 'other.example']], 'has no tenant matching'],
            'two rows for the mapping' => [[$alpha, ['customerId' => 'y', 'defaultDomainName' => 'other.example', 'initialDomainName' => 'alpha.example']], 'more than one tenant'],
            // CIPP's real answer when it could not list tenants: both keys present, empty.
            'vendor failure row' => [[['Results' => 'Failed to retrieve tenants', 'defaultDomainName' => '', 'displayName' => 'Failed', 'customerId' => '']], 'answered with a row that is not a tenant'],
            'vendor failure object' => [['Results' => 'Failed to retrieve tenants', 'defaultDomainName' => '', 'displayName' => 'Failed', 'customerId' => ''], 'answered with a row that is not a tenant'],
            'tenant also mapped to bravo under its initial domain' => [[array_merge($alpha, ['initialDomainName' => 'bravo.example'])], 'also mapped, under another of its domains'],
        ];
    }

    /** @dataProvider tenantListFailures */
    public function test_list_tenants_fails_closed_when_the_clients_row_is_not_exactly_one_and_its_own(array $rows, string $expected): void
    {
        $this->configure(true);
        $this->fakeTransports($rows);
        $this->prodCatalog();

        $response = $this->mcpCall($this->token(['cipp_list_tenants']), 'cipp_list_tenants', ['client_id' => $this->alpha->id]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString($expected, $this->text($response));
    }

    public function test_exec_tool_cannot_be_bound_to_the_client_and_fails_closed_without_an_upstream_call(): void
    {
        $this->configure(true);
        $this->fakeTransports();
        $this->prodCatalog();
        $token = $this->token(['cipp_exec_tool']);

        // At base this was SENT as {name, arguments:{tenantFilter: bravo}, tenantFilter: alpha}
        // and upstream ran the nested arguments: Bravo's users under Alpha's client_id.
        $response = $this->mcpCall($token, 'cipp_exec_tool', [
            'client_id' => $this->alpha->id,
            'name' => 'ListUsers',
            'arguments' => ['tenantFilter' => 'bravo.example'],
        ]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('cipp_exec_tool is unavailable through PSA', $this->text($response));
        $this->assertSame([], $this->sent);

        $listed = collect($this->listTools($token))->firstWhere('name', 'cipp_exec_tool');
        $this->assertIsArray($listed, 'still grantable and listed, as unavailable');
        $this->assertStringStartsWith('UNAVAILABLE THROUGH PSA', (string) $listed['description']);
    }

    public function test_catalog_and_docs_tools_carry_no_tenant_and_still_run(): void
    {
        $this->configure(true);
        $this->fakeTransports();
        $this->prodCatalog();
        $token = $this->token(['cipp_search_tools']);

        $response = $this->mcpCall($token, 'cipp_search_tools', ['client_id' => $this->alpha->id, 'query' => 'users']);

        $this->assertFalse((bool) $response->json('result.isError'), $this->text($response));
        $this->assertSame('SearchTools', $this->sent[0][0]);
        $listed = collect($this->listTools($token))->firstWhere('name', 'cipp_search_tools');
        $this->assertStringContainsString('not tenant data', (string) $listed['description']);
    }
}
