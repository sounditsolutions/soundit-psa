<?php

namespace Tests\Feature\Mcp;

use App\Models\CippMcpTool;
use App\Models\Client;
use App\Models\Setting;
use App\Services\Cipp\CippClient;
use App\Services\Cipp\CippMcpAuthException;
use App\Services\Cipp\CippMcpClient;
use App\Services\Cipp\CippTenantScope;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * CIPP's tenant list as read by CippTenantScope for the alias check, and narrowed by
 * cipp_list_tenants (card 6abdcac2, residuals of #4577):
 *
 *   - #4580: a REST read that throws falls back to ListTenants over the MCP relay,
 *     and a refusal names a failed sign-in as such;
 *   - #4581: one malformed row for ANOTHER tenant does not block every client, while
 *     the client's own malformed row, a malformed row that ties the client's tenant
 *     to another client's mapping, and CIPP's failure answer are all still refused.
 *
 * Synthetic tenants only (contoso, fabrikam, northwind). Rows are in the Get-Tenants
 * shape (CIPP-API Modules/CIPPCore/Public/GraphHelper/Get-Tenants.ps1); the failure
 * row is Invoke-ListTenants.ps1's catch block.
 */
class CippTenantListResilienceTest extends TestCase
{
    use RefreshDatabase;

    private const CONTOSO_ID = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    private const FABRIKAM_ID = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

    /**
     * A canary, not a credential: configure() stores it as cipp_client_secret, and
     * test_a_rest_sign_in_failure_is_named_as_a_sign_in_failure() asserts the refusal
     * never carries it.
     */
    private const REST_CLIENT_CANARY = 'rest-canary-must-not-leak';

    /** @var array<int, array{0: string, 1: array<string, mixed>}> tenant reads that reached a transport */
    private array $sent = [];

    /** @var array<int, string> which transport was asked for ListTenants, in order */
    private array $listReads = [];

    private Client $contoso;

    private Client $fabrikam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contoso = Client::factory()->create(['name' => 'Contoso', 'cipp_tenant_domain' => 'contoso.onmicrosoft.com']);
        $this->fabrikam = Client::factory()->create(['name' => 'Fabrikam', 'cipp_tenant_domain' => 'fabrikam.onmicrosoft.com']);
    }

    /** @return array<string, mixed> */
    private static function contosoRow(array $override = []): array
    {
        return array_merge(['customerId' => self::CONTOSO_ID, 'defaultDomainName' => 'contoso.onmicrosoft.com', 'initialDomainName' => 'contoso.onmicrosoft.com', 'displayName' => 'Contoso'], $override);
    }

    /** @return array<string, mixed> */
    private static function fabrikamRow(array $override = []): array
    {
        return array_merge(['customerId' => self::FABRIKAM_ID, 'defaultDomainName' => 'fabrikam.onmicrosoft.com', 'initialDomainName' => 'fabrikam.onmicrosoft.com', 'displayName' => 'Fabrikam'], $override);
    }

    /** CIPP's answer when it could not list tenants (Invoke-ListTenants.ps1 catch block). */
    private static function failureRow(): array
    {
        return ['Results' => 'Failed to retrieve tenants: synthetic', 'defaultDomainName' => '', 'displayName' => 'Failed to retrieve tenants. Perform a permission check.', 'customerId' => ''];
    }

    private function configure(bool $rest, bool $relay): void
    {
        Setting::setValue('cipp_enabled', '1');
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        if ($rest) {
            Setting::setValue('cipp_client_id', 'rest-client');
            Setting::setEncrypted('cipp_client_secret', self::REST_CLIENT_CANARY);
        }
        Setting::setValue('cipp_mcp_client_id', 'mcp-client');
        Setting::setEncrypted('cipp_mcp_client_secret', 'mcp-secret');
        Setting::setValue('cipp_mcp_enabled', $relay ? '1' : '0');
    }

    /**
     * Record-only fakes. ListTenants over REST answers $restList (a Throwable: thrown),
     * over MCP $mcpList (likewise). Every other call is a tenant read, recorded in
     * $sent, answering one row.
     */
    private function fakeTransports(array|\Throwable $restList, array|\Throwable $mcpList, ?array $tenantReadRows = null): void
    {
        $rows = $tenantReadRows ?? [['id' => 'row-1', 'displayName' => 'Row']];

        $mcp = Mockery::mock(CippMcpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(function (string $tool, array $args) use ($mcpList, $rows): array {
            if ($tool === 'ListTenants' && $args === []) {
                $this->listReads[] = 'mcp';
                if ($mcpList instanceof \Throwable) {
                    throw $mcpList;
                }

                return $mcpList;
            }
            $this->sent[] = [$tool, $args];

            return $rows;
        });
        $this->app->instance(CippMcpClient::class, $mcp);

        $rest = Mockery::mock(CippClient::class);
        $rest->shouldReceive('readDeadline')->andReturn(120.0);
        $rest->shouldReceive('get')->andReturnUsing(function (string $endpoint, array $query = []) use ($restList, $rows): array {
            if ($endpoint === 'api/ListTenants') {
                $this->listReads[] = 'rest';
                if ($restList instanceof \Throwable) {
                    throw $restList;
                }

                return $restList;
            }
            $this->sent[] = [$endpoint, $query];

            return $rows;
        });
        $this->app->instance(CippClient::class, $rest);
    }

    /**
     * A REAL CippClient (so its own token, retry and unwrap code runs) whose HTTP
     * layer answers ListTenants with $status and $body. The OAuth token is cached so
     * no sign-in request is made.
     */
    private function realRestAnswering(int $status, string $body): void
    {
        Cache::put('cipp_oauth_token', 'test-token', 3600);
        $this->app->instance(CippClient::class, new CippClient(
            ['api_url' => 'https://cipp.example.test'],
            app(\Illuminate\Contracts\Cache\Repository::class),
            new \GuzzleHttp\Client([
                'handler' => HandlerStack::create(new MockHandler(array_fill(0, 4, new Response($status, ['Content-Type' => 'application/json'], $body)))),
                'base_uri' => 'https://cipp.example.test/',
            ]),
        ));
    }

    private function mcpCall(string $name, array $arguments): TestResponse
    {
        $token = McpConfig::rotateStaffToken([$name], 'cipp-tenant-list');

        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    private function text(TestResponse $response): string
    {
        return (string) $response->json('result.content.0.text');
    }

    private function listTenantsTool(): void
    {
        CippMcpTool::create([
            'local_name' => 'cipp_list_tenants',
            'upstream_name' => 'ListTenants',
            'category' => 'CIPP',
            'description' => '[CIPP] ListTenants.',
            'input_schema' => ['type' => 'object', 'properties' => ['Mode' => ['type' => 'string'], 'TenantFilter' => ['type' => 'string']], 'required' => []],
            'annotations' => ['readOnlyHint' => true],
            'read_only' => true,
            'sensitive' => false,
            'active' => true,
            'last_seen_at' => now(),
        ]);
        McpToolRegistry::flushMemoized();
    }

    private function assertServed(TestResponse $response, string $tenant): void
    {
        $this->assertFalse((bool) $response->json('result.isError'), $this->text($response));
        $this->assertNotSame([], $this->sent, 'the tenant read never reached a transport');
        foreach ($this->sent as [, $args]) {
            $this->assertSame($tenant, $args['tenantFilter'] ?? $args['TenantFilter'] ?? null);
        }
    }

    private function assertRefused(TestResponse $response, string $expected): void
    {
        $this->assertTrue((bool) $response->json('result.isError'), $this->text($response));
        $this->assertStringContainsString($expected, $this->text($response));
        $this->assertSame([], $this->sent, 'a refused read still reached a transport');
    }

    // ── #4580: REST → MCP fallback for the tenant-list read ──

    /** @return array<string, array{0: \Throwable}> */
    public static function restFailures(): array
    {
        return [
            'transport error' => [new \App\Services\Cipp\CippClientException('CIPP API error: cURL error 7: connection refused', 0)],
            'HTTP 500' => [new \App\Services\Cipp\CippClientException('CIPP API error: Server error 500', 500)],
            'REST sign-in failed (HTTP 401)' => [new \App\Services\Cipp\CippClientException('CIPP API error: Client error 401 Unauthorized', 401)],
        ];
    }

    /**
     * REST credentials set but broken, relay enabled and healthy: the alias check reads
     * ListTenants over MCP and the mapped client's read is served (before #4580 every
     * read was refused with "could not read a usable CIPP tenant list").
     *
     * @dataProvider restFailures
     */
    public function test_a_failed_rest_tenant_list_read_falls_back_to_mcp_and_the_read_is_served(\Throwable $restFailure): void
    {
        $this->configure(rest: true, relay: true);
        $this->fakeTransports($restFailure, [self::contosoRow(), self::fabrikamRow()]);

        $response = $this->mcpCall('cipp_list_users', ['client_id' => $this->contoso->id]);

        $this->assertServed($response, 'contoso.onmicrosoft.com');
        $this->assertSame(['rest', 'mcp'], $this->listReads);
        $this->assertCount(2, (array) Cache::get('cipp-tenant-scope:tenant-list'), 'the list read over MCP is cached like a REST one');
    }

    /** The fallback is a fallback: a REST read that succeeds is not re-asked over MCP. */
    public function test_a_successful_rest_tenant_list_read_is_not_re_read_over_mcp(): void
    {
        $this->configure(rest: true, relay: true);
        $this->fakeTransports([self::contosoRow(), self::fabrikamRow()], new \RuntimeException('MCP must not be asked for ListTenants'));

        $response = $this->mcpCall('cipp_list_users', ['client_id' => $this->contoso->id]);

        $this->assertServed($response, 'contoso.onmicrosoft.com');
        $this->assertSame(['rest'], $this->listReads);
    }

    /** REST throws and there is no relay to fall back to: refused, with the cause named. */
    public function test_a_failed_rest_read_with_no_relay_is_refused_and_names_the_cause(): void
    {
        $this->configure(rest: true, relay: false);
        $this->fakeTransports(new \App\Services\Cipp\CippClientException('CIPP API error: cURL error 28: timed out', 0), [self::contosoRow(), self::fabrikamRow()]);

        $response = $this->mcpCall('cipp_list_users', ['client_id' => $this->contoso->id]);

        $this->assertRefused($response, 'could not read a usable CIPP tenant list (the CIPP REST API request failed)');
        $this->assertStringNotContainsString('sign-in', $this->text($response));
        $this->assertStringNotContainsString('timed out', $this->text($response), 'upstream text goes to the log, not the agent');
        $this->assertSame(['rest'], $this->listReads);
        $this->assertNull(Cache::get('cipp-tenant-scope:tenant-list'));
    }

    /** Both transports fail: still refused, and both causes are named. */
    public function test_rest_and_mcp_both_failing_is_refused_and_names_both(): void
    {
        $this->configure(rest: true, relay: true);
        $this->fakeTransports(
            new \App\Services\Cipp\CippClientException('CIPP API error: Server error 502', 502),
            new \App\Services\Cipp\CippClientException('CIPP MCP ListTenants failed: HTTP 502', 502),
        );

        $response = $this->mcpCall('cipp_list_users', ['client_id' => $this->contoso->id]);

        $this->assertRefused($response, 'could not read a usable CIPP tenant list (the CIPP REST API request failed; the CIPP MCP request failed)');
        $this->assertSame(['rest', 'mcp'], $this->listReads);
    }

    /**
     * A REST sign-in failure, through the REAL CippClient (its 401 retry runs, then it
     * throws with code 401): the refusal says CIPP sign-in failed, and carries neither
     * the secret nor the token.
     */
    public function test_a_rest_sign_in_failure_is_named_as_a_sign_in_failure(): void
    {
        $this->configure(rest: true, relay: false);
        $this->realRestAnswering(401, '{"error":"invalid_token test-token"}');

        $result = (new \App\Services\Assistant\AssistantToolExecutor(null, $this->contoso->id, null))->execute('cipp_list_users', []);
        $error = (string) ($result['error'] ?? '');

        $this->assertStringContainsString('could not read a usable CIPP tenant list (CIPP REST API sign-in failed)', $error);
        $this->assertStringContainsString('CIPP sign-in failed', $error);
        $this->assertStringNotContainsString(self::REST_CLIENT_CANARY, $error);
        $this->assertStringNotContainsString('test-token', $error);
        $this->assertStringNotContainsString('invalid_token', $error);
    }

    /**
     * An MCP-only deployment whose MCP sign-in fails (CippMcpAuthException, the type
     * cippDispatch()'s failover already keys on): the refusal names the sign-in, not
     * only the tenant list, and keeps the upstream AADSTS text in the log.
     */
    public function test_an_mcp_sign_in_failure_is_named_as_a_sign_in_failure(): void
    {
        $this->configure(rest: false, relay: true);
        $this->fakeTransports(new \RuntimeException('REST must not be asked'), new CippMcpAuthException('CIPP MCP ListTenants was refused: HTTP 401 AADSTS7000215 invalid client secret'));

        // The staff surface lists curated tools only with REST configured, so the
        // executor is driven directly; the resolver runs before any transport.
        $result = (new \App\Services\Assistant\AssistantToolExecutor(null, $this->contoso->id, null))->execute('cipp_list_users', []);
        $error = (string) ($result['error'] ?? '');

        $this->assertStringContainsString('could not read a usable CIPP tenant list (CIPP MCP sign-in failed)', $error);
        $this->assertStringContainsString('CIPP sign-in failed', $error);
        $this->assertStringNotContainsString('AADSTS', $error);
        $this->assertSame([], $this->sent);
        $this->assertSame(['mcp'], $this->listReads);
    }

    /**
     * CIPP's own failure answer is an ANSWER, not a transport failure: it is refused
     * and not re-asked over MCP, in the object form (REST get() unwraps its string
     * Results) and the list form (Invoke-ListTenants.ps1 returns @($Body)).
     *
     * @dataProvider vendorFailureBodies
     */
    public function test_the_vendor_failure_answer_over_rest_is_refused_and_not_re_asked(string $body): void
    {
        $this->configure(rest: true, relay: true);
        $this->fakeTransports([], [self::contosoRow(), self::fabrikamRow()]);
        $this->realRestAnswering(200, $body);

        $response = $this->mcpCall('cipp_list_users', ['client_id' => $this->contoso->id]);

        $this->assertRefused($response, 'could not read a usable CIPP tenant list (CIPP answered ListTenants with something other than a tenant list)');
        $this->assertSame([], $this->listReads, 'MCP was asked again after CIPP answered');
        $this->assertNull(Cache::get('cipp-tenant-scope:tenant-list'));
    }

    /** @return array<string, array{0: string}> */
    public static function vendorFailureBodies(): array
    {
        $row = self::failureRow();

        return [
            'object' => [json_encode($row)],
            'one-row list' => [json_encode([$row])],
            'mixed into real rows' => [json_encode([self::contosoRow(), self::fabrikamRow(), $row])],
        ];
    }

    // ── #4581: one malformed row must not block every client ──

    /** @return array<string, array{0: array<string, mixed>}> another tenant's row, malformed */
    public static function foreignMalformedRows(): array
    {
        return [
            'no defaultDomainName (mid-onboarding)' => [['customerId' => 'cccccccc-cccc-cccc-cccc-cccccccccccc', 'defaultDomainName' => '', 'initialDomainName' => 'northwind.onmicrosoft.com', 'displayName' => 'Northwind']],
            'no customerId' => [['defaultDomainName' => 'northwind.onmicrosoft.com', 'displayName' => 'Northwind']],
            'null domain values' => [['customerId' => null, 'defaultDomainName' => null, 'initialDomainName' => 'northwind.onmicrosoft.com', 'displayName' => 'Northwind']],
        ];
    }

    /**
     * A malformed row for a tenant no PSA client of ours is mapped to: Contoso's
     * curated read and Contoso's cipp_list_tenants both proceed (before #4581 the
     * whole list was refused, for every client).
     *
     * @dataProvider foreignMalformedRows
     */
    public function test_one_malformed_foreign_row_does_not_block_the_mapped_clients_reads(array $foreign): void
    {
        $list = [self::contosoRow(), $foreign, self::fabrikamRow()];
        $this->configure(rest: true, relay: false);
        $this->fakeTransports($list, new \RuntimeException('MCP must not be asked'));

        $this->assertServed($this->mcpCall('cipp_list_users', ['client_id' => $this->contoso->id]), 'contoso.onmicrosoft.com');
        $this->assertSame($list, Cache::get('cipp-tenant-scope:tenant-list'), 'a usable list is cached as read, malformed row included');

        // cipp_list_tenants answers every managed tenant; the client's own row is picked out.
        $this->configure(rest: true, relay: true);
        $this->listTenantsTool();
        $this->sent = [];
        $this->fakeTransports($list, $list, $list);

        $response = $this->mcpCall('cipp_list_tenants', ['client_id' => $this->contoso->id]);

        $this->assertFalse((bool) $response->json('result.isError'), $this->text($response));
        $this->assertSame(1, json_decode($this->text($response), true)['summary']['count'] ?? null, $this->text($response));
        $this->assertStringContainsString('Contoso', $this->text($response));
        $this->assertStringNotContainsString('northwind', $this->text($response));
        $this->assertStringNotContainsString('fabrikam', $this->text($response));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function ownMalformedRows(): array
    {
        return [
            'no customerId' => [['customerId' => '', 'defaultDomainName' => 'contoso.onmicrosoft.com', 'initialDomainName' => 'contoso.onmicrosoft.com', 'displayName' => 'Contoso']],
            'no defaultDomainName' => [['customerId' => self::CONTOSO_ID, 'defaultDomainName' => '', 'initialDomainName' => 'contoso.onmicrosoft.com', 'displayName' => 'Contoso']],
        ];
    }

    /**
     * The client's OWN row is the one the alias check and the narrowing rest on: if it
     * is malformed, both still refuse, on the curated read and on cipp_list_tenants.
     *
     * @dataProvider ownMalformedRows
     */
    public function test_the_clients_own_malformed_row_is_still_refused(array $own): void
    {
        $list = [$own, self::fabrikamRow()];
        $this->configure(rest: true, relay: true);
        $this->listTenantsTool();
        $this->fakeTransports($list, $list, $list);

        $this->assertRefused($this->mcpCall('cipp_list_users', ['client_id' => $this->contoso->id]), "row for PSA client {$this->contoso->id}'s CIPP mapping has no customerId or defaultDomainName value");
        $this->assertRefused($this->mcpCall('cipp_list_tenants', ['client_id' => $this->contoso->id]), "row for PSA client {$this->contoso->id}'s CIPP mapping has no customerId or defaultDomainName value");

        // Fabrikam's own row is well-formed, so Fabrikam still reads.
        $this->assertServed($this->mcpCall('cipp_list_users', ['client_id' => $this->fabrikam->id]), 'fabrikam.onmicrosoft.com');
    }

    /**
     * A malformed row is skipped for MATCHING, not for attribution. Here CIPP lists
     * Contoso's tenant twice: once well-formed, once mid-onboarding with no
     * defaultDomainName but Contoso's customerId and another initial domain, which a
     * third PSA client (Northwind) is mapped to. Get-Tenants matches that domain to
     * Contoso's tenant, so Northwind could read Contoso's data: both are refused.
     */
    public function test_a_malformed_row_whose_alias_is_another_clients_mapping_still_refuses(): void
    {
        $northwind = Client::factory()->create(['name' => 'Northwind', 'cipp_tenant_domain' => 'contoso-legacy.onmicrosoft.com']);
        $list = [
            self::contosoRow(),
            ['customerId' => self::CONTOSO_ID, 'defaultDomainName' => '', 'initialDomainName' => 'contoso-legacy.onmicrosoft.com', 'displayName' => 'Contoso (refreshing)'],
            self::fabrikamRow(),
        ];
        $this->configure(rest: true, relay: true);
        $this->listTenantsTool();
        $this->fakeTransports($list, $list, $list);

        $this->assertRefused($this->mcpCall('cipp_list_users', ['client_id' => $this->contoso->id]), 'also mapped, under another of its domains');
        $this->assertRefused($this->mcpCall('cipp_list_tenants', ['client_id' => $this->contoso->id]), 'also mapped, under another of its domains');
        // Northwind's mapping matches only the malformed row: refused, never served.
        $this->assertRefused($this->mcpCall('cipp_list_users', ['client_id' => $northwind->id]), 'has no customerId or defaultDomainName value');

        // Fabrikam shares nothing with either and still reads.
        $this->assertServed($this->mcpCall('cipp_list_users', ['client_id' => $this->fabrikam->id]), 'fabrikam.onmicrosoft.com');
    }

    /**
     * The vendor's whole-list failure is still refused by cipp_list_tenants, in the
     * object form and mixed into real rows, and the curated read's alias check refuses
     * the same answer over MCP (where it arrives as rows, not as a thrown TypeError).
     *
     * @dataProvider vendorFailureLists
     */
    public function test_the_vendor_failure_envelope_is_still_refused(array $answer): void
    {
        $this->configure(rest: true, relay: true);
        $this->listTenantsTool();
        $this->fakeTransports(new \RuntimeException('REST must not be asked'), $answer, $answer);

        // With Fabrikam unmapped the resolver makes no alias check, so the refusal
        // here is the cipp_list_tenants narrowing's own (clientTenantRow()).
        $this->fabrikam->update(['cipp_tenant_domain' => null]);
        $response = $this->mcpCall('cipp_list_tenants', ['client_id' => $this->contoso->id]);
        $this->assertTrue((bool) $response->json('result.isError'), $this->text($response));
        $this->assertStringContainsString('answered with a row that is not a tenant', $this->text($response));
        $this->assertSame([], $this->listReads);
        $this->sent = [];

        // With Fabrikam mapped, the resolver's alias check reads the same answer over
        // MCP (REST is not configured here) and refuses it before any read.
        $this->fabrikam->update(['cipp_tenant_domain' => 'fabrikam.onmicrosoft.com']);
        Setting::setValue('cipp_client_id', '');
        $result = CippTenantScope::resolve($this->contoso, $this->contoso->id);
        $this->assertStringContainsString('could not read a usable CIPP tenant list (CIPP answered ListTenants with something other than a tenant list)', (string) ($result['error'] ?? ''));
        $this->assertNull(Cache::get('cipp-tenant-scope:tenant-list'));
    }

    /** @return array<string, array{0: array<int|string, mixed>}> */
    public static function vendorFailureLists(): array
    {
        return [
            'object' => [self::failureRow()],
            'one-row list' => [[self::failureRow()]],
            'mixed into real rows' => [[self::contosoRow(), self::fabrikamRow(), self::failureRow()]],
            'empty-id rows only' => [[['customerId' => '', 'defaultDomainName' => '', 'displayName' => 'x']]],
        ];
    }

    /**
     * A client whose own row was malformed when the list was cached (CIPP still
     * onboarding it) is re-read past the cache, so it reads as soon as CIPP's row is
     * complete rather than after the cache TTL.
     */
    public function test_a_cached_malformed_own_row_is_re_read_before_refusing(): void
    {
        $this->configure(rest: true, relay: false);
        Cache::put('cipp-tenant-scope:tenant-list', [self::contosoRow(['defaultDomainName' => '']), self::fabrikamRow()], 300);
        $this->fakeTransports([self::contosoRow(), self::fabrikamRow()], new \RuntimeException('MCP must not be asked'));

        $this->assertServed($this->mcpCall('cipp_list_users', ['client_id' => $this->contoso->id]), 'contoso.onmicrosoft.com');
        $this->assertSame(['rest'], $this->listReads);
    }

    /**
     * REST settings that cannot be read (cipp_client_secret no longer decrypts under
     * this APP_KEY) do not throw out of the resolver: CIPP was not asked, so the
     * alias check falls back to MCP when the relay is on, and is refused with the
     * cause named when it is not. The resolver is driven directly because the staff
     * surface's own tool listing reads the same REST settings.
     */
    public function test_unreadable_rest_settings_fall_back_to_mcp_or_are_refused(): void
    {
        $this->configure(rest: true, relay: true);
        Setting::setValue('cipp_client_secret', 'not-an-encrypted-payload');
        $this->fakeTransports(new \RuntimeException('REST must not be asked'), [self::contosoRow(), self::fabrikamRow()]);

        $this->assertSame('contoso.onmicrosoft.com', CippTenantScope::resolve($this->contoso, $this->contoso->id));
        $this->assertSame(['mcp'], $this->listReads);

        Cache::forget('cipp-tenant-scope:tenant-list');
        Setting::setValue('cipp_mcp_enabled', '0');
        $this->listReads = [];

        $result = CippTenantScope::resolve($this->contoso, $this->contoso->id);
        $error = (string) ($result['error'] ?? '');
        $this->assertStringContainsString('could not read a usable CIPP tenant list (PSA could not read its CIPP REST API settings)', $error);
        $this->assertStringNotContainsString('sign-in', $error);
        $this->assertStringNotContainsString('payload', $error, 'upstream text goes to the log, not the agent');
        $this->assertSame([], $this->listReads);
        $this->assertNull(Cache::get('cipp-tenant-scope:tenant-list'));
    }
}
