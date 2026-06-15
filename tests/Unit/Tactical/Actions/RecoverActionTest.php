<?php

namespace Tests\Unit\Tactical\Actions;

use App\Services\Tactical\Actions\InvalidActionParams;
use App\Services\Tactical\Actions\RecoverAction;
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
 * Task 4 (P3): Recover services — NON-destructive (no confirm token at the bus).
 * Amendment D4: P3 ships `mode=mesh` ONLY (synchronous, reports the real
 * outcome). `tacagent` is async upstream and is REJECTED here — PSA must never
 * fire an untrackable async call the UI might present as completed; async
 * recover ships with the bulk/async phase (psa-d76b).
 */
class RecoverActionTest extends TestCase
{
    private RecoverAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new RecoverAction;
    }

    public function test_key_and_non_destructive(): void
    {
        $this->assertSame('tactical.recover', $this->action->key());
        $this->assertFalse($this->action->isDestructive());
    }

    public function test_mode_defaults_to_mesh(): void
    {
        $params = $this->action->validateParams([]);

        $this->assertSame('mesh', $params['mode']);
    }

    public function test_mode_accepts_mesh_explicitly(): void
    {
        $params = $this->action->validateParams(['mode' => 'mesh']);

        $this->assertSame('mesh', $params['mode']);
    }

    public function test_mode_rejects_tacagent_as_deferred(): void
    {
        // D4: tacagent is async — rejected in P3 (deferred to psa-d76b).
        $this->expectException(InvalidActionParams::class);
        $this->action->validateParams(['mode' => 'tacagent']);
    }

    public function test_tacagent_rejection_names_the_async_phase(): void
    {
        try {
            $this->action->validateParams(['mode' => 'tacagent']);
            $this->fail('Expected InvalidActionParams');
        } catch (InvalidActionParams $e) {
            $this->assertStringContainsString('async', strtolower($e->getMessage()));
            $this->assertStringContainsString('psa-d76b', $e->getMessage());
        }
    }

    public function test_mode_rejects_an_unknown_value(): void
    {
        $this->expectException(InvalidActionParams::class);
        $this->action->validateParams(['mode' => 'nonsense']);
    }

    public function test_summary_names_the_mode_in_plain_english(): void
    {
        $summary = $this->action->summary(['mode' => 'mesh']);

        $this->assertStringContainsStringIgnoringCase('recover', $summary);
        $this->assertStringContainsString('mesh', $summary);
    }

    public function test_execute_posts_the_mode_to_the_recover_endpoint(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], json_encode('ok'))]));
        $stack->push(Middleware::history($history));
        $client = new TacticalClient(new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack]));

        $params = $this->action->validateParams(['mode' => 'mesh']);
        $result = $this->action->execute($client, 'AGENT-1', $params);

        $this->assertTrue($result->isOk());

        /** @var RequestInterface $req */
        $req = $history[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/agents/AGENT-1/recover/', $req->getUri()->getPath());
        $this->assertSame(['mode' => 'mesh'], json_decode((string) $req->getBody(), true));
    }

    public function test_execute_normalizes_a_scalar_recover_response(): void
    {
        // The recover endpoint may return a scalar message; normalize, don't TypeError.
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], json_encode('Services recovered.'))]));
        $client = new TacticalClient(new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack]));

        $params = $this->action->validateParams([]);
        $result = $this->action->execute($client, 'AGENT-1', $params);

        $this->assertTrue($result->isOk());
        $this->assertStringContainsString('Services recovered.', (string) $result->stdout);
    }

    public function test_execute_lets_a_transport_failure_bubble_for_the_bus(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new ConnectException('timed out', new Request('POST', 'agents/AGENT-1/recover/')),
        ]));
        $client = new TacticalClient(new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack]));

        $params = $this->action->validateParams([]);

        try {
            $this->action->execute($client, 'AGENT-1', $params);
            $this->fail('Expected TacticalClientException to bubble');
        } catch (TacticalClientException $e) {
            $this->assertTrue($e->isTransportFailure());
        }
    }
}
