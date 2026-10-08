<?php

namespace Tests\Unit\Mesh;

use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshWriteClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What both Mesh clients refuse before Guzzle is called, and how the
 * InvalidArgumentException left over is reported:
 *
 *  - #5991: a base_url PSR-7 cannot parse. new Client() throws a
 *    MalformedUriException quoting it (user-info included); the
 *    constructors no longer throw, and every call is refused.
 *  - #5979 / #5985: an API key an HTTP header cannot carry. PSR-7's refusal
 *    ('"<value>" is not valid header value.') quotes the key.
 *  - #5984: an endpoint PSR-7 cannot parse, in MeshWriteClient too.
 *  - #5990: those refusals carry nothingSent.
 *  - #5979: any other InvalidArgumentException from the HTTP client is
 *    reported by class, not as a Mesh status, and claims nothing about
 *    what was sent. #6061: that arm is not only client-side: a 3xx whose
 *    Location PSR-7 cannot parse reaches it AFTER Mesh answered.
 *  - #6056: every arm's log line is asserted whole, so the mechanism it
 *    states ('nothing was sent') is checked where it is written.
 *  - #6048 / #6049 / #6060: the endpoint is parsed first and refused with
 *    the fixed '[unparseable endpoint]', client-detected; the write
 *    client's other arms never log user-info.
 *
 * Every premise is driven first: PSR-7 really quotes the secret. G-5:
 * MockHandler only, stray Http requests prevented, nothing leaves the
 * process. Synthetic data (G-13).
 */
class MeshRequestPreflightTest extends TestCase
{
    private const USER = 'synthuser-b6f';

    private const PASS = 'SYNTHPASS-b6f';

    private const HOST = 'mesh-preflight.example.test';

    private const KEY_FIXTURE = "SECRET_FIXTURE-b6f\r\nX-Injected: 1";

    private const BAD_BASE_URL = '//'.self::USER.':'.self::PASS.'@'.self::HOST.':99999';

    /** @var list<array{level: string, message: string, context: array}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
        });
    }

    /** @return array<string, array{0: string}> */
    public static function clients(): array
    {
        return ['read client' => ['read'], 'write client' => ['write']];
    }

    /** One call through the named client, with $guzzle as its transport when given. */
    private function callMesh(string $which, array $config, ?GuzzleClient $guzzle = null): ?MeshClientException
    {
        try {
            if ($which === 'read') {
                $client = new MeshClient($config);
                if ($guzzle !== null) {
                    (new \ReflectionProperty($client, 'http'))->setValue($client, $guzzle);
                }
                $client->get('api/customers/', ['_size' => 1]);
            } else {
                (new MeshWriteClient($config, $guzzle))->listCustomerRules('11111111-2222-3333-4444-555555555555');
            }
        } catch (MeshClientException $e) {
            return $e;
        }

        return null;
    }

    /** @return array{0: GuzzleClient, 1: MockHandler} */
    private function mockGuzzle(mixed $answer = null): array
    {
        $mock = new MockHandler([$answer ?? new Response(200, [], '{"results":[]}')]);

        return [new GuzzleClient(['base_uri' => 'https://'.self::HOST.'/', 'handler' => HandlerStack::create($mock)]), $mock];
    }

    /** #6056: exactly one log line, at error, with this whole text. */
    private function assertLoggedOnce(string $which, string $text): void
    {
        $prefix = $which === 'read' ? '[MeshClient] ' : '[MeshWriteClient] ';
        $this->assertSame([['level' => 'error', 'message' => $prefix.$text]], array_map(
            fn (array $l): array => ['level' => $l['level'], 'message' => $l['message']], $this->logged,
        ));
    }

