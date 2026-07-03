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

class TacticalClientScriptCrudTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    /** @param array<int, Response|\Throwable> $queue */
    private function clientReturning(array $queue): TacticalClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new TacticalClient(new GuzzleClient([
            'base_uri' => 'https://tactical.example.test/',
            'handler' => $stack,
            'headers' => ['X-API-KEY' => 'injected-key'],
        ]));
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

    public function test_script_crud_uses_source_confirmed_shapes(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode([['id' => 101, 'name' => 'Deploy']])),
            new Response(200, [], json_encode('Deploy was added!')),
            new Response(200, [], json_encode(['id' => 101, 'name' => 'Deploy', 'script_body' => 'Write-Host ok'])),
            new Response(200, [], json_encode('Deploy was edited!')),
            new Response(200, [], json_encode('Deploy was deleted!')),
            new Response(200, [], json_encode(['filename' => 'Deploy.ps1', 'code' => 'Write-Host ok'])),
        ]);

        $this->assertSame([['id' => 101, 'name' => 'Deploy']], $client->getScripts(showCommunityScripts: false, showHiddenScripts: true));
        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/scripts/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame('showCommunityScripts=false&showHiddenScripts=true', $this->lastRequest()->getUri()->getQuery());

        $body = [
            'name' => 'Deploy',
            'shell' => 'powershell',
            'script_body' => 'Write-Host ok',
            'default_timeout' => 90,
        ];

        $this->assertSame('Deploy was added!', $client->createScript($body));
        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/scripts/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame($body, $this->lastBody());

        $this->assertSame(['id' => 101, 'name' => 'Deploy', 'script_body' => 'Write-Host ok'], $client->getScriptDetail(101));
        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/scripts/101/', $this->lastRequest()->getUri()->getPath());

        $this->assertSame('Deploy was edited!', $client->updateScript(101, ['favorite' => true]));
        $this->assertSame('PUT', $this->lastRequest()->getMethod());
        $this->assertSame('/scripts/101/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['favorite' => true], $this->lastBody());

        $this->assertSame('Deploy was deleted!', $client->deleteScript(101));
        $this->assertSame('DELETE', $this->lastRequest()->getMethod());
        $this->assertSame('/scripts/101/', $this->lastRequest()->getUri()->getPath());

        $this->assertSame(['filename' => 'Deploy.ps1', 'code' => 'Write-Host ok'], $client->downloadScript(101, withSnippets: false));
        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/scripts/101/download/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame('with_snippets=false', $this->lastRequest()->getUri()->getQuery());
    }

    public function test_script_crud_transport_failures_surface_as_tactical_client_exception(): void
    {
        $client = $this->clientReturning([
            new ConnectException('offline', new Request('PUT', 'scripts/101/')),
        ]);

        try {
            $client->updateScript(101, ['hidden' => true]);
            $this->fail('Expected TacticalClientException');
        } catch (TacticalClientException $e) {
            $this->assertTrue($e->isTransportFailure());
            $this->assertNull($e->statusCode());
        }
    }
}
