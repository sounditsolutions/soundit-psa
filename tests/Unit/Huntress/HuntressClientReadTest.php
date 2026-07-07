<?php

namespace Tests\Unit\Huntress;

use App\Services\Huntress\HuntressClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * HuntressClient read-path behavior (psa-shej). Pure unit tests over an injected
 * Guzzle client backed by a MockHandler — no network, no Laravel container.
 *
 * Focus: the two API-shape facts a naive wrapper gets wrong (from
 * api.huntress.io/v1/swagger_doc.json):
 *   1. GET /escalations/{id} returns the escalation object DIRECTLY — no
 *      {escalation:{...}} wrapper (unlike incident_reports / organizations).
 *   2. The account is rate-limited (60 rpm); a 429 must back off and retry,
 *      not surface as an error on the first bump.
 */
class HuntressClientReadTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    /**
     * @param  array<int, Response|\Throwable>  $queue
     */
    private function clientReturning(array $queue): HuntressClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        $http = new GuzzleClient([
            'base_uri' => 'https://api.huntress.io/v1/',
            'handler' => $stack,
        ]);

        return new HuntressClient(['api_key' => 'k', 'api_secret' => 's'], $http);
    }

    private function lastPath(): string
    {
        return $this->history[array_key_last($this->history)]['request']->getUri()->getPath();
    }

    public function test_get_escalation_returns_the_unwrapped_object_with_no_wrapper_key(): void
    {
        // GET /escalations/{id} → EscalationWithEntities DIRECTLY (no {escalation} wrapper).
        $escalation = ['id' => 77, 'status' => 'resolved', 'subject' => 'Failed to Deliver', 'entities' => ['foo' => 'bar']];
        $client = $this->clientReturning([new Response(200, [], json_encode($escalation))]);

        $result = $client->getEscalation(77);

        $this->assertSame(77, $result['id']);
        $this->assertSame('resolved', $result['status']);
        $this->assertSame('Failed to Deliver', $result['subject']);
        $this->assertSame(['foo' => 'bar'], $result['entities']);
        $this->assertSame('/v1/escalations/77', $this->lastPath());
    }

    public function test_get_escalation_defensively_unwraps_a_wrapper_if_the_api_ever_adds_one(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode(['escalation' => ['id' => 9, 'status' => 'sent']])),
        ]);

        $result = $client->getEscalation(9);

        $this->assertSame(9, $result['id']);
        $this->assertSame('sent', $result['status']);
    }

    public function test_a_429_is_retried_after_backoff_then_succeeds(): void
    {
        // Retry-After: 0 keeps the test instant while still exercising the retry loop.
        $client = $this->clientReturning([
            new Response(429, ['Retry-After' => '0'], ''),
            new Response(200, [], json_encode(['id' => 5, 'status' => 'sent'])),
        ]);

        $result = $client->getEscalation(5);

        $this->assertSame(5, $result['id']);
        $this->assertCount(2, $this->history, 'the 429 should have been retried exactly once');
    }
}
