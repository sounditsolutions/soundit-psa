<?php

namespace Tests\Unit\Cipp;

use App\Services\Cipp\CippClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Cache\Repository as CacheInterface;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CippClientRawTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    public function test_get_raw_returns_binary_body_and_content_type(): void
    {
        $bytes = random_bytes(64);
        $client = $this->clientReturning(new Response(200, ['Content-Type' => 'image/jpeg'], $bytes));

        $result = $client->getRaw('api/ListUserPhoto', ['TenantFilter' => 'contoso', 'userId' => 'obj-1']);

        $this->assertSame(200, $result['status']);
        $this->assertSame('image/jpeg', $result['contentType']);
        $this->assertSame($bytes, $result['body']);

        // The raw fetch must send Accept: */* (not application/json) so CIPP hands
        // back image bytes rather than a JSON-encoded payload.
        $request = $this->history[0]['request'];
        $this->assertSame('*/*', $request->getHeaderLine('Accept'));
        $this->assertStringContainsString('Bearer test-token', $request->getHeaderLine('Authorization'));
        $this->assertStringContainsString('TenantFilter=contoso', $request->getUri()->getQuery());
        $this->assertStringContainsString('userId=obj-1', $request->getUri()->getQuery());
    }

    public function test_get_user_photo_targets_list_user_photo_with_tenant_and_user(): void
    {
        $client = $this->clientReturning(
            new Response(200, ['Content-Type' => 'application/json'], '{"error":{"code":"ImageNotFound"}}')
        );

        $result = $client->getUserPhoto('contoso.onmicrosoft.com', 'user-object-id');

        $this->assertStringContainsString('json', $result['contentType']);
        $this->assertStringContainsString('ImageNotFound', $result['body']);

        $uri = $this->history[0]['request']->getUri();
        $this->assertStringContainsString('api/ListUserPhoto', (string) $uri);
        $this->assertStringContainsString('TenantFilter=contoso.onmicrosoft.com', urldecode($uri->getQuery()));
        $this->assertStringContainsString('userId=user-object-id', $uri->getQuery());
    }

    private function clientReturning(Response $response): CippClient
    {
        // Pre-seed the OAuth token so getToken() short-circuits without a network call.
        Cache::put('cipp_oauth_token', 'test-token', 3600);

        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([$response]));
        $stack->push(Middleware::history($this->history));

        $http = new Client(['handler' => $stack, 'base_uri' => 'https://cipp.example.test/']);

        return new CippClient(
            ['api_url' => 'https://cipp.example.test'],
            app(CacheInterface::class),
            $http,
        );
    }
}
