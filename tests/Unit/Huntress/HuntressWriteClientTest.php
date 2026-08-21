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

    /**
     * A SCALAR top-level `resolution` is a sibling field (a note, a state
     * string), not a wrapper. Treating it as one and discarding the body
     * erases `resolution_method`, which the executor reads as '(missing)' and
     * escalates as a HARD FAULT — turning every clean resolve into a false
     * security incident.
     */
    public function test_a_scalar_resolution_sibling_key_does_not_discard_the_body(): void
    {
        $client = $this->clientReturning([
            new Response(201, [], json_encode([
                'id' => 9,
                'resolution_method' => 'direct',
                'resolution' => 'Resolved by analyst',
            ])),
        ]);

        $resolution = $client->resolveEscalation(9);

        $this->assertSame('direct', $resolution['resolution_method'], 'a scalar sibling key must never discard the decoded body');
        $this->assertSame(9, $resolution['id']);
    }

    /**
     * The same trap one shape out: a NON-scalar sibling `resolution` — a
     * nested detail object, a list of notes, an empty array — is still not a
     * wrapper. Selecting the arm on structure alone discards a body whose
     * top-level `resolution_method` is valid and reproduces the identical
     * false HARD FAULT. The wrapper arm is selected by content: only an array
     * that itself carries `resolution_method` is the resolution object.
     */
    public function test_a_non_wrapper_array_resolution_sibling_does_not_discard_the_body(): void
    {
        foreach ([
            ['notes' => 'Resolved by analyst', 'analyst' => 'jdoe'],
            ['Resolved by analyst'],
            [],
        ] as $sibling) {
            $client = $this->clientReturning([
                new Response(201, [], json_encode([
                    'id' => 9,
                    'resolution_method' => 'direct',
                    'resolution' => $sibling,
                ])),
            ]);

            $resolution = $client->resolveEscalation(9);

            $this->assertSame('direct', $resolution['resolution_method'], 'an array sibling that is not the resolution object must never discard the body');
            $this->assertSame(9, $resolution['id']);
        }
    }

    /**
     * BOTH shapes present at once: a top-level `resolution_method` AND a
     * content-verified wrapper reporting a DIFFERENT one. The wrapper is the
     * server's report of the code path it actually took; the top-level field
     * may be a summary/legacy echo. If the body won here, a resolution that
     * CREATED attribute rules would reach the executor as a clean 'direct',
     * be audited as `executed`, and page nobody — the one direction this
     * defensive unwrap may never fail in. Every other misread in it fails
     * toward the loud false fault; this one must too.
     */
    public function test_a_content_verified_wrapper_outranks_a_top_level_resolution_method(): void
    {
        foreach (['escalation_resolution', 'resolution'] as $wrapperKey) {
            $client = $this->clientReturning([
                new Response(201, [], json_encode([
                    'id' => 9,
                    'resolution_method' => 'direct',
                    $wrapperKey => ['resolution_method' => 'rule', 'rules_created' => [['id' => 3]]],
                ])),
            ]);

            $resolution = $client->resolveEscalation(9);

            $this->assertSame('rule', $resolution['resolution_method'], "the {$wrapperKey} wrapper's own method must reach the executor's post-condition, not a top-level echo");
        }

        // The MIRRORED shapes: selection is severity-aware, not positional, so
        // an unsafe `rule` must survive from EITHER position. A fixed
        // wrapper-first rule launders shape one; a fixed body-first rule
        // launders the original pair above — each ordering's fix opens the
        // mirror hole, which is why severity decides.
        foreach ([
            // top-level `rule` + content-verified wrapper reporting `direct`
            ['id' => 9, 'resolution_method' => 'rule', 'resolution' => ['resolution_method' => 'direct']],
            // two wrappers: escalation_resolution `direct` + resolution `rule`
            ['id' => 9, 'escalation_resolution' => ['resolution_method' => 'direct'], 'resolution' => ['resolution_method' => 'rule', 'rules_created' => [['id' => 3]]]],
        ] as $mirroredBody) {
            $client = $this->clientReturning([
                new Response(201, [], json_encode($mirroredBody)),
            ]);

            $resolution = $client->resolveEscalation(9);

            $this->assertSame('rule', $resolution['resolution_method'], 'an unsafe method must reach the post-condition from either position — severity outranks order');
        }
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

    /**
     * Retry-After is UPSTREAM-CONTROLLED and the sleep runs inside the
     * synchronous cockpit approve request: whatever the header says, no single
     * back-off may exceed RETRY_AFTER_CEILING_SECONDS. Non-numeric and
     * negative headers fall back to the exponential default (2s, 4s), which
     * sits under the ceiling by construction.
     */
    public function test_retry_delay_clamps_the_upstream_retry_after_header(): void
    {
        $ceiling = HuntressWriteClient::RETRY_AFTER_CEILING_SECONDS;

        $this->assertSame($ceiling, HuntressWriteClient::retryDelaySeconds('3600', 1), 'an hour-long upstream header must clamp to the ceiling');
        $this->assertSame($ceiling, HuntressWriteClient::retryDelaySeconds((string) ($ceiling + 1), 2));
        $this->assertSame(3, HuntressWriteClient::retryDelaySeconds('3', 1), 'a sane header value is honoured');
        $this->assertSame(0, HuntressWriteClient::retryDelaySeconds('0', 1), 'zero means retry immediately');
        $this->assertSame(2, HuntressWriteClient::retryDelaySeconds('', 1), 'no header → exponential default');
        $this->assertSame(4, HuntressWriteClient::retryDelaySeconds('', 2));
        $this->assertSame(2, HuntressWriteClient::retryDelaySeconds('-5', 1), 'negative header → exponential default');
        $this->assertSame(4, HuntressWriteClient::retryDelaySeconds('soon', 2), 'non-numeric header → exponential default');
    }
}
