<?php

namespace Tests\Unit\Graph;

use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphShapeDriftException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Wire-level proof for the Slice B calendar WRITE transport (psa-lulgh). Mirrors the read-side
 * GraphClientCalendarShapeTest + GraphClientPathSafetyTest idiom: an injected Guzzle MockHandler
 * + history middleware captures the OUTGOING request so we prove the exact Graph method / path /
 * body (grounded in the MS Graph v1.0 producer, fetched 2026-07-28, NOT guessed) AND that every
 * caller-controlled path segment stays percent-encoded — the security spine at the transport seam,
 * now the sole boundary since the Azure Application Access Policy was dropped.
 *
 * create/update return an event resource and MUST fail loud (GraphShapeDriftException) on a
 * bodyless/malformed 2xx — a write whose success we cannot confirm must SCREAM, never read as done
 * (CLAUDE.md vendor rule). cancel/respond are 202-no-body actions.
 *
 * Shapes cited from the producer:
 *  - create:  https://learn.microsoft.com/en-us/graph/api/user-post-events (POST /users/{id}/events -> 201 + event)
 *  - update:  https://learn.microsoft.com/en-us/graph/api/event-update     (PATCH /users/{id}/events/{id} -> 200 + event)
 *  - cancel:  https://learn.microsoft.com/en-us/graph/api/event-cancel     (POST .../cancel -> 202, body {comment?}, organizer-only)
 *  - respond: https://learn.microsoft.com/en-us/graph/api/event-accept     (POST .../{accept|decline|tentativelyAccept} -> 202, body {comment?,sendResponse?})
 */
class GraphClientCalendarWriteTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    private function client(Response $response): GraphClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([$response]));
        $stack->push(Middleware::history($this->history));
        $cache = new Repository(new ArrayStore);
        $cache->put('graph_api_token', 'test-token', 3600);

        return new GraphClient([
            'tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's',
            'request_timeout' => 15, 'token_timeout' => 10, 'handler' => $stack,
        ], $cache);
    }

    private function last(): RequestInterface
    {
        return $this->history[array_key_last($this->history)]['request'];
    }

    /** Documented MS Graph v1.0 event resource returned by create (201) / update (200). */
    private function returnedEvent(): array
    {
        return [
            'id' => 'AAMkAG-new',
            'subject' => 'Onsite: printer swap',
            'webLink' => 'https://outlook.office365.com/owa/?itemid=AAMkAG-new',
            'onlineMeeting' => ['joinUrl' => 'https://teams.microsoft.com/l/meetup-join/xyz'],
        ];
    }

    public function test_create_event_posts_the_event_body_to_the_owner_events_collection(): void
    {
        $client = $this->client(new Response(201, [], json_encode($this->returnedEvent())));

        $event = $client->createEvent('charlie@soundit.co', [
            'subject' => 'Onsite: printer swap',
            'start' => ['dateTime' => '2026-07-29T15:00:00', 'timeZone' => 'UTC'],
            'end' => ['dateTime' => '2026-07-29T16:00:00', 'timeZone' => 'UTC'],
            'attendees' => [['emailAddress' => ['address' => 'contact@clientco.example'], 'type' => 'required']],
        ]);

        $req = $this->last();
        $this->assertSame('POST', $req->getMethod());
        $this->assertStringContainsString('users/charlie%40soundit.co/events', (string) $req->getUri());

        $body = json_decode((string) $req->getBody(), true);
        $this->assertSame('Onsite: printer swap', $body['subject']);
        $this->assertSame('contact@clientco.example', $body['attendees'][0]['emailAddress']['address']);

        // The created event resource is returned, validated.
        $this->assertSame('AAMkAG-new', $event['id']);
    }

    public function test_create_event_screams_on_a_bodyless_success(): void
    {
        // A 201 with a malformed/empty event body (no id) must SCREAM, never read as created.
        $client = $this->client(new Response(201, [], json_encode(['subject' => 'no id'])));
        $this->expectException(GraphShapeDriftException::class);
        $client->createEvent('charlie@soundit.co', ['subject' => 'x']);
    }

    public function test_update_event_patches_and_encodes_the_event_id(): void
    {
        $client = $this->client(new Response(200, [], json_encode($this->returnedEvent())));

        $event = $client->updateEvent('charlie@soundit.co', 'AAA/../BBB', ['subject' => 'Rescheduled']);

        $req = $this->last();
        $this->assertSame('PATCH', $req->getMethod());
        $uri = (string) $req->getUri();
        // Owner stays the allowlisted mailbox; the id's slash is encoded so it cannot escape the segment.
        $this->assertStringContainsString('users/charlie%40soundit.co/events/AAA%2F', $uri);
        $this->assertStringNotContainsString('/users/billing', $uri);
        $this->assertSame('Rescheduled', json_decode((string) $req->getBody(), true)['subject']);
        $this->assertSame('AAMkAG-new', $event['id']);
    }

    public function test_update_event_screams_on_a_malformed_body(): void
    {
        $client = $this->client(new Response(200, [], json_encode(['subject' => 'no id'])));
        $this->expectException(GraphShapeDriftException::class);
        $client->updateEvent('charlie@soundit.co', 'AAA', ['subject' => 'x']);
    }

    public function test_cancel_event_posts_comment_to_the_cancel_action_and_cannot_escape_the_mailbox(): void
    {
        $client = $this->client(new Response(202, [], ''));
        // An allowlisted owner but an event_id that tries to walk to another mailbox.
        $client->cancelEvent('charlie@soundit.co', '../../../users/billing@soundit.co/events/KNOWN', 'Rescheduling to next week');

        $req = $this->last();
        $this->assertSame('POST', $req->getMethod());
        $uri = (string) $req->getUri();
        $this->assertStringContainsString('users/charlie%40soundit.co/events/', $uri);
        $this->assertStringContainsString('/cancel', $uri);
        $this->assertStringNotContainsString('/users/billing', $uri);
        // Documented lowercase `comment` (parameter table is normative; the doc's example shows Comment).
        $this->assertSame(['comment' => 'Rescheduling to next week'], json_decode((string) $req->getBody(), true));
    }

    public function test_cancel_event_sends_an_empty_object_when_no_comment(): void
    {
        $client = $this->client(new Response(202, [], ''));
        $client->cancelEvent('charlie@soundit.co', 'AAA', null);
        // An optional-body action with no comment posts {} (a JSON object), never a stray comment key.
        $this->assertSame('{}', (string) $this->last()->getBody());
    }

    public function test_respond_event_maps_tentative_to_the_tentatively_accept_action(): void
    {
        $client = $this->client(new Response(202, [], ''));
        $client->respondEvent('charlie@soundit.co', 'AAA', 'tentative', 'Maybe', true);

        $req = $this->last();
        $this->assertSame('POST', $req->getMethod());
        $this->assertStringContainsString('users/charlie%40soundit.co/events/AAA/tentativelyAccept', (string) $req->getUri());
        $body = json_decode((string) $req->getBody(), true);
        $this->assertSame('Maybe', $body['comment']);
        $this->assertTrue($body['sendResponse']);
    }

    public function test_respond_event_uses_accept_and_decline_action_paths(): void
    {
        foreach (['accept', 'decline'] as $response) {
            $client = $this->client(new Response(202, [], ''));
            $client->respondEvent('charlie@soundit.co', 'AAA', $response, null, false);
            $req = $this->last();
            $this->assertStringContainsString("events/AAA/{$response}", (string) $req->getUri());
            // No comment key when none supplied; sendResponse always carried.
            $body = json_decode((string) $req->getBody(), true);
            $this->assertArrayNotHasKey('comment', $body);
            $this->assertFalse($body['sendResponse']);
        }
    }

    public function test_respond_event_rejects_an_unknown_response_before_any_call(): void
    {
        $client = $this->client(new Response(202, [], ''));
        $this->expectException(\InvalidArgumentException::class);
        $client->respondEvent('charlie@soundit.co', 'AAA', 'maybe-later', null, true);
    }
}
