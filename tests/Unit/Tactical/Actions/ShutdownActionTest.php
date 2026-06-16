<?php

namespace Tests\Unit\Tactical\Actions;

use App\Services\Tactical\Actions\ShutdownAction;
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
 * Task 3 (P3): Shutdown — a destructive Reboot near-clone (D6: no shared base,
 * the ~6 trivial lines are duplicated; the bus is the only shared spine). The
 * critical difference from reboot is irreversibility: the box stays OFF and
 * cannot be powered back on remotely, so summary() must say so verbatim (D2) —
 * that text lands in the confirm modal AND the persisted audit message.
 */
class ShutdownActionTest extends TestCase
{
    private ShutdownAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new ShutdownAction;
    }

    public function test_key_and_destructive(): void
    {
        $this->assertSame('tactical.shutdown', $this->action->key());
        $this->assertTrue($this->action->isDestructive());
    }

    public function test_validate_params_is_trivial(): void
    {
        $this->assertSame([], $this->action->validateParams(['junk' => 'ignored']));
    }

    public function test_summary_warns_the_device_cannot_be_powered_on_remotely(): void
    {
        // D2 (binding, verbatim): the device-specific irreversibility consequence.
        $summary = $this->action->summary([]);

        $this->assertStringContainsString(
            'this device powers off and cannot be powered back on remotely; recovery requires physical/IPMI access',
            $summary,
        );
    }

    public function test_execute_posts_to_the_shutdown_endpoint_and_returns_ok(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], json_encode([]))]));
        $stack->push(Middleware::history($history));
        $client = new TacticalClient(new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack]));

        $result = $this->action->execute($client, 'AGENT-1', []);

        $this->assertTrue($result->isOk());

        /** @var RequestInterface $req */
        $req = $history[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/agents/AGENT-1/shutdown/', $req->getUri()->getPath());
    }

    public function test_execute_lets_a_transport_failure_bubble_for_the_bus(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new ConnectException('timed out', new Request('POST', 'agents/AGENT-1/shutdown/')),
        ]));
        $client = new TacticalClient(new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack]));

        try {
            $this->action->execute($client, 'AGENT-1', []);
            $this->fail('Expected TacticalClientException to bubble');
        } catch (TacticalClientException $e) {
            $this->assertTrue($e->isTransportFailure());
        }
    }

    public function test_action_execute_succeeds_on_a_scalar_shutdown_response(): void
    {
        // Mirror RebootActionTest's scalar test: shutdown returns the JSON scalar
        // "ok"; the action must yield an ok result, not TypeError on array access.
        $client = new TacticalClient(new GuzzleClient([
            'base_uri' => 'https://t.example.com/',
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, [], json_encode('ok')),
            ])),
        ]));

        $result = $this->action->execute($client, 'AGENT-1', []);

        $this->assertTrue($result->isOk());
    }
}
