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
 * Task 1 (P3): the transport seam for the four new remote actions —
 * cmd / shutdown / recover / setMaintenance. Pure unit tests over an injected
 * Guzzle client backed by a MockHandler (no container, no DB). We assert the
 * EXACT path + body each method sends (the request shape is the contract the
 * live box is verified against) and that every method is scalar-safe (typed
 * `mixed`, like reboot()/runScript()) so a non-object reply never TypeErrors.
 */
class TacticalClientRemoteActionsTest extends TestCase
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

    /** @return array<string, mixed> */
    private function lastBody(): array
    {
        return json_decode((string) $this->lastRequest()->getBody(), true) ?? [];
    }

    public function test_cmd_posts_the_pinned_body_to_the_cmd_endpoint(): void
    {
        $client = $this->clientReturning([new Response(200, [], json_encode('hostname-output'))]);

        $result = $client->cmd('AGENT-1', 'whoami', 'cmd', 30);

        // Scalar-safe: the cmd endpoint returns a bare string (spec §3).
        $this->assertSame('hostname-output', $result);

        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/agents/AGENT-1/cmd/', $this->lastRequest()->getUri()->getPath());

        // C1: the body is pinned EXACTLY — custom_shell hardcoded null,
        // run_as_user false, env_vars []. Nothing here is derived from input.
        $this->assertSame([
            'shell' => 'cmd',
            'cmd' => 'whoami',
            'timeout' => 30,
            'custom_shell' => null,
            'run_as_user' => false,
            'env_vars' => [],
        ], $this->lastBody());
    }

    public function test_cmd_custom_shell_is_always_null_never_from_input(): void
    {
        // The method signature carries no custom_shell parameter, so it can never
        // be threaded from a caller — the body must always pin it to null.
        $client = $this->clientReturning([new Response(200, [], json_encode(''))]);

        $client->cmd('AGENT-1', 'Get-Process', 'powershell', 60);

        $body = $this->lastBody();
        $this->assertArrayHasKey('custom_shell', $body);
        $this->assertNull($body['custom_shell']);
        $this->assertFalse($body['run_as_user']);
        $this->assertSame([], $body['env_vars']);
        $this->assertSame('powershell', $body['shell']);
    }

    public function test_shutdown_posts_and_tolerates_a_scalar_ok(): void
    {
        // Like reboot, shutdown returns the JSON scalar "ok" — typed `mixed` so a
        // narrower `: array` return would TypeError here.
        $client = $this->clientReturning([new Response(200, [], json_encode('ok'))]);

        $result = $client->shutdown('AGENT-1');

        $this->assertSame('ok', $result);
        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/agents/AGENT-1/shutdown/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame([], $this->lastBody());
    }

    public function test_recover_posts_the_mode_to_the_recover_endpoint(): void
    {
        $client = $this->clientReturning([new Response(200, [], json_encode('ok'))]);

        $result = $client->recover('AGENT-1', 'mesh');

        $this->assertSame('ok', $result);
        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/agents/AGENT-1/recover/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['mode' => 'mesh'], $this->lastBody());
    }

    public function test_set_maintenance_puts_a_partial_body_to_the_agent(): void
    {
        // D3: partial PUT {maintenance_mode: bool} to /agents/<id>/ — mirrors the
        // live setAgentCustomField partial-PUT precedent (no read-modify-write).
        $client = $this->clientReturning([new Response(200, [], json_encode(['maintenance_mode' => true]))]);

        $client->setMaintenance('AGENT-1', true);

        $this->assertSame('PUT', $this->lastRequest()->getMethod());
        $this->assertSame('/agents/AGENT-1/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['maintenance_mode' => true], $this->lastBody());
    }

    public function test_set_maintenance_can_disable(): void
    {
        $client = $this->clientReturning([new Response(200, [], json_encode(['maintenance_mode' => false]))]);

        $client->setMaintenance('AGENT-1', false);

        $this->assertSame(['maintenance_mode' => false], $this->lastBody());
    }

    public function test_a_connect_exception_surfaces_as_a_tactical_client_exception(): void
    {
        // A transport failure on any of the four must surface as the structured
        // exception the bus classifies on (offline), not a raw GuzzleException.
        $client = $this->clientReturning([
            new ConnectException('timed out', new Request('POST', 'agents/AGENT-1/cmd/')),
        ]);

        try {
            $client->cmd('AGENT-1', 'whoami', 'cmd', 30);
            $this->fail('Expected TacticalClientException');
        } catch (TacticalClientException $e) {
            $this->assertTrue($e->isTransportFailure());
            $this->assertNull($e->statusCode());
        }
    }
}
