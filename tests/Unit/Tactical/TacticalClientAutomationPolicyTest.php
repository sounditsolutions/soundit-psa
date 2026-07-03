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

class TacticalClientAutomationPolicyTest extends TestCase
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

    public function test_automation_policy_crud_and_assignment_use_source_confirmed_shapes(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode([['id' => 7, 'name' => 'Workstations']])),
            new Response(200, [], json_encode('ok')),
            new Response(200, [], json_encode(['id' => 7, 'name' => 'Workstations', 'active' => true])),
            new Response(200, [], json_encode('ok')),
            new Response(200, [], json_encode(['pk' => 7, 'name' => 'Workstations', 'agents' => []])),
            new Response(200, [], json_encode('ok')),
            new Response(200, [], json_encode('{client} was updated')),
            new Response(200, [], json_encode('Site was edited')),
            new Response(200, [], json_encode('The agent was updated successfully')),
        ]);

        $this->assertSame([['id' => 7, 'name' => 'Workstations']], $client->getPolicies());
        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/automation/policies/', $this->lastRequest()->getUri()->getPath());

        $body = ['name' => 'Workstations', 'desc' => 'Default workstations', 'active' => true, 'copyId' => 3];
        $this->assertSame('ok', $client->createAutomationPolicy($body));
        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/automation/policies/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame($body, $this->lastBody());

        $this->assertSame(['id' => 7, 'name' => 'Workstations', 'active' => true], $client->getAutomationPolicy(7));
        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/automation/policies/7/', $this->lastRequest()->getUri()->getPath());

        $this->assertSame('ok', $client->updateAutomationPolicy(7, ['enforced' => true]));
        $this->assertSame('PUT', $this->lastRequest()->getMethod());
        $this->assertSame('/automation/policies/7/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['enforced' => true], $this->lastBody());

        $this->assertSame(['pk' => 7, 'name' => 'Workstations', 'agents' => []], $client->getAutomationPolicyRelated(7));
        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/automation/policies/7/related/', $this->lastRequest()->getUri()->getPath());

        $this->assertSame('ok', $client->deleteAutomationPolicy(7));
        $this->assertSame('DELETE', $this->lastRequest()->getMethod());
        $this->assertSame('/automation/policies/7/', $this->lastRequest()->getUri()->getPath());

        $this->assertSame('{client} was updated', $client->updateClientPolicies(55, ['workstation_policy' => 7]));
        $this->assertSame('PUT', $this->lastRequest()->getMethod());
        $this->assertSame('/clients/55/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['client' => ['workstation_policy' => 7]], $this->lastBody());

        $this->assertSame('Site was edited', $client->updateSitePolicies(77, ['server_policy' => 8, 'block_policy_inheritance' => false]));
        $this->assertSame('PUT', $this->lastRequest()->getMethod());
        $this->assertSame('/clients/sites/77/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['site' => ['server_policy' => 8, 'block_policy_inheritance' => false]], $this->lastBody());

        $this->assertSame('The agent was updated successfully', $client->updateAgentPolicy('agent-1', ['policy' => 7, 'block_policy_inheritance' => true]));
        $this->assertSame('PUT', $this->lastRequest()->getMethod());
        $this->assertSame('/agents/agent-1/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['policy' => 7, 'block_policy_inheritance' => true], $this->lastBody());
    }

    public function test_automation_policy_transport_failures_surface_as_tactical_client_exception(): void
    {
        $client = $this->clientReturning([
            new ConnectException('offline', new Request('DELETE', 'automation/policies/7/')),
        ]);

        try {
            $client->deleteAutomationPolicy(7);
            $this->fail('Expected TacticalClientException');
        } catch (TacticalClientException $e) {
            $this->assertTrue($e->isTransportFailure());
            $this->assertNull($e->statusCode());
        }
    }
}
