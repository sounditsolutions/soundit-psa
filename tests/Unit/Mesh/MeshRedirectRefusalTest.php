<?php

namespace Tests\Unit\Mesh;

use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshWriteClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * #6105 (with #6112, #6116): neither Mesh client follows a redirect.
 *
 * Each row answers the first request with a 3xx whose Location names
 * another host, and queues a second answer behind it. The transport is a
 * test-built Guzzle client with Guzzle's DEFAULT allow_redirects, so the
 * per-request option in request() is what is graded; the constructor's
 * own client option is graded separately. A Guzzle history middleware
 * records every request that reached the handler: exactly one, to the
 * configured host, so the API-KEY header went to no other host.
 *
 * G-5: MockHandler only (Http::preventStrayRequests() does not cover a
 * Guzzle client; the history and the unconsumed queue are the evidence).
 * Synthetic data (G-13).
 */
class MeshRedirectRefusalTest extends TestCase
{
    private const HOST = 'mesh-redirect.example.test';

    private const OTHER = 'elsewhere-6105.example.test';

    private const KEY = 'SECRET_FIXTURE-6105';

    private const TENANT = '11111111-2222-3333-4444-555555555555';

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    /** @var list<string> */
    private array $logged = [];

    private ?MockHandler $mock = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = $e->message.' '.json_encode($e->context);
        });
    }

    /** A Guzzle client with Guzzle's default redirect setting, a history and these answers. */
    private function guzzle(array $answers): GuzzleClient
    {
        $this->mock = new MockHandler($answers);
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        return new GuzzleClient(['base_uri' => 'https://'.self::HOST.'/', 'handler' => $stack]);
    }

    private function readClient(GuzzleClient $guzzle): MeshClient
    {
        $client = new MeshClient(['api_key' => self::KEY, 'base_url' => 'https://'.self::HOST]);
        (new \ReflectionProperty($client, 'http'))->setValue($client, $guzzle);

        return $client;
    }

    /** Exactly one request reached the handler, to the configured host; nothing else carried the key. */
    private function assertOneHopOnly(): void
    {
        $this->assertCount(1, $this->history, 'one request reached the handler: the redirect was not followed');
        $this->assertSame(self::HOST, $this->history[0]['request']->getUri()->getHost());
        $this->assertSame(self::KEY, $this->history[0]['request']->getHeaderLine('API-KEY'), 'positive control: the one hop carried the key');
        $this->assertSame(1, $this->mock->count(), 'the queued second answer was never asked for');
    }

    /** No surface names the other host or the Location. */
    private function assertNoLocation(MeshClientException $e): void
    {
        foreach (['message' => $e->getMessage(), 'string' => (string) $e, 'log' => implode("\n", $this->logged)] as $where => $text) {
            $this->assertStringNotContainsString(self::OTHER, $text, "{$where} names the Location host");
            $this->assertStringNotContainsString(self::KEY, $text, "{$where} carries the key");
        }
    }

    /** @return array<string, array{0: int}> */
    public static function redirects(): array
    {
        return ['301' => [301], '302' => [302], '303' => [303], '307' => [307], '308' => [308]];
    }

    #[DataProvider('redirects')]
    public function test_the_read_client_does_not_follow_a_redirect(int $status): void
    {
        $client = $this->readClient($this->guzzle([
            new Response($status, ['Location' => 'http://'.self::OTHER.'/api/customers/']),
            new Response(200, [], '{"results":[{"id":"from-elsewhere"}]}'),
        ]));

        try {
            $client->get('api/customers/', ['_size' => 1]);
            $this->fail('a redirect must fail the read');
        } catch (MeshClientException $e) {
            $this->assertSame($status, $e->getCode(), 'the answered status is the code');
            $this->assertSame("Mesh API error: GET api/customers/ failed with HTTP {$status} (redirect not followed)", $e->getMessage());
            $this->assertSame("the Mesh host (or something in front of it) answered the read with HTTP {$status}", $e->statusPhrase('the read'));
            $this->assertFalse($e->nothingWasSent());
            $this->assertNoLocation($e);
        }
        $this->assertOneHopOnly();
    }

    #[DataProvider('redirects')]
    public function test_the_write_client_does_not_follow_a_redirect_and_a_create_may_have_committed(int $status): void
    {
        $client = new MeshWriteClient(['api_key' => self::KEY], $this->guzzle([
            new Response($status, ['Location' => 'http://'.self::OTHER.'/x']),
            new Response(201, [], '{"added_for":["'.self::TENANT.'"]}'),
        ]));

        try {
            $client->createAllowRule(self::TENANT, 'billing@vendor.example.test', 'PSA allow 6105', null);
            $this->fail('a redirect must fail the create');
        } catch (MeshClientException $e) {
            $this->assertSame($status, $e->getCode());
            $this->assertSame("Mesh API error: POST api/rule-allows-blocks/ failed with HTTP {$status} (redirect not followed)", $e->getMessage());
            $this->assertFalse($e->nothingWasSent(), 'Mesh answered: the write may have been acted on, never nothingSent');
        }
        $this->assertOneHopOnly();
        $this->assertSame('POST', $this->history[0]['request']->getMethod());
        $this->assertNoLocation($e);
    }

    /**
     * #6116: at base a 303 was followed, the second hop failed at name
     * resolution (errno 6), and the create was reported nothingSent. Now
     * there is no second hop, so the never-sent arm cannot fire after Mesh
     * answered.
     */
    public function test_a_redirect_then_a_second_hop_resolve_failure_is_never_nothing_sent(): void
    {
        $client = new MeshWriteClient(['api_key' => self::KEY], $this->guzzle([
            new Response(303, ['Location' => 'https://'.self::OTHER.'/x']),
            fn (RequestInterface $r) => throw new ConnectException('cURL error 6: could not resolve '.$r->getUri()->getHost(), $r, null, ['errno' => 6]),
        ]));

        try {
            $client->createAllowRule(self::TENANT, 'billing@vendor.example.test', 'PSA allow 6116', null);
            $this->fail('a redirect must fail the create');
        } catch (MeshClientException $e) {
            $this->assertFalse($e->nothingWasSent(), '#6116: Mesh answered the POST; nothing-sent would drop a rule it may have committed');
            $this->assertSame(303, $e->getCode());
            $this->assertStringNotContainsString('nothing was sent', $e->getMessage());
        }
        $this->assertOneHopOnly();
    }

    /** #6112: a redirected detail read ending at a 404 is unmeasured, never absent. */
    public function test_rule_absent_does_not_take_a_redirected_404_as_absent(): void
    {
        $client = new MeshWriteClient(['api_key' => self::KEY], $this->guzzle([
            new Response(302, ['Location' => 'https://'.self::OTHER.'/gone/']),
            new Response(404, [], '{"detail":"Not found."}'),
        ]));

        $this->assertNull($client->ruleAbsent('rule-6112'), 'not measured: a redirect is not the rule detail answering 404');
        $this->assertOneHopOnly();
    }

    /** Control for the row above: a 404 from the rule detail itself is still proved absence. */
    public function test_rule_absent_still_takes_a_direct_404_as_absent(): void
    {
        $client = new MeshWriteClient(['api_key' => self::KEY], $this->guzzle([new Response(404, [], '{"detail":"Not found."}')]));

        $this->assertTrue($client->ruleAbsent('rule-6112'));
    }

    /** The clients each build their own transport with redirects off, as well as passing it per request. */
    public function test_both_constructors_build_a_transport_that_follows_no_redirect(): void
    {
        $read = new MeshClient(['api_key' => self::KEY, 'base_url' => 'https://'.self::HOST]);
        $write = new MeshWriteClient(['api_key' => self::KEY, 'base_url' => 'https://'.self::HOST]);

        foreach (['read' => $read, 'write' => $write] as $which => $client) {
            $http = (new \ReflectionProperty($client, 'http'))->getValue($client);
            $this->assertInstanceOf(GuzzleClient::class, $http);
            $this->assertFalse($http->getConfig('allow_redirects'), "{$which} client: allow_redirects is off");
        }
    }
}