    /** No surface of the exception, and no log record, carries any of $secrets. */
    private function assertNothingCarries(MeshClientException $e, array $secrets): void
    {
        $this->assertNull($e->getPrevious(), 'nothing is chained');
        $surfaces = ['getMessage()' => $e->getMessage(), '(string)' => (string) $e];
        foreach ($this->logged as $i => $line) {
            $surfaces["log#{$i}"] = $line['message'].' '.json_encode($line['context']);
        }
        foreach ($surfaces as $where => $text) {
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $text, "{$where} carries '{$secret}'");
            }
        }
    }

    #[DataProvider('clients')]
    public function test_a_base_url_psr7_rejects_is_refused_status_only_and_nothing_is_sent(string $which): void
    {
        // The premise, driven: PSR-7 refuses this base URL in a message
        // that quotes the password.
        try {
            new GuzzleClient(['base_uri' => self::BAD_BASE_URL.'/']);
            $this->fail('PSR-7 accepted the base URL; the row tests nothing');
        } catch (\InvalidArgumentException $raw) {
            $this->assertStringContainsString(self::PASS, $raw->getMessage(), 'positive control: the raw refusal quotes the password');
        }

        // #5991: the constructor no longer throws; the call is refused.
        $e = $this->callMesh($which, ['api_key' => 'test-placeholder-not-a-key', 'base_url' => self::BAD_BASE_URL]);

        $this->assertInstanceOf(MeshClientException::class, $e);
        $this->assertSame('The Mesh base URL could not be parsed; nothing was sent.', $e->getMessage());
        $this->assertSame(0, $e->getCode());
        $this->assertTrue($e->nothingWasSent(), '#5990: nothing was sent');
        $this->assertSame('The Mesh base URL could not be parsed; nothing was sent.', $e->statusPhrase('the read'));
        $this->assertLoggedOnce($which, ($which === 'read' ? 'GET api/customers/' : 'GET api/rule-allows-blocks/').' refused: the Mesh base URL could not be parsed; nothing was sent');
        $this->assertNothingCarries($e, [self::USER, self::PASS, self::HOST, 'Unable to parse URI']);
    }

    #[DataProvider('clients')]
    public function test_an_api_key_a_header_cannot_carry_is_refused_without_quoting_it(string $which): void
    {
        // The premise, driven: PSR-7's refusal quotes the header value.
        try {
            new Request('GET', 'https://'.self::HOST.'/', ['API-KEY' => self::KEY_FIXTURE]);
            $this->fail('PSR-7 accepted the header value; the row tests nothing');
        } catch (\InvalidArgumentException $raw) {
            $this->assertStringContainsString('SECRET_FIXTURE-b6f', $raw->getMessage(), 'positive control: the raw refusal quotes the key');
        }

        [$guzzle, $mock] = $this->mockGuzzle();
        $e = $this->callMesh($which, ['api_key' => self::KEY_FIXTURE, 'base_url' => 'https://'.self::HOST], $guzzle);

        $this->assertInstanceOf(MeshClientException::class, $e, 'a MeshClientException, not a raw InvalidArgumentException (#5985)');
        $this->assertSame('The Mesh API key holds a character an HTTP header cannot carry; nothing was sent.', $e->getMessage());
        // #5979: the operator is told the cause, not a routine Mesh outage.
        $this->assertSame($e->getMessage(), $e->statusPhrase('the read'));
        $this->assertTrue($e->nothingWasSent());
        $this->assertSame(1, $mock->count(), 'no request reached the handler');
        $this->assertLoggedOnce($which, ($which === 'read' ? 'GET api/customers/' : 'GET api/rule-allows-blocks/').' refused: the Mesh API key holds a character an HTTP header cannot carry; nothing was sent');
        $this->assertNothingCarries($e, ['SECRET_FIXTURE-b6f', 'X-Injected', 'is not valid header value']);
    }

    #[DataProvider('clients')]
    public function test_an_endpoint_psr7_rejects_is_refused_with_nothing_sent(string $which): void
    {
        $endpoint = '//'.self::USER.':'.self::PASS.'@'.self::HOST.':99999/api/rule-allows-blocks/';
        try {
            new \GuzzleHttp\Psr7\Uri($endpoint);
            $this->fail('PSR-7 accepted the endpoint; the row tests nothing');
        } catch (\InvalidArgumentException $raw) {
            $this->assertStringContainsString(self::PASS, $raw->getMessage(), 'positive control: the raw refusal quotes the password');
        }

        [$guzzle, $mock] = $this->mockGuzzle();
        $e = null;
        try {
            if ($which === 'read') {
                $client = new MeshClient(['api_key' => 'test-placeholder-not-a-key', 'base_url' => 'https://'.self::HOST]);
                (new \ReflectionProperty($client, 'http'))->setValue($client, $guzzle);
                $client->get($endpoint);
            } else {
                // #5984: no public method takes a raw endpoint; request() is
                // the method under test.
                $client = new MeshWriteClient(['api_key' => 'test-placeholder-not-a-key'], $guzzle);
                (new \ReflectionMethod($client, 'request'))->invoke($client, 'GET', $endpoint);
            }
        } catch (MeshClientException $caught) {
            $e = $caught;
        }

        $this->assertInstanceOf(MeshClientException::class, $e, 'a MeshClientException, not a raw MalformedUriException (#5984)');
        $this->assertSame('The Mesh request endpoint could not be parsed; nothing was sent.', $e->getMessage());
        $this->assertSame(0, $e->getCode());
        $this->assertTrue($e->nothingWasSent(), '#5990: PSR-7 refused it before any handler ran');
        // #6060: decided in the PSA, so not phrased as a Mesh-side failure.
        $this->assertSame($e->getMessage(), $e->statusPhrase('the read'));
        $this->assertSame(1, $mock->count(), 'no request reached the handler');
        $this->assertLoggedOnce($which, 'GET [unparseable endpoint] refused: the endpoint could not be parsed (GuzzleHttp\Psr7\Exception\MalformedUriException); nothing was sent');
        $this->assertNothingCarries($e, [self::USER, self::PASS, self::HOST, 'Unable to parse URI']);
    }

    /**
     * #5979: an InvalidArgumentException from inside the HTTP client that
     * preflight() does not cover (here a handler that throws one, as the
     * vendored CurlFactory does for bad request options; #6061: it is not
     * necessarily client-side, see the redirect row below) is reported by
     * class, says no Mesh status was recorded, claims nothing about what
     * was sent, and quotes nothing from its message.
     */
    #[DataProvider('clients')]
    public function test_another_client_side_invalid_argument_is_reported_by_class_only(string $which): void
    {
        [$guzzle, $mock] = $this->mockGuzzle(function () {
            throw new \InvalidArgumentException('SSL CA bundle not found: /'.self::HOST.'/'.self::PASS);
        });
        $e = $this->callMesh($which, ['api_key' => 'test-placeholder-not-a-key', 'base_url' => 'https://'.self::HOST], $guzzle);

        $this->assertInstanceOf(MeshClientException::class, $e);
        $this->assertStringContainsString('failed in the HTTP client with no Mesh status recorded (InvalidArgumentException)', $e->getMessage());
        $this->assertSame(0, $e->getCode());
        $this->assertFalse($e->nothingWasSent(), 'not measured, so not claimed: a create stays may-have-committed');
        $this->assertSame(0, $mock->count(), 'the handler ran');
        $this->assertLoggedOnce($which, ($which === 'read' ? 'GET api/customers/' : 'GET api/rule-allows-blocks/').' failed in the HTTP client with no Mesh status recorded (InvalidArgumentException)');
        $this->assertNothingCarries($e, [self::HOST, self::PASS, 'SSL CA bundle']);
    }

    /**
     * #6061 measured the class-only arm after a SEND: a 3xx whose Location
     * PSR-7 cannot parse, thrown unwrapped by RedirectMiddleware. #6105
     * stopped following redirects, so that answer is now a failure by its
     * status (#6104: the text names the status Mesh gave), the Location is
     * never parsed, and nothingSent stays unset (Mesh answered).
     */
    #[DataProvider('clients')]
    public function test_a_3xx_with_an_unparseable_location_is_a_status_failure_not_the_class_only_arm(string $which): void
    {
        $location = '//'.self::USER.':'.self::PASS.'@'.self::HOST.':99999/elsewhere';
        [$guzzle, $mock] = $this->mockGuzzle(new Response(302, ['Location' => $location]));
        $e = $this->callMesh($which, ['api_key' => 'test-placeholder-not-a-key', 'base_url' => 'https://'.self::HOST], $guzzle);

        $this->assertInstanceOf(MeshClientException::class, $e);
        $this->assertSame(0, $mock->count(), 'premise: the request reached the handler and Mesh answered 302');
        $this->assertSame(302, $e->getCode());
        $this->assertStringEndsWith('failed with HTTP 302 (redirect not followed)', $e->getMessage());
        $this->assertFalse($e->nothingWasSent(), 'sent and answered: nothingSent must stay unset (fail closed)');
        $this->assertSame('Mesh answered the read with HTTP 302', $e->statusPhrase('the read'));
        $this->assertNothingCarries($e, [self::USER, self::PASS, self::HOST, 'Unable to parse URI']);
    }

    /**
     * #6104: the class-only arm does not say Mesh gave no status: the
     * client threw before recording one, and whether Mesh answered is not
     * known.
     */
    #[DataProvider('clients')]
    public function test_the_class_only_arms_phrase_claims_no_absent_status(string $which): void
    {
        [$guzzle] = $this->mockGuzzle(function () {
            throw new \InvalidArgumentException('synthetic: thrown inside the client for '.self::HOST);
        });
        $e = $this->callMesh($which, ['api_key' => 'test-placeholder-not-a-key', 'base_url' => 'https://'.self::HOST], $guzzle);

        $this->assertInstanceOf(MeshClientException::class, $e);
        $this->assertSame("the read failed in the PSA's HTTP client with no Mesh status recorded", $e->statusPhrase('the read'));
        $this->assertFalse($e->nothingWasSent());
    }

    /**
     * #6048: with a base URL PSR-7 refused, the write client's base-URL arm
     * logs logPath() of the endpoint; an endpoint carrying user-info (one
     * PSR-7 parses, so the endpoint arm does not take it) is logged as the
     * fixed '[endpoint outside the rule route]' (#6157: it parsed, so not
     * '[unparseable endpoint]'), never with its user-info or host.
     */
    public function test_the_write_clients_base_url_arm_never_logs_an_endpoints_user_info(): void
    {
        $client = new MeshWriteClient(['api_key' => 'test-placeholder-not-a-key', 'base_url' => self::BAD_BASE_URL]);
        $e = null;
        try {
            (new \ReflectionMethod($client, 'request'))->invoke($client, 'GET', '//'.self::USER.':'.self::PASS.'@'.self::HOST.'/x');
        } catch (MeshClientException $caught) {
            $e = $caught;
        }

        $this->assertInstanceOf(MeshClientException::class, $e);
        $this->assertSame('The Mesh base URL could not be parsed; nothing was sent.', $e->getMessage());
        $this->assertLoggedOnce('write', 'GET '.MeshWriteClient::OTHER_ENDPOINT.' refused: the Mesh base URL could not be parsed; nothing was sent');
        $this->assertNothingCarries($e, [self::USER, self::PASS, self::HOST]);
    }
}
