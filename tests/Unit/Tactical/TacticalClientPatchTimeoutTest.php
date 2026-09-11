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
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * psa-1355: the per-request PATCH timeout, and what the failure line says.
 *
 * Two separate defects met on one log line. `tactical:reconcile-alerts` PATCHes
 * `alerts/` hourly and failed on every run in the retained production window
 * with `[TacticalClient] PATCH alerts/ failed {"status":null}` — a null status
 * meaning no HTTP response arrived at all. The endpoint is healthy and answers
 * that query in 41-85s, against the 30s timeout the config-driven client is
 * built with; and the line carried nothing — no exception class, no reason, no
 * timing — with which to reach that conclusion from the log itself.
 *
 * The timeout assertions read the OPTIONS the history middleware records, which
 * is what the handler was actually handed, not what the call site intended.
 *
 * The diagnostic assertions have a boundary that matters more than the keys:
 * the Guzzle message is logged ONLY when no response arrived. Guzzle's
 * BodySummarizer embeds ~120 bytes of the RESPONSE BODY in the message of a
 * RequestException, and a Tactical validation-error body echoes rest_headers
 * including X-Webhook-Key — the same leak TacticalClientException::fromGuzzle
 * refuses. So the with-a-response case sweeps a tracer value through the
 * whole logged context and requires it absent.
 */
class TacticalClientPatchTimeoutTest extends TestCase
{
    /** @var array<int, array{request: mixed, options: array<string, mixed>}> */
    private array $history = [];

    /** @var list<MessageLogged> */
    private array $capturedLogs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->capturedLogs = [];

