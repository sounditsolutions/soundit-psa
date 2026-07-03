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

class TacticalClientServiceControlTest extends TestCase
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

    public function test_get_services_uses_source_confirmed_endpoint(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode([
                ['name' => 'Spooler', 'display_name' => 'Print Spooler'],
            ])),
        ]);

        $services = $client->getServices('AGENT-1');

        $this->assertSame('Spooler', $services[0]['name']);
        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/services/AGENT-1/', $this->lastRequest()->getUri()->getPath());
    }

    public function test_control_service_posts_source_confirmed_body_and_encodes_service_path(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode('The service was restarted successfully')),
        ]);

        $result = $client->controlService('AGENT-1', 'Print Spooler', 'restart');

        $this->assertSame('The service was restarted successfully', $result);
        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/services/AGENT-1/Print%20Spooler/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['sv_action' => 'restart'], $this->lastBody());
    }

    public function test_set_service_start_type_puts_source_confirmed_body(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode('The service start type was updated successfully')),
        ]);

        $result = $client->setServiceStartType('AGENT-1', 'Spooler', 'autodelay');

        $this->assertSame('The service start type was updated successfully', $result);
        $this->assertSame('PUT', $this->lastRequest()->getMethod());
        $this->assertSame('/services/AGENT-1/Spooler/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['startType' => 'autodelay'], $this->lastBody());
    }

    public function test_service_control_transport_failures_surface_as_tactical_client_exception(): void
    {
        $client = $this->clientReturning([
            new ConnectException('timed out', new Request('POST', 'services/AGENT-1/Spooler/')),
        ]);

        try {
            $client->controlService('AGENT-1', 'Spooler', 'stop');
            $this->fail('Expected TacticalClientException');
        } catch (TacticalClientException $e) {
            $this->assertTrue($e->isTransportFailure());
            $this->assertNull($e->statusCode());
        }
    }
}
