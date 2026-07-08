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

class TacticalClientTaskCrudTest extends TestCase
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

    public function test_task_crud_aliases_and_runs_use_source_confirmed_shapes(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode([['id' => 21, 'name' => 'Daily cleanup']])),
            new Response(200, [], json_encode([['id' => 21, 'name' => 'Daily cleanup']])),
            new Response(200, [], json_encode([['id' => 22, 'name' => 'Policy cleanup']])),
            new Response(200, [], json_encode([['id' => 31, 'name' => 'Detector', 'check_type' => 'script']])),
            new Response(200, [], json_encode('created')),
            new Response(200, [], json_encode('Script Check: Detector was added!')),
            new Response(200, [], json_encode(['id' => 21, 'name' => 'Daily cleanup'])),
            new Response(200, [], json_encode('updated')),
            new Response(200, [], json_encode('Daily cleanup will be deleted shortly')),
            new Response(200, [], json_encode('Daily cleanup will now be run.')),
            new Response(200, [], json_encode('Policy cleanup will now be run.')),
            new Response(200, [], json_encode('Affected agent tasks will run shortly')),
        ]);

        $this->assertSame([['id' => 21, 'name' => 'Daily cleanup']], $client->getTasks());
        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/tasks/', $this->lastRequest()->getUri()->getPath());

        $this->assertSame([['id' => 21, 'name' => 'Daily cleanup']], $client->getAgentTasks('agent-1'));
        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/agents/agent-1/tasks/', $this->lastRequest()->getUri()->getPath());

        $this->assertSame([['id' => 22, 'name' => 'Policy cleanup']], $client->getPolicyTasks(7));
        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/automation/policies/7/tasks/', $this->lastRequest()->getUri()->getPath());

        $this->assertSame([['id' => 31, 'name' => 'Detector', 'check_type' => 'script']], $client->getPolicyChecks(7));
        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/automation/policies/7/checks/', $this->lastRequest()->getUri()->getPath());

        $body = [
            'agent' => 'agent-1',
            'name' => 'Daily cleanup',
            'enabled' => true,
            'task_type' => 'daily',
            'run_time_date' => '2026-07-04T03:00:00Z',
            'daily_interval' => 1,
            'actions' => [['type' => 'cmd', 'shell' => 'powershell', 'command' => 'whoami', 'timeout' => 30]],
        ];
        $this->assertSame('created', $client->createTask($body));
        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/tasks/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame($body, $this->lastBody());

        $checkBody = [
            'policy' => 7,
            'check_type' => 'script',
            'script' => 102,
            'success_return_codes' => [0],
            'info_return_codes' => [],
            'warning_return_codes' => [7],
            'fails_b4_alert' => 2,
        ];
        $this->assertSame('Script Check: Detector was added!', $client->createCheck($checkBody));
        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/checks/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame($checkBody, $this->lastBody());

        $this->assertSame(['id' => 21, 'name' => 'Daily cleanup'], $client->getTask(21));
        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/tasks/21/', $this->lastRequest()->getUri()->getPath());

        $this->assertSame('updated', $client->updateTask(21, ['enabled' => false]));
        $this->assertSame('PUT', $this->lastRequest()->getMethod());
        $this->assertSame('/tasks/21/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['enabled' => false], $this->lastBody());

        $this->assertSame('Daily cleanup will be deleted shortly', $client->deleteTask(21));
        $this->assertSame('DELETE', $this->lastRequest()->getMethod());
        $this->assertSame('/tasks/21/', $this->lastRequest()->getUri()->getPath());

        $this->assertSame('Daily cleanup will now be run.', $client->runTask(21));
        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/tasks/21/run/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame([], $this->lastBody());

        $this->assertSame('Policy cleanup will now be run.', $client->runTask(22, 'agent-1'));
        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/tasks/22/run/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['agent_id' => 'agent-1'], $this->lastBody());

        $this->assertSame('Affected agent tasks will run shortly', $client->runPolicyTask(22));
        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/automation/tasks/22/run/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame([], $this->lastBody());
    }

    public function test_task_transport_failures_surface_as_tactical_client_exception(): void
    {
        $client = $this->clientReturning([
            new ConnectException('offline', new Request('POST', 'automation/tasks/22/run/')),
        ]);

        try {
            $client->runPolicyTask(22);
            $this->fail('Expected TacticalClientException');
        } catch (TacticalClientException $e) {
            $this->assertTrue($e->isTransportFailure());
            $this->assertNull($e->statusCode());
        }
    }
}
