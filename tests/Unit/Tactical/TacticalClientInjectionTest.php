<?php

namespace Tests\Unit\Tactical;

use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Task 1 (P2): the constructor-injection seam + structured error classification.
 *
 * Pure unit tests — no Laravel container, no DB. We hand TacticalClient a Guzzle
 * client backed by a MockHandler (the fault-injecting transport) and assert it is
 * used as-is, and that failures surface as TacticalClientException carrying the
 * STRUCTURED signal the bus (T5) classifies on (M2):
 *   - transport failure (ConnectException / timeout, no response, code 0) => offline-classifiable
 *   - HTTP response error (401/403/404/5xx) => carries the status, NOT offline-classifiable
 */
class TacticalClientInjectionTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    /**
     * Build a TacticalClient over an injected Guzzle client returning $queue in order.
     *
     * @param  array<int, Response|\Throwable>  $queue
     */
    private function clientReturning(array $queue): TacticalClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        $http = new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => $stack,
            'headers' => ['X-API-KEY' => 'injected-key'],
        ]);

        return new TacticalClient($http);
    }

    private function lastRequest(): RequestInterface
    {
        return $this->history[array_key_last($this->history)]['request'];
    }

    public function test_injected_client_is_used_and_not_reheadered(): void
    {
        $client = $this->clientReturning([new Response(200, [], json_encode([['agent_id' => 'a1']]))]);

        $result = $client->getAgents();

        $this->assertSame([['agent_id' => 'a1']], $result);
        // The injected client owns its headers; the config X-API-KEY must NOT be re-injected.
        $this->assertSame('injected-key', $this->lastRequest()->getHeaderLine('X-API-KEY'));
        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/agents/', $this->lastRequest()->getUri()->getPath());
    }

    public function test_connect_exception_is_a_transport_failure(): void
    {
        $client = $this->clientReturning([
            new ConnectException('Connection timed out', new Request('GET', 'agents/')),
        ]);

        try {
            $client->getAgents();
            $this->fail('Expected TacticalClientException');
        } catch (TacticalClientException $e) {
            $this->assertTrue($e->isTransportFailure(), 'ConnectException must classify as a transport failure');
            $this->assertNull($e->statusCode(), 'A transport failure has no HTTP status');
        }
    }

    public function test_http_403_carries_status_and_is_not_a_transport_failure(): void
    {
        $client = $this->clientReturning([new Response(403, [], 'forbidden by role')]);

        try {
            $client->getAgents();
            $this->fail('Expected TacticalClientException');
        } catch (TacticalClientException $e) {
            $this->assertFalse($e->isTransportFailure(), 'A 403 is an auth failure, never offline');
            $this->assertSame(403, $e->statusCode());
            $this->assertStringContainsString('forbidden by role', (string) $e->responseBody());
        }
    }
}
