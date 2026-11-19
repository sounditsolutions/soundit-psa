<?php

namespace Tests\Feature\Cipp;

use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Assistant\AssistantToolDefinitions;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Cipp\CippClient;
use App\Services\Cipp\CippMcpClient;
use App\Services\Mcp\CippServiceHealthToolExecutor;
use App\Services\Teams\TeamsReadOnlyToolset;
use App\Services\Triage\TriageToolDefinitions;
use App\Services\Triage\TriageToolExecutor;
use App\Support\CippConfig;
use App\Support\McpConfig;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Promise\Create;
use Illuminate\Contracts\Cache\Repository as CacheInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * cipp_list_service_health_issues: one client's tenant, both transports, built dormant.
 *
 * Fixture rows follow the key set and types measured on a direct
 * api/ListServiceHealthIssues reply (producer: Invoke-ListServiceHealthIssues.ps1,
 * live-Graph branch, Body = @($Results)). Ids, text and tenants are invented.
 * The relay replies are built to the direct leg's measured shape, not measured on a
 * live relay; the relay's reply shape is read from vendor source, not measured.
 */
class CippServiceHealthIssuesTest extends TestCase
{
    use RefreshDatabase;

    private const TOOL = 'cipp_list_service_health_issues';

    private const TEN = ['id', 'title', 'service', 'feature', 'classification', 'status', 'start', 'end', 'last_updated', 'resolved'];

    private Client $client;

    /** @var mixed the direct reply body, JSON-encoded unless a string */
    private mixed $restBody = [];

