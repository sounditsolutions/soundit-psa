<?php

namespace Tests\Unit\Tactical\Actions;

use App\Services\Tactical\Actions\InvalidActionParams;
use App\Services\Tactical\Actions\SetMaintenanceAction;
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
 * Task 5 (P3): toggle maintenance mode — NON-destructive (alert suppression is
 * reversible; audited, no confirm token). validateParams requires `enabled` and
 * coerces it to a strict bool; execute() drives the D3 partial-PUT via
 * TacticalClient::setMaintenance.
 */
class SetMaintenanceActionTest extends TestCase
{
    private SetMaintenanceAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new SetMaintenanceAction;
    }

    public function test_key_and_non_destructive(): void
    {
        $this->assertSame('tactical.set_maintenance', $this->action->key());
        $this->assertFalse($this->action->isDestructive());
    }

    public function test_validate_requires_enabled(): void
    {
        $this->expectException(InvalidActionParams::class);
        $this->action->validateParams([]);
    }

    public function test_validate_coerces_truthy_values_to_true(): void
    {
        foreach ([true, 'true', '1', 1, 'on'] as $truthy) {
            $params = $this->action->validateParams(['enabled' => $truthy]);
            $this->assertTrue($params['enabled'], 'expected true for '.var_export($truthy, true));
        }
    }

    public function test_validate_coerces_falsy_values_to_false(): void
    {
        foreach ([false, 'false', '0', 0, 'off'] as $falsy) {
            $params = $this->action->validateParams(['enabled' => $falsy]);
            $this->assertFalse($params['enabled'], 'expected false for '.var_export($falsy, true));
        }
    }

    public function test_validate_returns_a_strict_bool(): void
    {
        $params = $this->action->validateParams(['enabled' => '1']);
        $this->assertIsBool($params['enabled']);
    }

    public function test_summary_reads_enable_when_on(): void
    {
        $summary = $this->action->summary(['enabled' => true]);

        $this->assertStringContainsStringIgnoringCase('enable', $summary);
        $this->assertStringContainsStringIgnoringCase('maintenance', $summary);
    }

    public function test_summary_reads_disable_when_off(): void
    {
        $summary = $this->action->summary(['enabled' => false]);

        $this->assertStringContainsStringIgnoringCase('disable', $summary);
        $this->assertStringContainsStringIgnoringCase('maintenance', $summary);
    }

    public function test_execute_puts_the_partial_maintenance_body(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], json_encode(['maintenance_mode' => true]))]));
        $stack->push(Middleware::history($history));
        $client = new TacticalClient(new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack]));

        $params = $this->action->validateParams(['enabled' => true]);
        $result = $this->action->execute($client, 'AGENT-1', $params);

        $this->assertTrue($result->isOk());

        /** @var RequestInterface $req */
        $req = $history[0]['request'];
        $this->assertSame('PUT', $req->getMethod());
        $this->assertSame('/agents/AGENT-1/', $req->getUri()->getPath());
        $this->assertSame(['maintenance_mode' => true], json_decode((string) $req->getBody(), true));
    }

    public function test_execute_can_disable(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], json_encode(['maintenance_mode' => false]))]));
        $stack->push(Middleware::history($history));
        $client = new TacticalClient(new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack]));

        $params = $this->action->validateParams(['enabled' => false]);
        $this->action->execute($client, 'AGENT-1', $params);

        /** @var RequestInterface $req */
        $req = $history[0]['request'];
        $this->assertSame(['maintenance_mode' => false], json_decode((string) $req->getBody(), true));
    }

    public function test_execute_lets_a_transport_failure_bubble_for_the_bus(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new ConnectException('timed out', new Request('PUT', 'agents/AGENT-1/')),
        ]));
        $client = new TacticalClient(new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack]));

        $params = $this->action->validateParams(['enabled' => true]);

        try {
            $this->action->execute($client, 'AGENT-1', $params);
            $this->fail('Expected TacticalClientException to bubble');
        } catch (TacticalClientException $e) {
            $this->assertTrue($e->isTransportFailure());
        }
    }
}
