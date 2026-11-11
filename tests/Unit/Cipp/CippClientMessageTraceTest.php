<?php

namespace Tests\Unit\Cipp;

use App\Services\Cipp\CippClient;
use App\Services\Cipp\CippClientException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Cache\Repository as CacheInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CippClient::listMessageTrace() — the one POST this client makes (card AsGVKuRa).
 *
 * CIPP-API Invoke-ListMessageTrace.ps1 (master b4ab8d9a) reads tenantFilter (:68),
 * days (:90), sender and recipient (:112-113) from $Request.Body only. These tests
 * pin the WIRE: method, endpoint, content type and the decoded JSON body, so the
 * call cannot quietly drift back to a GET with query parameters (which CIPP
 * answers with an empty Results for every tenant). Response fixtures follow the
 * same file's success (:189-193) and failure (:198-202) bodies.
 */
class CippClientMessageTraceTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_message_trace_posts_a_json_body_with_cipps_key_names(): void
    {
        $client = $this->client([$this->ok('{"Results":[],"Metadata":{"Returned":0,"Source":"Graph"}}')]);

        $client->listMessageTrace('contoso.onmicrosoft.com', 4, ' sender@remote.example ', 'user@contoso.com');

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/api/ListMessageTrace', $request->getUri()->getPath());
        $this->assertSame('', $request->getUri()->getQuery(), 'inputs leaked to the query string, which CIPP never reads');
        $this->assertStringStartsWith('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame('Bearer synthetic-cached', $request->getHeaderLine('Authorization'));
        $this->assertSame(
            ['tenantFilter' => 'contoso.onmicrosoft.com', 'days' => 4, 'sender' => 'sender@remote.example', 'recipient' => 'user@contoso.com'],
            json_decode((string) $request->getBody(), true),
        );
    }

    public function test_message_trace_omits_empty_sender_and_recipient(): void
    {
        $client = $this->client([$this->ok('{"Results":[]}')]);

        $client->listMessageTrace('contoso.onmicrosoft.com', 2, '  ', null);

        $this->assertSame(
            ['tenantFilter' => 'contoso.onmicrosoft.com', 'days' => 2],
            json_decode((string) $this->history[0]['request']->getBody(), true),
        );
    }

    public function test_message_trace_returns_rows_and_keeps_metadata(): void
    {
        $client = $this->client([$this->ok(
            '{"Results":[{"MessageTraceId":"t-1","Status":"Delivered"}],"Metadata":{"Returned":1,"Source":"Get-MessageTraceV2","Note":"Served via Get-MessageTraceV2"}}'
        )]);

        $trace = $client->listMessageTrace('contoso.onmicrosoft.com', 2);

        $this->assertSame([['MessageTraceId' => 't-1', 'Status' => 'Delivered']], $trace['rows']);
        $this->assertSame(['Returned' => 1, 'Source' => 'Get-MessageTraceV2', 'Note' => 'Served via Get-MessageTraceV2'], $trace['metadata']);
    }

    public function test_message_trace_returns_cipps_metadata_error_from_a_500(): void
    {
        $client = $this->client([new Response(500, ['Content-Type' => 'application/json'],
            '{"Results":[],"Metadata":{"Error":"Failed to retrieve message trace: synthetic reason"}}')]);

        $trace = $client->listMessageTrace('contoso.onmicrosoft.com', 2);

        $this->assertSame([], $trace['rows']);
        $this->assertSame(['Error' => 'Failed to retrieve message trace: synthetic reason'], $trace['metadata']);
        $this->assertCount(1, $this->history);
    }

    public function test_message_trace_rethrows_a_500_without_a_metadata_error(): void
    {
        $client = $this->client([new Response(500, [], 'gateway exploded')]);

        $this->expectException(CippClientException::class);
        $this->expectExceptionMessage('CIPP API error: 500 ServerException');

        $client->listMessageTrace('contoso.onmicrosoft.com', 2);
    }

    public function test_message_trace_refuses_an_unreadable_body_rather_than_answering_zero(): void
    {
        $client = $this->client([$this->ok('')]);

        $this->expectException(CippClientException::class);
        $this->expectExceptionMessage('unreadable body');

        $client->listMessageTrace('contoso.onmicrosoft.com', 2);
    }

    public function test_message_trace_refuses_an_object_that_is_not_a_results_envelope(): void
    {
        $client = $this->client([$this->ok('{"error":"nope"}')]);

        $this->expectException(CippClientException::class);
        $this->expectExceptionMessage('not a Results envelope');

        $client->listMessageTrace('contoso.onmicrosoft.com', 2);
    }

    public function test_message_trace_refuses_an_empty_object_rather_than_answering_zero(): void
    {
        // Assoc json_decode turns `{}` into `[]`, which would otherwise pass as an empty list.
        $client = $this->client([$this->ok('{}')]);

        $this->expectException(CippClientException::class);
        $this->expectExceptionMessage('not a Results envelope');

        $client->listMessageTrace('contoso.onmicrosoft.com', 2);
    }

    public function test_message_trace_refuses_an_empty_bare_list_rather_than_answering_zero(): void
    {
        $client = $this->client([$this->ok('[]')]);

        $this->expectException(CippClientException::class);
        $this->expectExceptionMessage('not a Results envelope');

        $client->listMessageTrace('contoso.onmicrosoft.com', 2);
    }

    public function test_message_trace_refuses_a_bare_list_of_rows(): void
    {
        // The producer always wraps rows as {Results, Metadata}; a bare list is not its shape.
        $client = $this->client([$this->ok('[{"MessageTraceId":"t-1"}]')]);

        $this->expectException(CippClientException::class);
        $this->expectExceptionMessage('not a Results envelope');

        $client->listMessageTrace('contoso.onmicrosoft.com', 2);
    }

    public function test_message_trace_refuses_an_empty_object_as_results(): void
    {
        $client = $this->client([$this->ok('{"Results":{},"Metadata":{"Returned":0,"Source":"Graph"}}')]);

        $this->expectException(CippClientException::class);
        $this->expectExceptionMessage('not a Results envelope');

        $client->listMessageTrace('contoso.onmicrosoft.com', 2);
    }

    public function test_message_trace_refreshes_the_token_on_401_and_resends_the_same_post(): void
    {
        $cache = \Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->with('cipp_oauth_token')->twice()->andReturn('synthetic-old', 'synthetic-new');
        $cache->shouldReceive('forget')->with('cipp_oauth_token')->once();
        $client = $this->client([new Response(401), $this->ok('{"Results":[{"MessageTraceId":"t-1"}]}')], $cache);

        $trace = $client->listMessageTrace('contoso.onmicrosoft.com', 3);

        $this->assertSame([['MessageTraceId' => 't-1']], $trace['rows']);
        $this->assertCount(2, $this->history);
        $this->assertSame('Bearer synthetic-old', $this->history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('Bearer synthetic-new', $this->history[1]['request']->getHeaderLine('Authorization'));
        foreach ($this->history as $entry) {
            $this->assertSame('POST', $entry['request']->getMethod());
            $this->assertSame(['tenantFilter' => 'contoso.onmicrosoft.com', 'days' => 3], json_decode((string) $entry['request']->getBody(), true));
        }
    }

    public function test_message_trace_backs_off_on_429_like_get(): void
    {
        $client = $this->client([new Response(429, ['Retry-After' => '1']), $this->ok('{"Results":[]}')]);

        $this->assertSame([], $client->listMessageTrace('contoso.onmicrosoft.com', 2)['rows']);
        $this->assertCount(2, $this->history);
        $this->assertSame([1], $client->pauses);
    }

    public function test_message_trace_throws_on_a_queue_backed_answer_like_get(): void
    {
        // Nested queue shape, as CippQueueGuard documents (CIPP-API Invoke-ListGraphRequest.ps1:176-191).
        $client = $this->client([$this->ok('{"Results":[],"Metadata":{"QueueMessage":"Still loading data for this tenant","QueueId":"q-1"}}')]);

        $this->expectException(CippClientException::class);
        $this->expectExceptionMessage('queue-backed result');

        $client->listMessageTrace('contoso.onmicrosoft.com', 2);
    }

    private function ok(string $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], $body);
    }

    /**
     * @param  array<int, Response>  $queue
     */
    private function client(array $queue, ?CacheInterface $cache = null): CippClient
    {
        Cache::put('cipp_oauth_token', 'synthetic-cached', 3600);
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new class([], $cache ?? app(CacheInterface::class), new Client(['handler' => $stack, 'base_uri' => 'https://cipp.example.test/'])) extends CippClient
        {
            /** @var array<int, int> */
            public array $pauses = [];

            protected function pause(int $seconds): void
            {
                $this->pauses[] = $seconds;
            }
        };
    }
}
