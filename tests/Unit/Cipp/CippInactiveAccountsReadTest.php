<?php

namespace Tests\Unit\Cipp;

use App\Services\Cipp\CippClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Cache\Repository as CacheInterface;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * listInactiveAccounts() is the one read whose caller acts on emptiness: it clears
 * cipp_inactive client-wide before re-flagging. So "CIPP answered with no rows" and
 * "CIPP did not answer" must not collapse into the same [] the way get() collapses
 * them — a real empty list has to clear stale flags, an unreadable body must not.
 */
class CippInactiveAccountsReadTest extends TestCase
{
    public function test_results_envelope_holding_an_empty_list_is_a_read_empty_list(): void
    {
        $client = $this->clientReturning(new Response(200, ['Content-Type' => 'application/json'], '{"Results":[]}'));

        $this->assertSame([], $client->listInactiveAccounts('contoso.onmicrosoft.com'));
    }

    public function test_bare_empty_array_is_a_read_empty_list(): void
    {
        $client = $this->clientReturning(new Response(200, ['Content-Type' => 'application/json'], '[]'));

        $this->assertSame([], $client->listInactiveAccounts('contoso.onmicrosoft.com'));
    }

    public function test_rows_are_returned_unwrapped(): void
    {
        $client = $this->clientReturning(new Response(200, ['Content-Type' => 'application/json'],
            '{"Results":[{"azureAdUserId":"obj-1","lastSignInDateTime":"2026-01-02T03:04:05Z"}]}'));

        $rows = $client->listInactiveAccounts('contoso.onmicrosoft.com');

        $this->assertIsArray($rows);
        $this->assertSame('obj-1', $rows[0]['azureAdUserId']);
    }

    public function test_empty_body_is_unread_not_empty(): void
    {
        $client = $this->clientReturning(new Response(200, ['Content-Type' => 'application/json'], ''));

        $this->assertNull($client->listInactiveAccounts('contoso.onmicrosoft.com'));
    }

    public function test_unparseable_body_is_unread_not_empty(): void
    {
        $client = $this->clientReturning(new Response(200, ['Content-Type' => 'text/html'], '<html>502</html>'));

        $this->assertNull($client->listInactiveAccounts('contoso.onmicrosoft.com'));
    }

    public function test_error_object_is_unread_not_empty(): void
    {
        // A JSON object that is not a Results envelope carries no list at all — reading
        // it as "nobody is inactive" would clear every flag at the client.
        $client = $this->clientReturning(new Response(200, ['Content-Type' => 'application/json'],
            '{"error":{"code":"Forbidden"}}'));

        $this->assertNull($client->listInactiveAccounts('contoso.onmicrosoft.com'));
    }

    private function clientReturning(Response $response): CippClient
    {
        // Pre-seed the OAuth token so getToken() short-circuits without a network call.
        Cache::put('cipp_oauth_token', 'test-token', 3600);

        $http = new Client([
            'handler' => HandlerStack::create(new MockHandler([$response])),
            'base_uri' => 'https://cipp.example.test/',
        ]);

        return new CippClient(
            ['api_url' => 'https://cipp.example.test'],
            app(CacheInterface::class),
            $http,
        );
    }
}
