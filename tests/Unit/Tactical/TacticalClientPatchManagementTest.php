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

class TacticalClientPatchManagementTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    /** @param array<int, Response|\Throwable> $queue */
    private function clientReturning(array $queue): TacticalClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        $http = new GuzzleClient([
            'base_uri' => 'https://tactical.example.test/',
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

    public function test_winupdate_scan_approve_and_install_use_source_confirmed_shapes(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode([['id' => 44, 'kb' => 'KB5000001']])),
            new Response(200, [], json_encode('scan queued')),
            new Response(200, [], json_encode('Windows update KB5000001 was changed to approve')),
            new Response(200, [], json_encode('Approved patches will now be installed on PC-01')),
        ]);

        $this->assertSame([['id' => 44, 'kb' => 'KB5000001']], $client->getPatches('agent-1'));
        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/winupdate/agent-1/', $this->lastRequest()->getUri()->getPath());

        $this->assertSame('scan queued', $client->scanPatches('agent-1'));
        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/winupdate/agent-1/scan/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame([], $this->lastBody());

        $this->assertSame('Windows update KB5000001 was changed to approve', $client->setPatchAction(44, 'approve'));
        $this->assertSame('PUT', $this->lastRequest()->getMethod());
        $this->assertSame('/winupdate/44/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['action' => 'approve'], $this->lastBody());

        $this->assertSame('Approved patches will now be installed on PC-01', $client->installApprovedPatches('agent-1'));
        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/winupdate/agent-1/install/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame([], $this->lastBody());
    }

    public function test_patchpolicy_crud_and_reset_use_source_confirmed_shapes(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode('ok')),
            new Response(200, [], json_encode('ok')),
            new Response(200, [], json_encode('ok')),
            new Response(200, [], json_encode('ok')),
        ]);

        $body = [
            'policy' => 7,
            'critical' => 'approve',
            'important' => 'manual',
            'reboot_after_install' => 'never',
        ];

        $this->assertSame('ok', $client->createPatchPolicy($body));
        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/automation/patchpolicy/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame($body, $this->lastBody());

        $this->assertSame('ok', $client->updatePatchPolicy(501, ['critical' => 'ignore']));
        $this->assertSame('PUT', $this->lastRequest()->getMethod());
        $this->assertSame('/automation/patchpolicy/501/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['critical' => 'ignore'], $this->lastBody());

        $this->assertSame('ok', $client->deletePatchPolicy(501));
        $this->assertSame('DELETE', $this->lastRequest()->getMethod());
        $this->assertSame('/automation/patchpolicy/501/', $this->lastRequest()->getUri()->getPath());

        $this->assertSame('ok', $client->resetPatchPolicies(['site' => 77]));
        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/automation/patchpolicy/reset/', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['site' => 77], $this->lastBody());
    }

    public function test_patch_management_transport_failures_surface_as_tactical_client_exceptions(): void
    {
        $client = $this->clientReturning([
            new ConnectException('offline', new Request('POST', 'winupdate/agent-1/install/')),
        ]);

        try {
            $client->installApprovedPatches('agent-1');
            $this->fail('Expected TacticalClientException');
        } catch (TacticalClientException $e) {
            $this->assertTrue($e->isTransportFailure());
            $this->assertNull($e->statusCode());
        }
    }
}