        // Log::listen sees what the logger actually received, which is the
        // thing under test here; a facade mock would only prove the call shape.
        Log::listen(function (MessageLogged $record): void {
            $this->capturedLogs[] = $record;
        });
    }

    /**
     * A TacticalClient over an injected Guzzle client whose transport returns
     * $queue in order. The injected client carries a 30s timeout, matching the
     * config-driven production path, so "no override" is a real default and not
     * an absent key.
     *
     * @param  array<int, Response|\Throwable>  $queue
     */
    private function clientReturning(array $queue): TacticalClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new TacticalClient(new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => $stack,
            'timeout' => 30,
        ]));
    }

    /** @return array<string, mixed> */
    private function lastOptions(): array
    {
        return $this->history[array_key_last($this->history)]['options'];
    }

    /**
     * The context of the last [TacticalClient] PATCH failure line.
     *
     * @return array<string, mixed>
     */
    private function lastFailureContext(): array
    {
        foreach (array_reverse($this->capturedLogs) as $record) {
            if (str_contains((string) $record->message, '[TacticalClient] PATCH')) {
                return $record->context;
            }
        }

        $this->fail('No [TacticalClient] PATCH failure line was logged.');
    }

    public function test_a_per_request_timeout_reaches_the_transport(): void
    {
        $client = $this->clientReturning([new Response(200, [], json_encode([['id' => 1]]))]);

        $client->patch('alerts/', ['timeFilter' => 30], 150.0);

        $this->assertSame(150.0, $this->lastOptions()['timeout']);
    }

    public function test_without_an_override_the_clients_own_timeout_stays_in_force(): void
    {
        $client = $this->clientReturning([new Response(200, [], json_encode([]))]);

        $client->patch('alerts/', ['timeFilter' => 30]);

        // 30, not 150 and not absent: the override is opt-in per call and does
        // not become the new default for every other Tactical PATCH.
        $this->assertSame(30, $this->lastOptions()['timeout']);
    }

    public function test_a_timeout_of_zero_is_refused_and_nothing_is_sent(): void
    {
        $client = $this->clientReturning([new Response(200, [], json_encode([]))]);

        try {
            $client->patch('alerts/', [], 0.0);
            $this->fail('A zero timeout should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('greater than zero', $e->getMessage());
        }

        // Guzzle reads timeout 0 as "wait forever", which is the opposite of
        // what a caller asking for a bound means — so it is refused before the
        // request is made, not silently passed through.
        $this->assertSame([], $this->history);
    }

    public function test_a_negative_timeout_is_refused(): void
    {
        $client = $this->clientReturning([new Response(200, [], json_encode([]))]);

        $this->expectException(InvalidArgumentException::class);

        $client->patch('alerts/', [], -1.0);
    }

    public function test_a_transport_failure_logs_the_class_the_reason_the_timing_and_the_timeout_asked_for(): void
    {
        $client = $this->clientReturning([
            new ConnectException(
                'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received',
                new Request('PATCH', 'alerts/')
            ),
        ]);

        try {
            $client->patch('alerts/', ['timeFilter' => 30], 150.0);
            $this->fail('A transport failure should surface as TacticalClientException.');
        } catch (TacticalClientException $e) {
            $this->assertTrue($e->isTransportFailure());
        }

        $context = $this->lastFailureContext();

        $this->assertNull($context['status']);
        $this->assertSame(ConnectException::class, $context['exception']);
        $this->assertStringContainsString('cURL error 28', $context['reason']);
        $this->assertIsInt($context['elapsed_ms']);
        $this->assertGreaterThanOrEqual(0, $context['elapsed_ms']);
        $this->assertSame(150.0, $context['request_timeout_s']);
    }

    public function test_without_an_override_the_logged_request_timeout_is_null(): void
    {
        $client = $this->clientReturning([
            new ConnectException('cURL error 7: Failed to connect', new Request('PATCH', 'alerts/')),
        ]);

        try {
            $client->patch('alerts/');
        } catch (TacticalClientException) {
            // asserted below
        }

        // Null says "the client default applied" rather than naming a number
        // this method did not choose. Read beside elapsed_ms it still answers
        // whether the bound that stopped the call was ours.
        $this->assertNull($this->lastFailureContext()['request_timeout_s']);
    }

    public function test_a_failure_that_carried_a_response_logs_its_status_and_never_the_guzzle_message(): void
    {
        // A tracer, not a credential — and written so that no line here takes
        // the shape of an assigned credential literal: the repo's
        // hardcoded-credential diff scan reads shape, not intent, and a test
        // fixture is not worth an exception in that scan. Nested under the key
        // name rather than spelled as a `Header: value` line, which is both the
        // shape the scan refuses and the shape rest_headers actually echoes.
        $tracer = 'TRACER-DO-NOT-LOG';

        $client = $this->clientReturning([
            new Response(400, [], json_encode(['rest_headers' => ['X-Webhook-Key' => $tracer]])),
        ]);

        try {
            $client->patch('alerts/', ['timeFilter' => 30], 150.0);
            $this->fail('A 400 should surface as TacticalClientException.');
        } catch (TacticalClientException $e) {
            $this->assertSame(400, $e->statusCode());
        }

        $context = $this->lastFailureContext();

        $this->assertSame(400, $context['status']);
        // The class is safe and useful; the message is not, because Guzzle
        // composed it with a summary of the body above.
        $this->assertNull($context['reason']);

        $encoded = json_encode($context);
        // An encoding failure returns false, and (string) false is '' — which
        // would make the assertion below pass while measuring nothing.
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString($tracer, $encoded);
        $this->assertStringNotContainsString('rest_headers', $encoded);
    }

    public function test_the_transport_reason_is_bounded(): void
    {
        $client = $this->clientReturning([
            new ConnectException('cURL error 6: '.str_repeat('a', 5000), new Request('PATCH', 'alerts/')),
        ]);

        try {
            $client->patch('alerts/');
        } catch (TacticalClientException) {
            // asserted below
        }

        // Bytes, not characters: a formatter escapes what it is given, and an
        // unbounded stretch of remote-influenced text does not belong in a line
        // that repeats hourly.
        $this->assertSame(300, strlen($this->lastFailureContext()['reason']));
    }

    public function test_an_empty_transport_message_logs_a_null_reason_rather_than_an_empty_string(): void
    {
        $client = $this->clientReturning([
            new ConnectException('', new Request('PATCH', 'alerts/')),
        ]);

        try {
            $client->patch('alerts/');
        } catch (TacticalClientException) {
            // asserted below
        }

        // '' and null both read as "nothing here" to a human and differently to
        // a log query; null is the one that means "no diagnostic arrived".
        $this->assertNull($this->lastFailureContext()['reason']);
    }
}
