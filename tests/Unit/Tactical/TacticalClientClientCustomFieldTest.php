<?php

namespace Tests\Unit\Tactical;

use App\Services\Tactical\TacticalClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * #1276 — the wire shape of a Tactical CLIENT custom-field write.
 *
 * Upstream (clients/views.py, GetUpdateDeleteClient::put) the client branch reads
 * request.data["client"] UNCONDITIONALLY and only touches custom fields when
 * "custom_fields" is a TOP-LEVEL key of request.data. So the body has to carry
 * both keys as siblings, and the client half has to be a JSON OBJECT — DRF's
 * ClientSerializer rejects a list with "Expected a dictionary". PHP serialises an
 * empty array as `[]`, which is why this is asserted against the raw body string
 * and not just the decoded array.
 */
class TacticalClientClientCustomFieldTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    /** @param array<int, Response|\Throwable> $queue */
    private function clientReturning(array $queue): TacticalClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new TacticalClient(new GuzzleClient([
            'base_uri' => 'https://tactical.example.test/',
            'handler' => $stack,
            'headers' => ['X-API-KEY' => 'injected-key'],
        ]));
    }

    private function lastRequest(): RequestInterface
    {
        return $this->history[array_key_last($this->history)]['request'];
    }

    public function test_client_custom_field_write_carries_an_empty_client_object_and_a_sibling_custom_fields_list(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode(['id' => 7, 'name' => 'Acme'])),
        ]);

        $client->setClientCustomField(7, 44, 'org-abc123');

        $request = $this->lastRequest();
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('/clients/7/', $request->getUri()->getPath());

        $raw = (string) $request->getBody();

        // The `client` key must be PRESENT (the view dereferences it) and must be a
        // JSON object, not an array — `"client":[]` fails DRF validation upstream.
        $this->assertStringContainsString('"client":{}', $raw);

        $body = json_decode($raw, true);
        $this->assertArrayHasKey('client', $body);
        $this->assertArrayHasKey('custom_fields', $body, 'custom_fields is a sibling key, never nested inside client');
        $this->assertSame([['field' => 44, 'string_value' => 'org-abc123']], $body['custom_fields']);

        // Nothing about the client itself is being changed; an empty object is the
        // safe no-op under the serializer's partial=True.
        $this->assertSame([], (array) $body['client']);
    }

    public function test_the_policy_update_helper_is_left_alone_and_sends_no_custom_fields(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode(['id' => 7])),
        ]);

        $client->updateClientPolicies(7, ['block_policy_inheritance' => true]);

        $body = json_decode((string) $this->lastRequest()->getBody(), true);
        $this->assertSame(['client' => ['block_policy_inheritance' => true]], $body);
        $this->assertArrayNotHasKey('custom_fields', $body);
    }
}
