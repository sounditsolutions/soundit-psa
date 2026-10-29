<?php

namespace Tests\Unit\Cipp;

use App\Services\Cipp\CippClient;
use App\Services\Cipp\CippClientException;
use App\Services\Cipp\CippTenantScope;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Cache\Repository as CacheInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CippClientTokenErrorTest extends TestCase
{
    private array $records = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Log::listen(function (\Illuminate\Log\Events\MessageLogged $record): void {
            $this->records[] = [$record->level, $record->message, $record->context];
        });
    }

    public static function failures(): array
    {
        return [
            '400' => [400, [], '400 ClientException (AADSTS700016)'],
            '401' => [401, [], '401 ClientException (AADSTS700016)'],
            'connect' => [0, [], '0 ConnectException'],
            'errno' => [0, ['errno' => 28], '0 ConnectException (curl 28)'],
            'zero' => [0, ['errno' => 0], '0 ConnectException'],
            'string' => [0, ['errno' => '28'], '0 ConnectException'],
        ];
    }

    #[DataProvider('failures')]
    public function test_token_failure_is_sanitized_and_classified(int $status, array $context, string $detail): void
    {
        $forbidden = ['contoso-synth.onmicrosoft.com', '11111111-2222-4333-8444-555555555555', 'login.microsoftonline.com', 'invalid_grant', 'synthetic-client-id', 'synthetic-secret-canary', 'synthetic-trace', 'synthetic-correlation', 'description-canary'];
        $body = 'invalid_grant:AADSTS700016 '.implode(' ', $forbidden);
        $request = new Request('POST', 'https://login.microsoftonline.com/11111111-2222-4333-8444-555555555555/oauth2/v2.0/token');
        $raw = $status === 0
            ? new ConnectException($body, $request, null, $context + ['error' => $body])
            : new ClientException($body, $request, new Response($status, [], $body));
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([$raw]))]);
        $cache = \Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->with('cipp_oauth_token')->once()->andReturn(null);
        $client = new class(['tenant_id' => '11111111-2222-4333-8444-555555555555', 'client_id' => 'synthetic-client-id', 'client_secret' => 'synthetic-secret-canary'], $cache, $http) extends CippClient
        {
            public Client $tokenHttp;

            protected function tokenClient(): Client
            {
                return $this->tokenHttp;
            }
        };
        $client->tokenHttp = $http;
        $this->assertTrue(method_exists(CippClient::class, 'tokenClient'), 'Token transport seam must exist before invoking: never allow live Guzzle');
        try {
            $client->get('api/ListUsers');
            $this->fail('Expected token failure');
        } catch (CippClientException $exception) {
            $this->assertSame('CIPP OAuth token request failed: '.$detail, $exception->getMessage());
            $this->assertSame($status, $exception->getCode());
            $this->assertSame($raw, $exception->getPrevious());
            foreach ($forbidden as $text) {
                $this->assertStringNotContainsString($text, $exception->getMessage());
            }
            $check = new \ReflectionMethod(CippTenantScope::class, 'isSignInFailure');
            $this->assertTrue($check->invoke(null, $exception));
            if ($status !== 0) {
                $this->assertSame($body, $exception->getPrevious()->getResponse()->getBody()->getContents());
            }
        }
        $this->assertSame([['error', '[CippClient] Token request failed: '.$detail, []]], $this->records);
    }
}
