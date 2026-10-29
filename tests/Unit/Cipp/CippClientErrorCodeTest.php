<?php

namespace Tests\Unit\Cipp;

use App\Services\Cipp\CippClient;
use App\Services\Cipp\CippClientException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Cache\Repository as CacheInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CippClientErrorCodeTest extends TestCase
{
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Log::spy();
    }

    public static function bodies(): array
    {
        // Issue #7650's text example, with exclusively synthetic identifiers.
        $detail = ' contoso-synth.onmicrosoft.com 11111111-2222-4333-8444-555555555555 Trace ID: synthetic-trace Correlation ID: synthetic-correlation description-canary';

        return [
            'text' => [500, 'Could not get token: invalid_grant:AADSTS700003'.$detail, 'AADSTS700003'],
            'json' => [500, json_encode(['error' => 'invalid_grant', 'error_description' => 'AADSTS700003:'.$detail]), 'AADSTS700003'],
            '400' => [400, 'invalid_grant:AADSTS700003'.$detail, 'AADSTS700003'],
            '403' => [403, 'invalid_grant:AADSTS700003'.$detail, 'AADSTS700003'],
            'empty' => [500, '', null],
            'sixteen digits' => [500, 'AADSTS1234567890123456', 'AADSTS1234567890123456'],
            'seventeen digits' => [500, 'AADSTS12345678901234567', null],
            'near bound' => [500, str_repeat('x', 64988).' AADSTS700003 ', 'AADSTS700003'],
            'no code' => [500, 'invalid_grant'.$detail, null],
            'beyond truncation' => [500, str_repeat('padding ', 100).'invalid_grant:AADSTS700003'.$detail, 'AADSTS700003'],
            'hostile error' => [500, json_encode(['error' => '<b>hostile.example.test error</b>', 'error_description' => 'AADSTS700003:'.$detail]), 'AADSTS700003'],
            'long error' => [500, json_encode(['error' => str_repeat('hostile ', 1000)]), null],
            'first only' => [500, 'AADSTS700003 AADSTS900001'.$detail, 'AADSTS700003'],
            'outside bound' => [500, str_repeat('x', 65536).' AADSTS700003', null],
            'straddles bound' => [500, str_repeat('x', 65525).' AADSTS700003'.$detail, null],
            'ends at bound' => [500, str_repeat('x', 65523).' AADSTS700003'.$detail, 'AADSTS700003'],
            'invalid code boundaries' => [500, 'xAADSTS700003 AADSTS123 AADSTS700003x', null],
        ];
    }

    #[DataProvider('bodies')]
    public function test_response_message_and_log_are_codes_only(int $status, string $body, ?string $aadsts): void
    {
        $response = new Response($status, [], $body);
        $client = $this->client([$response]);
        $exception = $this->failure($client);
        $class = $status >= 500 ? 'ServerException' : 'ClientException';
        $expected = "CIPP API error: {$status} {$class}".($aadsts === null ? '' : " ({$aadsts})");

        $this->assertSame($status, $exception->getCode());
        $this->assertSame($response, $exception->getPrevious()->getResponse());
        $this->assertCount(1, $this->history);
        foreach (['invalid_grant', 'contoso-synth.onmicrosoft.com', '11111111-2222-4333-8444-555555555555', 'synthetic-trace', 'synthetic-correlation', 'description-canary', 'truncated', 'hostile'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $exception->getMessage());
        }
        $this->assertSame($expected, $exception->getMessage());
        $this->assertFailureLog($expected);
        $this->assertSame($body, $exception->getPrevious()->getResponse()->getBody()->getContents());
    }

    public function test_transport_failure_keeps_chain_but_not_url_or_message(): void
    {
        $raw = new ConnectException('cURL error 28: timed out https://cipp.example.test/?TenantFilter=contoso-synth.onmicrosoft.com', new Request('GET', 'https://cipp.example.test/'));
        $exception = $this->failure($this->client([$raw]));
        $this->assertSame(0, $exception->getCode());
        $this->assertSame($raw, $exception->getPrevious());
        $this->assertSame('CIPP API error: 0 ConnectException', $exception->getMessage());
        $this->assertFailureLog('CIPP API error: 0 ConnectException');
    }

    public function test_401_refreshes_cached_token_and_retries(): void
    {
        $cache = \Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->with('cipp_oauth_token')->twice()->andReturn('synthetic-old', 'synthetic-new');
        $cache->shouldReceive('forget')->with('cipp_oauth_token')->once();
        $client = $this->client([new Response(401), new Response(200, [], '{"Results":[]}')], $cache);
        $this->assertSame([], $client->get('api/ListUsers'));
        $this->assertCount(2, $this->history);
        $this->assertSame('Bearer synthetic-old', $this->history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('Bearer synthetic-new', $this->history[1]['request']->getHeaderLine('Authorization'));
        Log::shouldNotHaveReceived('error');
    }

    public function test_429_retries_to_success(): void
    {
        $client = $this->client([new Response(429, ['Retry-After' => '1']), new Response(429, ['Retry-After' => '1']), new Response(200, [], '[]')]);
        $this->assertSame([], $client->get('api/ListUsers'));
        $this->assertCount(3, $this->history);
        $this->assertSame([1, 1], $client->pauses);
        Log::shouldHaveReceived('info')->twice()->with('[CippClient] Rate limited on api/ListUsers, retrying in 1s');
        Log::shouldNotHaveReceived('error');
    }

    private function assertFailureLog(string $expected, bool $retry = false): void
    {
        Log::shouldHaveReceived('error')->once()->with('[CippClient] GET api/ListUsers failed: '.$expected);
        foreach (['emergency', 'alert', 'critical', 'warning', 'notice', 'debug', 'log', 'write'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
        if (! $retry) {
            Log::shouldNotHaveReceived('info');
        }
    }

    public static function errnos(): array
    {
        return ['missing' => [[], ''], 'zero' => [['errno' => 0], ''], 'string' => [['errno' => '28'], ''], 'integer' => [['errno' => 28], ' (curl 28)']];
    }

    #[DataProvider('errnos')]
    public function test_errno_is_only_a_nonzero_integer(array $context, string $suffix): void
    {
        $raw = new ConnectException('synthetic private transport detail', new Request('GET', 'https://cipp.example.test/'), null, $context + ['error' => 'https://cipp.example.test/private-canary']);
        $exception = $this->failure($this->client([$raw]));
        $expected = 'CIPP API error: 0 ConnectException'.$suffix;
        $this->assertSame($expected, $exception->getMessage());
        $this->assertSame($raw, $exception->getPrevious());
        $this->assertFailureLog($expected);
    }

    public static function streamCases(): array
    {
        return [
            'read throws' => ['throw', null],
            'partial prefix then throws' => ['partial', null],
            'partial valid code then throws' => ['partial-code', null],
            'short early' => ['short', 'AADSTS700003'],
            'short near bound' => ['short-late', 'AADSTS700003'],
            'consumed' => ['consumed', 'AADSTS700003'],
            'nonseekable' => ['nonseekable', 'AADSTS700003'],
            'rewind throws' => ['rewind', null],
        ];
    }

    #[DataProvider('streamCases')]
    public function test_stream_safety(string $mode, ?string $code): void
    {
        $text = str_repeat('x', $mode === 'short-late' ? 64988 : 20).' AADSTS700003 description-canary';
        $body = new class(\GuzzleHttp\Psr7\Utils::streamFor($text), $mode) implements \Psr\Http\Message\StreamInterface
        {
            use \GuzzleHttp\Psr7\StreamDecoratorTrait;

            private int $reads = 0;

            public function __construct(private \Psr\Http\Message\StreamInterface $stream, private string $mode) {}

            public function isSeekable(): bool
            {
                return $this->mode !== 'nonseekable';
            }

            public function rewind(): void
            {
                if (in_array($this->mode, ['nonseekable', 'rewind'], true)) {
                    throw new \RuntimeException('synthetic rewind failure');
                }
                $this->stream->rewind();
            }

            public function read(int $length): string
            {
                $this->reads++;
                if ($this->mode === 'throw' || (str_starts_with($this->mode, 'partial') && $this->reads > 1)) {
                    throw new \RuntimeException('synthetic read failure');
                }
                if ($this->mode === 'partial') {
                    return 'AADS';
                }
                if ($this->mode === 'partial-code') {
                    return 'AADSTS700003 ';
                }

                return $this->stream->read(min($length, 7));
            }
        };
        if ($mode === 'consumed') {
            $body->getContents();
        }
        // Queue an exception directly so Guzzle's message summarizer does not consume the test stream.
        $raw = new \GuzzleHttp\Exception\ServerException('synthetic upstream failure', new Request('GET', 'https://cipp.example.test/'), new Response(500, [], $body));
        $exception = $this->failure($this->client([$raw]));
        $expected = 'CIPP API error: 500 ServerException'.($code === null ? '' : " ({$code})");
        $this->assertSame($expected, $exception->getMessage());
        $this->assertSame($raw, $exception->getPrevious());
        $this->assertSame(500, $exception->getCode());
        $this->assertFailureLog($expected);
        if (in_array($mode, ['short', 'short-late', 'consumed'], true)) {
            $this->assertSame($text, $body->getContents());
        }
    }

    public static function retryStatuses(): array
    {
        return [[401], [429]];
    }

    #[DataProvider('retryStatuses')]
    public function test_retry_exhaustion(int $status): void
    {
        $cache = \Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->with('cipp_oauth_token')->times(3)->andReturn('synthetic-cached');
        if ($status === 401) {
            $cache->shouldReceive('forget')->with('cipp_oauth_token')->twice();
        }
        $client = $this->client([
            new Response($status, ['Retry-After' => '1']),
            new Response($status, ['Retry-After' => '1']),
            new Response($status, [], 'invalid_grant:AADSTS700003 description-canary'),
        ], $cache);
        $exception = $this->failure($client);
        $expected = "CIPP API error: {$status} ClientException (AADSTS700003)";
        $this->assertSame($expected, $exception->getMessage());
        $this->assertSame($status, $exception->getCode());
        $this->assertSame($status, $exception->getPrevious()->getCode());
        $this->assertCount(3, $this->history);
        $this->assertSame($status === 429 ? [1, 1] : [], $client->pauses);
        Log::shouldHaveReceived('info')->twice()->with($status === 401
            ? '[CippClient] 401 received, refreshing token and retrying'
            : '[CippClient] Rate limited on api/ListUsers, retrying in 1s');
        $this->assertFailureLog($expected, true);
    }

    private function failure(CippClient $client): CippClientException
    {
        try {
            $client->get('api/ListUsers', ['TenantFilter' => 'contoso-synth.onmicrosoft.com']);
            $this->fail('Expected a CIPP exception');
        } catch (CippClientException $e) {
            return $e;
        } catch (\RuntimeException $e) {
            $this->fail('Expected CippClientException, got '.$e::class);
        }
    }

    private function client(array $queue, ?CacheInterface $cache = null): CippClient
    {
        Cache::put('cipp_oauth_token', 'synthetic-cached', 3600);
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new class([], $cache ?? app(CacheInterface::class), new Client(['handler' => $stack, 'base_uri' => 'https://cipp.example.test/'])) extends CippClient
        {
            public array $pauses = [];

            protected function pause(int $seconds): void
            {
                $this->pauses[] = $seconds;
            }
        };
    }
}
