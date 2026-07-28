<?php

namespace Tests\Unit\Graph;

use App\Services\Graph\GraphClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * SECURITY SPINE regression (psa-abl0i.1). The staff calendar allowlist gates the owner
 * user_upn, but event_id is caller-controlled and interpolated into the Graph path
 * users/{upn}/events/{eventId}. Guzzle resolves RFC 3986 dot-segments, so a raw
 * event_id="../../../users/other@x/events/ID" walks OUT of the allowlisted mailbox and
 * targets a DIFFERENT mailbox — bypassing the sole control (the Azure Application Access
 * Policy was dropped, so nothing else blocks it).
 *
 * These tests capture the OUTGOING request URI (via an injected Guzzle MockHandler + history
 * middleware, the ServosityClient/TacticalClient wire-test idiom) and prove every
 * caller-controlled path segment is percent-encoded so it can only ever occupy its own
 * segment — the wire target stays bound to the allowlisted mailbox.
 */
class GraphClientPathSafetyTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    /**
     * A GraphClient whose Graph HTTP client is backed by a MockHandler returning $response,
     * with a pre-seeded token so no real auth call is made.
     */
    private function client(Response $response): GraphClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([$response]));
        $stack->push(Middleware::history($this->history));

        $cache = new Repository(new ArrayStore);
        $cache->put('graph_api_token', 'test-token', 3600);

        return new GraphClient([
            'tenant_id' => 'tenant',
            'client_id' => 'client',
            'client_secret' => 'secret',
            'request_timeout' => 15,
            'token_timeout' => 10,
            'handler' => $stack,
        ], $cache);
    }

    private function lastUri(): string
    {
        return (string) $this->history[array_key_last($this->history)]['request']->getUri();
    }

    public function test_event_id_dot_segment_traversal_cannot_escape_the_owner_mailbox(): void
    {
        $client = $this->client(new Response(200, [], json_encode(['id' => 'x'])));

        // The classic attack from the security review: an allowlisted owner, but an event_id
        // that tries to walk up and re-target a non-allowlisted mailbox.
        $client->getEvent('charlie@soundit.co', '../../../users/billing@soundit.co/events/KNOWN');

        $uri = $this->lastUri();

        // The mailbox segment on the wire MUST remain the allowlisted owner...
        $this->assertStringContainsString('users/charlie%40soundit.co/events/', $uri);
        // ...and the traversal target must NOT appear as its own live path segment.
        $this->assertStringNotContainsString('/users/billing', $uri);
    }

    public function test_event_id_raw_slash_stays_within_its_segment(): void
    {
        $client = $this->client(new Response(200, [], json_encode(['id' => 'x'])));

        $client->getEvent('charlie@soundit.co', 'AAA/../BBB');

        $uri = $this->lastUri();
        $this->assertStringContainsString('users/charlie%40soundit.co/events/', $uri);
        // No bare separator survives inside the id — the slash is encoded.
        $this->assertStringContainsString('events/AAA%2F', $uri);
    }

    public function test_event_id_query_and_fragment_cannot_smuggle(): void
    {
        $client = $this->client(new Response(200, [], json_encode(['id' => 'x'])));

        $client->getEvent('charlie@soundit.co', 'AAA?$select=secret#frag');

        $uri = $this->lastUri();
        $this->assertStringContainsString('users/charlie%40soundit.co/events/', $uri);
        // The ? and # are encoded, so no query string or fragment is introduced by the id.
        $this->assertStringNotContainsString('?$select', $uri);
        $this->assertStringNotContainsString('#frag', $uri);
    }

    public function test_owner_upn_is_also_encoded_as_a_single_segment(): void
    {
        $client = $this->client(new Response(200, [], json_encode(['value' => []])));

        // Defense in depth: even though the executor allowlist-gates the UPN, the transport
        // seam encodes it too, so it can never itself carry a traversal.
        $client->calendarView('a/../b@soundit.co', '2026-07-28T00:00:00Z', '2026-07-29T00:00:00Z');

        $uri = $this->lastUri();
        $this->assertStringContainsString('users/a%2F..%2Fb%40soundit.co/calendarView', $uri);
        $this->assertStringNotContainsString('users/b@soundit.co', $uri);
    }
}
