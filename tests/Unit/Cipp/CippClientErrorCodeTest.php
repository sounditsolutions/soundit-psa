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
        Http::fake();
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
            'no code' => [500, 'invalid_grant'.$detail, null],
            'beyond truncation' => [500, str_repeat('padding ', 100).'invalid_grant:AADSTS700003'.$detail, 'AADSTS700003'],
            'hostile error' => [500, json_encode(['error' => '<b>hostile.example.test error</b>', 'error_description' => 'AADSTS700003:'.$detail]), 'AADSTS700003'],
            'long error' => [500, json_encode(['error' => str_repeat('hostile ', 1000)]), null],
            'first only' => [500, 'AADSTS700003 AADSTS900001'.$detail, 'AADSTS700003'],
            'outside bound' => [500, str_repeat('x', 65536).' AADSTS700003', null],
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
        Log::shouldHaveReceived('error')->once()->with('[CippClient] GET api/ListUsers failed: '.$expected);
    }

    public function test_transport_failure_keeps_chain_but_not_url_or_message(): void
    {
        $raw = new ConnectException('cURL error 28: timed out https://cipp.example.test/?TenantFilter=contoso-synth.onmicrosoft.com', new Request('GET', 'https://cipp.example.test/'));
        $exception = $this->failure($this->client([$raw]));
        $this->assertSame(0, $exception->getCode());
        $this->assertSame($raw, $exception->getPrevious());
        $this->assertSame('CIPP API error: 0 ConnectException', $exception->getMessage());
        Log::shouldHaveReceived('error')->once()->with('[CippClient] GET api/ListUsers failed: CIPP API error: 0 ConnectException');
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
        Log::shouldHaveReceived('info')->twice()->with('[CippClient] Rate limited on api/ListUsers, retrying in 1s');
        Log::shouldNotHaveReceived('error');
    }

    private function failure(CippClient $client): CippClientException
    {
        try {
            $client->get('api/ListUsers', ['TenantFilter' => 'contoso-synth.onmicrosoft.com']);
            $this->fail('Expected a CIPP exception');
        } catch (CippClientException $e) {
            return $e;
        }
    }

    private function client(array $queue, ?CacheInterface $cache = null): CippClient
    {
        Cache::put('cipp_oauth_token', 'synthetic-cached', 3600);
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new CippClient([], $cache ?? app(CacheInterface::class), new Client(['handler' => $stack, 'base_uri' => 'https://cipp.example.test/']));
    }
}
