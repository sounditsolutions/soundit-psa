<?php

namespace Tests\Unit\Unifi;

use App\Services\Unifi\UnifiClient;
use App\Services\Unifi\UnifiClientException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * UnifiClient read-path behavior (psa-1ynqc). Pure unit tests over an injected
 * Guzzle client backed by a MockHandler — no network, no Laravel container.
 *
 * EVERY fixture in tests/Fixtures/unifi/ is copied VERBATIM from the vendor's own
 * example payloads in the UniFi Site Manager OpenAPI spec:
 *   https://developer.ui.com/site-manager/v1.0.0/openapi.json  (OpenAPI 3.0.3, v1.0.0)
 * They are NOT authored from the shape this code expects — that is the whole point
 * (CLAUDE.md: "A mock you authored from the code under test proves nothing").
 *
 * Focus: the API-shape facts a naive wrapper gets wrong.
 *   1. Every response is enveloped: {data: [...], httpStatusCode, traceId, nextToken}.
 *      The cursor is TOP-LEVEL `nextToken` — NOT nested under a `pagination` key the
 *      way Huntress does it, and NOT an offset.
 *   2. GET /v1/devices is grouped BY HOST: data[] rows are HOSTS carrying a nested
 *      devices[] array. Treating data[] as a device list yields host-shaped rows with
 *      no mac/model/status at all.
 *   3. isp-metrics wan{} MIXES casing in a single object — avgLatency/maxLatency/
 *      packetLoss/ispName/ispAsn/downtime/uptime are camelCase, but download_kbps and
 *      upload_kbps are SNAKE_CASE. Normalizing them from memory silently returns null.
 *   4. Auth is the X-API-Key header (components.securitySchemes.site-manager-api-key).
 *   5. 429 is a documented response; a bump must back off and retry, not surface.
 */
class UnifiClientReadTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    /** @param array<int, Response|\Throwable> $queue */
    private function clientReturning(array $queue): UnifiClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        $http = new GuzzleClient([
            'base_uri' => 'https://api.ui.com/',
            'handler' => $stack,
        ]);

        return new UnifiClient(['api_key' => 'test-key'], $http);
    }

    private function fixture(string $name): Response
    {
        $path = base_path("tests/Fixtures/unifi/{$name}.json");

        return new Response(200, ['Content-Type' => 'application/json'], (string) file_get_contents($path));
    }

    private function lastRequest(): RequestInterface
    {
        return $this->history[array_key_last($this->history)]['request'];
    }

    public function test_it_sends_the_api_key_as_the_x_api_key_header(): void
    {
        $client = $this->clientReturning([$this->fixture('list_sites')]);

        $client->listSites();

        // components.securitySchemes.site-manager-api-key = {in: header, name: X-API-Key}
        $this->assertSame('test-key', $this->lastRequest()->getHeaderLine('X-API-Key'));
    }

    public function test_list_sites_unwraps_the_data_envelope_and_returns_the_top_level_next_token(): void
    {
        $client = $this->clientReturning([$this->fixture('list_sites')]);

        $result = $client->listSites();

        $this->assertSame('/v1/sites', $this->lastRequest()->getUri()->getPath());
        $this->assertCount(1, $result['data']);
        $this->assertSame('661de833b6b2463f0c20b319', $result['data'][0]['siteId']);
        // The cursor lives at the TOP level, not under a `pagination` wrapper.
        $this->assertSame('ba8e384e-3308-4236-b344-7357657351ca', $result['nextToken']);
    }

    public function test_site_statistics_carry_the_wan_health_fields_the_incident_needed(): void
    {
        $client = $this->clientReturning([$this->fixture('list_sites')]);

        $site = $client->listSites()['data'][0];

        // These four are exactly what T-22724 (Comcast WAN root-cause) required.
        $this->assertSame('Chunghwa Telecom', $site['statistics']['ispInfo']['name']);
        $this->assertSame(100, $site['statistics']['percentages']['wanUptime']);
        $this->assertSame([], $site['statistics']['internetIssues']);
        $this->assertSame(0, $site['statistics']['counts']['offlineDevice']);
        $this->assertSame('default', $site['meta']['name']);
    }

    public function test_list_devices_is_grouped_by_host_and_flattens_with_host_context(): void
    {
        $client = $this->clientReturning([$this->fixture('list_devices')]);

        $groups = $client->listDevices()['data'];

        // data[] rows are HOSTS, not devices — the nested devices[] is the device list.
        $this->assertArrayHasKey('devices', $groups[0]);
        $this->assertArrayNotHasKey('mac', $groups[0]);

        $flat = $client->flattenDevices($groups);

        $this->assertCount(1, $flat);
        $this->assertSame('F4E2C6C23F13', $flat[0]['mac']);
        $this->assertSame('online', $flat[0]['status']);
        $this->assertSame('UDM SE', $flat[0]['model']);
        // Host context is carried down onto each device so a flat row is still attributable.
        $this->assertSame('unifi.yourdomain.com', $flat[0]['hostName']);
        $this->assertSame(
            '900A6F00301100000000074A6BA90000000007A3387E0000000063EC9853:123456789',
            $flat[0]['hostId'],
        );
    }

    public function test_isp_metrics_preserve_the_vendors_mixed_snake_and_camel_case_wan_keys(): void
    {
        $client = $this->clientReturning([$this->fixture('get_isp_metrics_5m')]);

        $result = $client->getIspMetrics('5m', ['duration' => '24h']);

        $this->assertSame('/v1/isp-metrics/5m', $this->lastRequest()->getUri()->getPath());
        $this->assertStringContainsString('duration=24h', $this->lastRequest()->getUri()->getQuery());

        $wan = $result['data'][0]['periods'][0]['data']['wan'];

        // camelCase siblings...
        $this->assertSame(1, $wan['avgLatency']);
        $this->assertSame(2, $wan['maxLatency']);
        $this->assertSame(0, $wan['packetLoss']);
        $this->assertSame('12578', $wan['ispAsn']);
        // ...and SNAKE_CASE throughput keys in the very same object. This assertion is
        // the guard against anyone "tidying" these into downloadKbps/uploadKbps.
        $this->assertArrayHasKey('download_kbps', $wan);
        $this->assertArrayHasKey('upload_kbps', $wan);
        $this->assertArrayNotHasKey('downloadKbps', $wan);
    }

    public function test_it_backs_off_and_retries_once_on_a_429_rather_than_surfacing_it(): void
    {
        $client = $this->clientReturning([
            new Response(429, ['Retry-After' => '0']),
            $this->fixture('list_sites'),
        ]);

        $result = $client->listSites();

        $this->assertCount(1, $result['data']);
        $this->assertCount(2, $this->history, 'expected the 429 to be retried, not surfaced');
    }

    public function test_it_throws_a_typed_exception_when_the_api_rejects_the_key(): void
    {
        $client = $this->clientReturning([new Response(401, [], '{"httpStatusCode":401}')]);

        $this->expectException(UnifiClientException::class);

        $client->listSites();
    }

    public function test_a_degraded_response_missing_the_data_envelope_is_not_silently_an_empty_list(): void
    {
        // CLAUDE.md rule 3: a degraded read must SCREAM, never return a clean empty
        // result. An envelope with no `data` key is drift, not "no sites".
        $client = $this->clientReturning([
            new Response(200, [], '{"httpStatusCode":200,"traceId":"abc"}'),
        ]);

        $this->expectException(UnifiClientException::class);

        $client->listSites();
    }

    // ── allSites: the operator-surface cursor walk (psa-g5l80) ─────────────────

    private function sitesPage(array $siteIds, ?string $nextToken): Response
    {
        $rows = array_map(fn (string $id) => ['siteId' => $id, 'hostId' => 'host-1'], $siteIds);

        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => $rows,
            'httpStatusCode' => 200,
            'traceId' => 'trace',
            'nextToken' => $nextToken,
        ]));
    }

    public function test_all_sites_walks_the_cursor_and_concatenates_every_page(): void
    {
        $client = $this->clientReturning([
            $this->sitesPage(['site-1', 'site-2'], 'cursor-2'),
            $this->sitesPage(['site-3'], null),
        ]);

        $rows = $client->allSites();

        $this->assertSame(['site-1', 'site-2', 'site-3'], array_column($rows, 'siteId'));

        // The second request must carry the first page's top-level nextToken as the
        // cursor param — the vendor's cursor is NOT nested under `pagination`.
        parse_str($this->lastRequest()->getUri()->getQuery(), $query);
        $this->assertSame('cursor-2', $query['nextToken']);
    }

    public function test_all_sites_throws_on_cap_exhaustion_rather_than_returning_a_partial_list(): void
    {
        // A mapping screen missing sites it never fetched would read as "those sites
        // are gone" — cap exhaustion with a cursor still outstanding must SCREAM.
        $client = $this->clientReturning([
            $this->sitesPage(['site-1'], 'cursor-2'),
            $this->sitesPage(['site-2'], 'cursor-3'),
            $this->sitesPage(['site-3'], 'cursor-4'),
        ]);

        $this->expectException(UnifiClientException::class);
        $this->expectExceptionMessage('incomplete');

        $client->allSites(maxPages: 2);
    }
}
