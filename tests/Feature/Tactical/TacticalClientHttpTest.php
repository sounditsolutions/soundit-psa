<?php

namespace Tests\Feature\Tactical;

use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Characterization tests for TacticalClient's HTTP behaviour. No behaviour change —
 * these lock in the CURRENT contract (X-API-KEY header, endpoint paths/verbs, and
 * non-2xx -> TacticalClientException mapping).
 *
 * P2/m4: the reflection-based $http swap from P1 is RETIRED. TacticalClient now
 * accepts an injected Guzzle client (Task 1 seam), so we build the mock transport
 * and hand it to `new TacticalClient($mockHttp)` directly. The injected client
 * carries the X-API-KEY default header itself (the constructor does not re-inject
 * config headers onto a provided client).
 */
class TacticalClientHttpTest extends TestCase
{
    private string $apiKey = 'svc-user-api-key-abc123';

    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    /**
     * Build a TacticalClient over an injected mock transport that returns $queue
     * in order, while applying the same default X-API-KEY header production uses.
     *
     * @param  Response[]  $queue
     */
    private function clientReturning(array $queue): TacticalClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        $mockHttp = new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => $stack,
            'allow_redirects' => false,
            'headers' => [
                'X-API-KEY' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);

        return new TacticalClient($mockHttp);
    }

    private function lastRequest(): RequestInterface
    {
        return $this->history[array_key_last($this->history)]['request'];
    }

    public function test_requests_send_the_api_key_header(): void
    {
        $client = $this->clientReturning([new Response(200, [], json_encode([]))]);

        $client->getAgents();

        $this->assertSame($this->apiKey, $this->lastRequest()->getHeaderLine('X-API-KEY'));
    }

    public function test_get_agents_hits_the_agents_endpoint(): void
    {
        $client = $this->clientReturning([new Response(200, [], json_encode([['agent_id' => 'a1']]))]);

        $result = $client->getAgents();

        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/agents/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame([['agent_id' => 'a1']], $result);
    }

    public function test_run_script_posts_to_the_runscript_endpoint(): void
    {
        $client = $this->clientReturning([new Response(200, [], json_encode(['stdout' => 'ok', 'retcode' => 0]))]);

        $result = $client->runScript('AGENT123', 201, ['--foo'], 90);

        $req = $this->lastRequest();
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/agents/AGENT123/runscript/', $req->getUri()->getPath());

        $body = json_decode((string) $req->getBody(), true);
        $this->assertSame('wait', $body['output']);
        $this->assertSame(201, $body['script']);
        $this->assertSame(['--foo'], $body['args']);
        $this->assertSame(['stdout' => 'ok', 'retcode' => 0], $result);
    }

    public function test_patch_alerts_hits_the_alerts_endpoint(): void
    {
        $client = $this->clientReturning([new Response(200, [], json_encode([['id' => 1, 'resolved' => true]]))]);

        $result = $client->patch('alerts/', ['timeFilter' => 30]);

        $this->assertSame('PATCH', $this->lastRequest()->getMethod());
        $this->assertSame('/alerts/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame([['id' => 1, 'resolved' => true]], $result);
    }

    public function test_non_2xx_response_is_mapped_to_tactical_exception(): void
    {
        $client = $this->clientReturning([new Response(500, [], 'upstream boom')]);

        $this->expectException(TacticalClientException::class);

        $client->getAgents();
    }

    public function test_is_healthy_returns_false_on_error(): void
    {
        $client = $this->clientReturning([new Response(401, [], 'nope')]);

        // isHealthy() swallows TacticalClientException and returns false.
        $this->assertFalse($client->isHealthy());
    }

    public function test_is_healthy_returns_true_on_success(): void
    {
        $client = $this->clientReturning([new Response(200, [], json_encode([]))]);

        $this->assertTrue($client->isHealthy());
    }
}
