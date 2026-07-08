<?php

namespace Tests\Unit\Tactical\Actions;

use App\Services\Tactical\Actions\RebootAction;
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
 * Task 8 (P2): Reboot — the first destructive action end-to-end. The bus (T5)
 * gates it on a confirm token; here we test the action in isolation.
 */
class RebootActionTest extends TestCase
{
    private RebootAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new RebootAction;
    }

    public function test_key_and_destructive(): void
    {
        $this->assertSame('tactical.reboot', $this->action->key());
        $this->assertTrue($this->action->isDestructive());
    }

    public function test_validate_params_is_trivial(): void
    {
        // Reboot takes no params; validateParams returns an empty normalized set.
        $this->assertSame([], $this->action->validateParams(['junk' => 'ignored']));
    }

    public function test_summary_mentions_reboot(): void
    {
        $this->assertStringContainsStringIgnoringCase('reboot', $this->action->summary([]));
    }

    public function test_execute_posts_to_the_reboot_endpoint_and_returns_ok(): void
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
        $this->assertSame('/agents/AGENT-1/reboot/', $req->getUri()->getPath());
    }

    public function test_execute_lets_a_transport_failure_bubble_for_the_bus_to_classify(): void
    {
        // The action does NOT catch — the bus catches TacticalClientException and
        // classifies offline vs error (M2). Here we just confirm it propagates.
        $stack = HandlerStack::create(new MockHandler([
            new ConnectException('timed out', new Request('POST', 'agents/AGENT-1/reboot/')),
        ]));
        $client = new TacticalClient(new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack]));

        try {
            $this->action->execute($client, 'AGENT-1', []);
            $this->fail('Expected TacticalClientException to bubble');
        } catch (TacticalClientException $e) {
            $this->assertTrue($e->isTransportFailure());
        }
    }

    public function test_reboot_handles_a_scalar_string_response_without_throwing(): void
    {
        // Regression (live-verified): POST /agents/{id}/reboot/ returns the JSON
        // scalar "ok", not an object. reboot()/post() are typed `: mixed`; a
        // narrower `: array` return type would TypeError here.
        $client = new TacticalClient(new GuzzleClient([
            'base_uri' => 'https://t.example.com/',
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, [], json_encode('ok')), // body is the JSON string "ok"
            ])),
        ]));

        $response = $client->reboot('AGENT-1');

        $this->assertSame('ok', $response);
    }

    public function test_action_execute_succeeds_on_a_scalar_reboot_response(): void
    {
        // The same scalar response, through the action: must yield an ok result.
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