    /** @var array<int, string> the relay reply's text content blocks */
    private array $relayTexts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        $this->client = Client::factory()->create(['cipp_tenant_domain' => 'contoso.example']);
        Setting::setValue('cipp_enabled', '1');
        Setting::setValue('cipp_api_url', 'https://cipp.example');
        Setting::setValue('cipp_tenant_id', 'synthetic-tenant');
        Setting::setValue('cipp_client_id', 'synthetic-client');
        Setting::setEncrypted('cipp_client_secret', 'synthetic-secret');
        Setting::setValue('cipp_mcp_enabled', '0');
        Cache::put('cipp_oauth_token', 'synthetic-token', 3600);
        // Production REST uses Guzzle; this handler routes its real request through
        // the strict Http fake, so the method, path and query are asserted as sent.
        $http = new GuzzleClient(['base_uri' => 'https://cipp.example/', 'handler' => function ($request) {
            $response = Http::send($request->getMethod(), (string) $request->getUri());

            return Create::promiseFor($response->toPsrResponse());
        }]);
        $this->app->instance(CippClient::class, new CippClient(['api_url' => 'https://cipp.example'], Cache::store(), $http));
        // Registered once: a later Http::fake() would reset the recording, and the
        // first matching stub wins, so each reply is read from a property instead.
        Http::fake([
            'https://cipp.example/api/ListServiceHealthIssues*' => fn () => Http::response(is_string($this->restBody) ? $this->restBody : json_encode($this->restBody)),
            'login.microsoftonline.com/*' => fn () => Http::response(['access_token' => 'synthetic-mcp-token', 'expires_in' => 3600]),
            'https://cipp.example/api/ExecMCP*' => fn () => Http::response(
                "event: message\ndata: ".json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => array_map(fn (string $text): array => ['type' => 'text', 'text' => $text], $this->relayTexts), 'isError' => false]])."\n\n",
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);
    }

    private function row(array $overrides = []): array
    {
        return array_replace([
            'id' => 'ZZ900001',
            'title' => 'Synthetic delay in mail delivery',
            'service' => 'Exchange Online',
            'feature' => 'E-Mail and calendar access',
            'featureGroup' => 'E-Mail and calendar access',
            'classification' => 'incident',
            'status' => 'serviceRestored',
            'origin' => 'microsoft',
            'impactDescription' => 'Synthetic impact text that must not be returned',
            'startDateTime' => '2026-11-01T10:00:00Z',
            'endDateTime' => '2026-11-01T12:00:00Z',
            'lastModifiedDateTime' => '2026-11-01T12:30:00Z',
            'isResolved' => true,
            'details' => [],
            'posts' => [['createdDateTime' => '2026-11-01T10:05:00Z', 'postType' => 'regular', 'description' => ['contentType' => 'html', 'content' => 'synthetic post']]],
        ], $overrides);
    }

    private function callTool(array $input = []): array
    {
        return (new CippServiceHealthToolExecutor($this->client->id))->execute($input);
    }

    /** @return array<int, Request> requests sent to the direct endpoint */
    private function restCalls(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/api/ListServiceHealthIssues'))->map(fn ($pair) => $pair[0])->values()->all();
    }

    private function enableRelay(): void
    {
        Setting::setValue('cipp_mcp_client_id', 'synthetic-mcp-client');
        Setting::setEncrypted('cipp_mcp_client_secret', 'synthetic-mcp-secret');
        Setting::setValue('cipp_mcp_enabled', '1');
        $this->assertTrue(CippConfig::isMcpRelayEnabled());
        $this->app->instance(CippMcpClient::class, new CippMcpClient([
            'api_url' => 'https://cipp.example',
            'tenant_id' => 'synthetic-tenant',
            'client_id' => 'synthetic-mcp-client',
            'client_secret' => 'synthetic-mcp-secret',
        ], app(CacheInterface::class), fn (string $host): array => ['203.0.113.10']));
    }

    /** ExecMCP answering with these content blocks (each a text string). */
    private function relayReplies(array $texts): void
    {
        $this->relayTexts = $texts;
    }

    /** @return array<int, Request> */
    private function execMcpCalls(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/api/ExecMCP'))->map(fn ($pair) => $pair[0])->values()->all();
    }

    private function assertRefused(array $result): void
    {
        $this->assertArrayHasKey('error', $result, json_encode($result));
        $this->assertArrayNotHasKey('issues', $result);
        $this->assertStringContainsString('NOT an empty result', $result['error']);
    }

    // ── Direct leg ──

    public function test_direct_leg_sends_one_get_with_only_the_resolved_tenant(): void
    {
        $this->restBody = [$this->row()];
        $result = $this->callTool(['issue_id' => 'ZZ900001']);

        $this->assertTrue($result['found'], json_encode($result));
        $calls = $this->restCalls();
        $this->assertCount(1, $calls);
        $this->assertSame('GET', $calls[0]->method());
        $this->assertSame('/api/ListServiceHealthIssues', parse_url($calls[0]->url(), PHP_URL_PATH));
        parse_str((string) parse_url($calls[0]->url(), PHP_URL_QUERY), $query);
        $this->assertSame(['TenantFilter' => 'contoso.example'], $query);
        $this->assertArrayNotHasKey('Open', $query);
        $this->assertArrayNotHasKey('UseReportDB', $query);
        $this->assertStringNotContainsString('ZZ900001', $calls[0]->url());
        Http::assertSentCount(1);
    }

    public function test_projection_is_exactly_the_ten_fields_with_free_text_fenced(): void
    {
        $this->restBody = [$this->row(), $this->row(['id' => 'YY900002', 'endDateTime' => null, 'isResolved' => false, 'status' => 'serviceDegradation', 'classification' => 'advisory'])];
        $result = $this->callTool();

        $this->assertSame(2, $result['issues_read']);
        $this->assertSame('2 issues returned', $result['result']);
        foreach ($result['issues'] as $issue) {
            $this->assertSame(self::TEN, array_keys($issue));
        }
        $first = $result['issues'][0];
        $this->assertSame(['ZZ900001', 'incident', 'serviceRestored', '2026-11-01T10:00:00Z', '2026-11-01T12:00:00Z', '2026-11-01T12:30:00Z', true], [$first['id'], $first['classification'], $first['status'], $first['start'], $first['end'], $first['last_updated'], $first['resolved']]);
        $this->assertStringContainsString('UNTRUSTED', $first['title']);
        $this->assertStringContainsString('Synthetic delay in mail delivery', $first['title']);
        $this->assertStringContainsString('Exchange Online', $first['service']);
        $this->assertNull($result['issues'][1]['end']);
        $this->assertFalse($result['issues'][1]['resolved']);
        $json = json_encode($result);
        foreach (['impactDescription', 'posts', 'details', 'origin', 'featureGroup', 'Synthetic impact text', 'synthetic post'] as $dropped) {
            $this->assertStringNotContainsString($dropped, $json);
        }
    }

    /** @return array<string, array{0: mixed}> */
    public static function notAFlatList(): array
    {
        $row = ['id' => 'ZZ900001', 'title' => 't', 'service' => 's', 'feature' => 'f', 'classification' => 'incident', 'status' => 'serviceRestored', 'startDateTime' => 'a', 'endDateTime' => null, 'lastModifiedDateTime' => 'b', 'isResolved' => true];

        return [
            'Results envelope' => [['Results' => [$row], 'Metadata' => []]],
            'value envelope' => [['value' => [$row]]],
            'single object' => [$row],
            'keyed array' => [['a' => $row, 'b' => $row]],
            'text fallback' => [['text' => 'synthetic text']],
            'scalar number' => ['5'],
            'scalar string' => ['"synthetic"'],
            'not JSON' => ['synthetic not json'],
        ];
    }

    #[DataProvider('notAFlatList')]
    public function test_direct_leg_refuses_a_reply_that_is_not_a_flat_list(mixed $body): void
    {
        $this->restBody = $body;
        $this->assertRefused($this->callTool());
        $this->assertCount(1, $this->restCalls());
    }

    /** @return array<string, array{0: array<string, mixed>|string}> */
    public static function badRows(): array
    {
        return [
            'id null' => [['id' => null]],
            'missing title' => [['title' => '__unset__']],
            'missing lastModifiedDateTime' => [['lastModifiedDateTime' => '__unset__']],
            'missing endDateTime key' => [['endDateTime' => '__unset__']],
            'id as int' => [['id' => 900001]],
            'service as array' => [['service' => ['Exchange']]],
            'isResolved as string' => [['isResolved' => 'true']],
            'isResolved missing' => [['isResolved' => '__unset__']],
            'endDateTime as int' => [['endDateTime' => 1700000000]],
            'startDateTime null' => [['startDateTime' => null]],
            'row is a list' => ['list'],
            'row is a scalar' => ['scalar'],
        ];
    }

    private function badRow(array|string $fault): mixed
    {
        if ($fault === 'list') {
            return ['ZZ900002', 'title'];
        }
        if ($fault === 'scalar') {
            return 'ZZ900002';
        }
        $row = $this->row(['id' => 'ZZ900002']);
        foreach ($fault as $key => $value) {
            if ($value === '__unset__') {
                unset($row[$key]);
            } else {
                $row[$key] = $value;
            }
        }

        return $row;
    }

    #[DataProvider('badRows')]
    public function test_one_bad_row_refuses_the_whole_direct_call(array|string $fault): void
    {
        $this->restBody = [$this->row(), $this->badRow($fault)];
        $result = $this->callTool();
        $this->assertRefused($result);
        $this->assertStringNotContainsString('ZZ900001', json_encode($result));
    }

    public function test_empty_list_is_worded_as_a_count_never_as_no_issues(): void
    {
        $this->restBody = [];
        $result = $this->callTool();

        $this->assertSame(['issues_read' => 0, 'result' => '0 issues returned', 'issues' => []], $result);
        $this->assertStringNotContainsStringIgnoringCase('no issues', json_encode($result));
    }

    public function test_issue_id_is_an_exact_case_sensitive_match_applied_after_the_check(): void
    {
        $this->restBody = [$this->row(), $this->row(['id' => 'YY900002'])];

        $hit = $this->callTool(['issue_id' => 'YY900002']);
        $this->assertSame(['issue_id' => 'YY900002', 'found' => true, 'issues_read' => 2, 'result' => '1 issue returned'], array_diff_key($hit, ['issues' => 1]));
        $this->assertSame(['YY900002'], array_column($hit['issues'], 'id'));

        foreach (['yy900002', 'YY90000', 'YY9000022'] as $miss) {
            $result = $this->callTool(['issue_id' => $miss]);
            $this->assertSame(['issue_id' => $miss, 'found' => false, 'issues_read' => 2, 'result' => "issue {$miss} not found among 2 issues read"], $result);
        }

        // The contract check runs before the filter: a bad row elsewhere still refuses.
        $this->restBody = [$this->row(), $this->badRow(['isResolved' => 'true'])];
        $this->assertRefused($this->callTool(['issue_id' => 'ZZ900001']));
    }

    /** @return array<string, array{0: mixed}> */
    public static function badIssueIds(): array
    {
        return [
            'hyphen' => ['ZZ-900001'],
            'empty' => [''],
            'space' => [' ZZ900001'],
            'trailing newline' => ["ZZ900001\n"],
            'wildcard' => ['ZZ*'],
            'integer' => [900001],
            'array' => [['ZZ900001']],
            'null' => [null],
        ];
    }

    #[DataProvider('badIssueIds')]
    public function test_a_bad_issue_id_is_refused_before_any_request(mixed $issueId): void
    {
        $this->restBody = [$this->row()];
        $result = $this->callTool(['issue_id' => $issueId]);
        $this->assertSame(['error' => 'issue_id must be letters and digits only; the request was not sent.'], $result);
        Http::assertNothingSent();
    }

    // ── Relay leg ──

    public function test_relay_sends_only_the_resolved_tenant_and_accepts_a_flat_list(): void
    {
        $this->enableRelay();
        $this->relayReplies([json_encode([$this->row()])]);

        $result = $this->callTool(['issue_id' => 'ZZ900001']);

        $this->assertTrue($result['found'], json_encode($result));
        $this->assertSame(self::TEN, array_keys($result['issues'][0]));
        $calls = $this->execMcpCalls();
        $this->assertCount(1, $calls);
        parse_str((string) parse_url($calls[0]->url(), PHP_URL_QUERY), $query);
        $this->assertSame('ListServiceHealthIssues', $query['tools']);
        $this->assertSame('ListServiceHealthIssues', $calls[0]['params']['name']);
        $this->assertSame(['tenantFilter' => 'contoso.example'], $calls[0]['params']['arguments']);
        $this->assertStringNotContainsString('ZZ900001', json_encode($calls[0]->data()));
        $this->assertSame([], $this->restCalls());
    }

    public function test_relay_empty_reply_is_worded_as_a_count(): void
    {
        $this->enableRelay();
        foreach (['', '[]'] as $text) {
            $this->relayReplies([$text]);
            $result = $this->callTool();
            $this->assertSame(['issues_read' => 0, 'result' => '0 issues returned', 'issues' => []], $result);
            $this->assertStringNotContainsStringIgnoringCase('no issues', json_encode($result));
        }
    }

    /** @return array<string, array{0: string}> */
    public static function relayNotAFlatList(): array
    {
        $row = json_encode(['id' => 'ZZ900001', 'title' => 't', 'service' => 's', 'feature' => 'f', 'classification' => 'incident', 'status' => 'serviceRestored', 'startDateTime' => 'a', 'endDateTime' => null, 'lastModifiedDateTime' => 'b', 'isResolved' => true]);

        return [
            'Results envelope' => ['{"Results":['.$row.']}'],
            'Results envelope with Metadata' => ['{"Results":['.$row.'],"Metadata":{}}'],
            'value envelope' => ['{"value":['.$row.']}'],
            'empty Results envelope' => ['{"Results":[]}'],
            'single object' => [$row],
            'keyed array' => ['{"a":'.$row.'}'],
            'text fallback' => ['synthetic plain text'],
            'scalar number' => ['5'],
            'scalar string' => ['"synthetic"'],
        ];
    }

    #[DataProvider('relayNotAFlatList')]
    public function test_relay_refuses_an_envelope_or_anything_not_a_flat_list(string $text): void
    {
        $this->enableRelay();
        $this->relayReplies([$text]);
        $this->assertRefused($this->callTool());
        $this->assertCount(1, $this->execMcpCalls());
        $this->assertSame([], $this->restCalls());
    }

    public function test_relay_envelope_refusal_names_the_envelope(): void
    {
        $this->enableRelay();
        $this->relayReplies(['{"Results":'.json_encode([$this->row()]).'}']);
        $this->assertStringContainsString('inside an envelope', $this->callTool()['error']);
    }

    public function test_relay_one_bad_row_refuses_the_whole_call(): void
    {
        $this->enableRelay();
        $this->relayReplies([json_encode([$this->row(), $this->badRow(['endDateTime' => 1700000000])])]);
        $this->assertRefused($this->callTool());
    }

    public function test_relay_second_content_block_refuses_rather_than_showing_a_short_list(): void
    {
        $this->enableRelay();
        $this->relayReplies([json_encode([$this->row()]), 'Response metadata (paging cursor and query context): {"nextLink":"https://graph.example/next"}']);
        $result = $this->callTool();
        $this->assertRefused($result);
        $this->assertStringContainsString('more than one content block', $result['error']);
        $this->assertStringNotContainsString('ZZ900001', json_encode($result));

        $this->relayReplies([json_encode([$this->row()]), 'second block']);
        $this->assertRefused($this->callTool(['issue_id' => 'ZZ900001']));
    }

    public function test_an_existing_relay_tool_still_accepts_an_enveloped_reply_exactly_as_before(): void
    {
        $this->enableRelay();
        $licence = ['skuId' => '44444444-4444-4444-8444-444444444444', 'License' => 'Synthetic licence', 'TotalLicenses' => 10, 'CountUsed' => 3, 'CountAvailable' => 7];
        $executor = new AssistantToolExecutor(null, $this->client->id);

        $this->relayReplies([json_encode(['Results' => [$licence], 'Metadata' => (object) []])]);
        $enveloped = $executor->execute('cipp_list_licenses', []);
        $this->relayReplies([json_encode([$licence])]);
        $bare = $executor->execute('cipp_list_licenses', []);

        $this->assertArrayNotHasKey('error', $enveloped, json_encode($enveloped));
        $this->assertNotSame([], $enveloped);
        $this->assertStringContainsString('44444444-4444-4444-8444-444444444444', json_encode($enveloped));
        $this->assertSame($bare, $enveloped);
        $this->assertCount(2, $this->execMcpCalls());
        $this->assertSame([], $this->restCalls());
    }

    // ── One tenant, never all tenants ──

    public function test_no_all_tenants_value_is_sent_on_either_leg(): void
    {
        foreach (['AllTenants', 'alltenants', ' ALLTENANTS '] as $mapping) {
            $this->client->update(['cipp_tenant_domain' => $mapping]);
            foreach ([false, true] as $relay) {
                if ($relay) {
                    $this->enableRelay();
                    $this->relayReplies([json_encode([$this->row()])]);
                }
                $result = $this->callTool();
                $this->assertArrayHasKey('error', $result, $mapping);
                $this->assertArrayNotHasKey('issues', $result);
            }
        }
        $this->assertSame([], $this->restCalls());
        $this->assertSame([], $this->execMcpCalls());
    }

    public function test_a_caller_supplied_tenant_is_refused_before_any_request_on_both_legs(): void
    {
        $this->restBody = [$this->row()];
        foreach ([false, true] as $relay) {
            if ($relay) {
                $this->enableRelay();
                $this->relayReplies([json_encode([$this->row()])]);
            }
            foreach (['tenantFilter', 'TenantFilter', 'tenant', 'Open', 'UseReportDB'] as $key) {
                $result = $this->callTool([$key => 'AllTenants']);
                $this->assertArrayHasKey('error', $result, $key);
                $this->assertStringContainsString($key, $result['error']);
            }
        }
        Http::assertNothingSent();
    }

    public function test_an_unmapped_client_is_refused_before_any_request(): void
    {
        $this->client->update(['cipp_tenant_domain' => null]);
        $this->assertStringContainsString('not mapped to CIPP', $this->callTool()['error']);
        $this->assertStringContainsString('client_id is required', (new CippServiceHealthToolExecutor(null))->execute([])['error']);
        Http::assertNothingSent();
    }

    // ── Built dormant ──

    public function test_absent_from_triage_assistant_and_teams_tool_lists(): void
    {
        $this->assertTrue(TriageToolDefinitions::isCippAvailable());
        $this->assertContains(self::TOOL, TriageToolDefinitions::MCP_ONLY_TOOLS);
        $teams = TeamsReadOnlyToolset::forTurn(null);
        foreach ([TriageToolDefinitions::getTools(), TriageToolDefinitions::readTools(), AssistantToolDefinitions::getTools(true), AssistantToolDefinitions::getTools(false), $teams->tools()] as $tools) {
            $this->assertNotContains(self::TOOL, array_column($tools, 'name'));
        }
        $this->assertContains('cipp_list_licenses', array_column(TriageToolDefinitions::getTools(), 'name'));
        $this->assertFalse($teams->allows(self::TOOL));
        $this->assertNotContains(self::TOOL, AssistantToolExecutor::readTools());
        Http::assertNothingSent();
    }

    public function test_triage_and_assistant_executors_refuse_it_by_name_without_requests(): void
    {
        $this->restBody = [$this->row()];
        $ticket = new Ticket(['client_id' => $this->client->id]);
        foreach ([new AssistantToolExecutor(null, $this->client->id), new TriageToolExecutor($ticket)] as $executor) {
            $this->assertSame(['error' => 'Unknown tool: '.self::TOOL], $executor->execute(self::TOOL, []));
        }
        Http::assertNothingSent();
    }

    private function mcp(string $token, string $method, array $params = []): array
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ?: new \stdClass])
            ->json();
    }

    public function test_only_a_token_granting_it_by_name_can_list_or_call_it(): void
    {
        $this->restBody = [$this->row()];
        // null = the legacy full-surface token.
        foreach ([null, ['cipp_list_licenses'], ['cipp_list_users', 'cipp_compare_user_access'], [self::TOOL]] as $grants) {
            $label = 'synthetic-svch-'.md5(json_encode($grants));
            $token = McpConfig::rotateStaffToken(allowedTools: $grants, label: $label);
            $allowed = $grants === [self::TOOL];

            $names = array_column($this->mcp($token, 'tools/list')['result']['tools'] ?? [], 'name');
            $this->assertSame($allowed, in_array(self::TOOL, $names, true), json_encode($grants));

            $call = $this->mcp($token, 'tools/call', ['name' => self::TOOL, 'arguments' => ['client_id' => $this->client->id]]);
            if ($allowed) {
                $this->assertNotTrue($call['result']['isError'] ?? null, json_encode($call));
                $data = json_decode($call['result']['content'][0]['text'], true);
                $this->assertSame('1 issue returned', $data['result']);
                $this->assertSame(self::TEN, array_keys($data['issues'][0]));
                $this->assertCount(1, $this->restCalls());
            } else {
                $this->assertTrue(($call['result']['isError'] ?? false) === true || isset($call['error']), json_encode($call));
                $this->assertSame([], $this->restCalls(), json_encode($grants));
            }
        }
    }
}
