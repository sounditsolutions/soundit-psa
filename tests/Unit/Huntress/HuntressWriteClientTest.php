<?php

namespace Tests\Unit\Huntress;

use App\Services\Huntress\HuntressClientException;
use App\Services\Huntress\HuntressEscalationAlreadyResolvedException;
use App\Services\Huntress\HuntressEscalationNotApiResolvableException;
use App\Services\Huntress\HuntressWriteClient;
use App\Services\Huntress\HuntressWriteScopeException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * HuntressWriteClient — the single-verb write lane. Pure unit tests over an
 * injected Guzzle client backed by a MockHandler; no network, no container.
 *
 * The load-bearing facts under test:
 *   1. The resolution body is the LITERAL `{}` — never a serialized caller
 *      structure (json_encode([]) would emit `[]`), and no code path lets
 *      caller input reach it.
 *   2. 409 (already resolved) and 422 (not API-resolvable) map to their typed
 *      exceptions so the approval path can distinguish idempotent-satisfied
 *      from console-only.
 *   3. Missing user-key credentials fail closed BEFORE any HTTP.
 */
class HuntressWriteClientTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    /**
     * @param  array<int, Response|\Throwable>  $queue
     */
    private function clientReturning(array $queue, array $config = ['user_api_key' => 'uk', 'user_api_secret' => 'us']): HuntressWriteClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        $http = new GuzzleClient([
            'base_uri' => 'https://api.huntress.io/v1/',
            'handler' => $stack,
        ]);

        return new HuntressWriteClient($config, $http);
    }

    private function lastRequest(): RequestInterface
    {
        return $this->history[array_key_last($this->history)]['request'];
    }

    public function test_resolve_sends_post_with_the_literal_empty_object_body(): void
    {
        $client = $this->clientReturning([
            new Response(201, [], json_encode(['resolution_method' => 'direct', 'id' => 1])),
        ]);

        $resolution = $client->resolveEscalation(55);

        $this->assertSame('direct', $resolution['resolution_method']);
        $request = $this->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/v1/escalations/55/resolution', $request->getUri()->getPath());
        $this->assertSame('{}', (string) $request->getBody(), 'the body must be the literal empty JSON object');
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function test_resolve_unwraps_a_wrapped_resolution_object(): void
    {
        $client = $this->clientReturning([
            new Response(201, [], json_encode(['escalation_resolution' => ['resolution_method' => 'dismiss']])),
        ]);

        $this->assertSame('dismiss', $client->resolveEscalation(7)['resolution_method']);
    }

    public function test_409_maps_to_the_already_resolved_exception(): void
    {
        $client = $this->clientReturning([
            new Response(409, [], json_encode(['error' => 'Escalation has already been resolved'])),
        ]);

        $this->expectException(HuntressEscalationAlreadyResolvedException::class);
        $client->resolveEscalation(55);
    }

    public function test_422_maps_to_the_not_api_resolvable_exception(): void
    {
        $client = $this->clientReturning([
            new Response(422, [], json_encode(['error' => 'Escalation cannot be resolved through the API'])),
        ]);

        $this->expectException(HuntressEscalationNotApiResolvableException::class);
        $client->resolveEscalation(55);
    }

    public function test_other_upstream_failures_surface_as_client_exceptions(): void
    {
        $client = $this->clientReturning([new Response(500, [], 'boom')]);

        $this->expectException(HuntressClientException::class);
        $client->resolveEscalation(55);
    }

    public function test_missing_write_credentials_fail_closed_before_any_http(): void
    {
        $client = $this->clientReturning(
            [new Response(201, [], '{}')],
            // The READ pair being present must not satisfy the write lane.
            ['api_key' => 'read-k', 'api_secret' => 'read-s'],
        );

        try {
            $client->resolveEscalation(55);
            $this->fail('expected HuntressWriteScopeException');
        } catch (HuntressWriteScopeException) {
            // expected
        }

        $this->assertSame([], $this->history, 'no HTTP request may be sent without the user-based key');
    }

    public function test_non_positive_escalation_id_is_refused_before_any_http(): void
    {
        $client = $this->clientReturning([new Response(201, [], '{}')]);

        try {
            $client->resolveEscalation(0);
            $this->fail('expected HuntressWriteScopeException');
        } catch (HuntressWriteScopeException) {
            // expected
        }

        $this->assertSame([], $this->history);
    }

    public function test_429_retries_and_a_retry_that_lands_on_409_maps_to_already_resolved(): void
    {
        // First attempt rate-limited; the retry discovers the resolve already
        // landed (the reason retrying this POST is safe).
        $client = $this->clientReturning([
            new Response(429, ['Retry-After' => '0'], ''),
            new Response(409, [], json_encode(['error' => 'Escalation has already been resolved'])),
        ]);

        $this->expectException(HuntressEscalationAlreadyResolvedException::class);
        $client->resolveEscalation(55);
    }
}
