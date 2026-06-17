<?php

namespace Tests\Feature\Tactical;

use App\Services\Tactical\TacticalClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * TDD tests for TacticalClient::getMeshCentralLinks (P6 Task 1).
 *
 * Uses the same injected-MockHandler pattern as TacticalClientHttpTest —
 * a pre-built GuzzleClient is passed directly to `new TacticalClient($http)`
 * so SSRF-pin middleware is NOT active (no real DNS lookup in unit tests).
 */
class TacticalMeshCentralTest extends TestCase
{
    private string $apiKey = 'svc-user-api-key-abc123';

    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    /**
     * Build a TacticalClient over an injected mock transport.
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

    public function test_getMeshCentralLinks_hits_the_agent_meshcentral_endpoint(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode([
                'hostname' => 'BOX',
                'control' => 'https://mesh.example.com/?login=ctrl',
                'terminal' => 'https://mesh.example.com/?login=term',
                'file' => 'https://mesh.example.com/?login=file',
            ])),
        ]);

        $links = $client->getMeshCentralLinks('AGENT-1');

        $this->assertSame('https://mesh.example.com/?login=ctrl', $links['control']);
        $this->assertStringContainsString(
            'agents/AGENT-1/meshcentral',
            (string) $this->history[0]['request']->getUri()
        );
    }

    public function test_getMeshCentralLinks_returns_all_fields(): void
    {
        $payload = [
            'hostname' => 'WORKSTATION-42',
            'control' => 'https://mesh.example.com/?login=ctrl',
            'terminal' => 'https://mesh.example.com/?login=term',
            'file' => 'https://mesh.example.com/?login=file',
            'status' => 'online',
            'client' => 'Acme Corp',
            'site' => 'Main',
        ];

        $client = $this->clientReturning([
            new Response(200, [], json_encode($payload)),
        ]);

        $links = $client->getMeshCentralLinks('AGENT-42');

        $this->assertSame($payload, $links);
    }

    public function test_getMeshCentralLinks_uses_get_verb(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode(['hostname' => 'BOX'])),
        ]);

        $client->getMeshCentralLinks('AGENT-1');

        $this->assertSame('GET', $this->history[0]['request']->getMethod());
    }
}
