<?php

namespace Tests\Unit\Mesh;

use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshClientException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #5878: an endpoint parse_url rejects makes PSR-7 throw
 * MalformedUriException, quoting the raw endpoint (user-info and password
 * included), before any handler runs. It is not a GuzzleException, so
 * MeshClient::request() must catch it separately: a status-only
 * [MeshClient] line, a MeshClientException, and no previous exception
 * carrying the raw text. #6049: the line shows the fixed
 * '[unparseable endpoint]', never logPath() of an endpoint PSR-7 refused,
 * so a scheme-less 'host:port/...' endpoint keeps no host on it. #6060:
 * the refusal is client-detected, so statusPhrase() does not read it as
 * a Mesh-side failure.
 *
 * G-5: a real MeshClient whose Guzzle is a MockHandler (never reached: the
 * URI is refused first; its queue is asserted untouched). Synthetic data
 * only (G-13): example.test host, placeholder user and password.
 */
class MeshClientMalformedUriTest extends TestCase
{
    private const HOST = 'mesh-endpoint.example.test';

    private const USER = 'synthuser-5c19';

    private const PASS = 'SYNTHPASS-5c19e2';

    /** @var list<array{level: string, message: string, context: array}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
        });
    }

    /** @return array<string, array{0: string, 1: string}> endpoint => the path the line must show */
    public static function malformed(): array
    {
        return [
            'scheme-relative user-info, out-of-range port' => ['//'.self::USER.':'.self::PASS.'@'.self::HOST.':99999/api/customers/', '[unparseable endpoint]'],
            "scheme-relative user-info, '/' in the password" => ['//'.self::USER.':'.self::PASS.'/z@'.self::HOST.'/api/', '[unparseable endpoint]'],
            // #6049: no '//' and no '@', so logPath() would keep host:port.
            'scheme-less host, out-of-range port' => [self::HOST.':99999/api/customers/', '[unparseable endpoint]'],
        ];
    }

    /** @return array{0: MeshClient, 1: MockHandler} */
    private function client(): array
    {
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $client = new MeshClient(['base_url' => 'https://mesh.example.test', 'api_key' => 'test-placeholder-not-a-key']);
        (new \ReflectionProperty($client, 'http'))->setValue($client, new GuzzleClient([
            'base_uri' => 'https://mesh.example.test/',
            'handler' => HandlerStack::create($mock),
        ]));

        return [$client, $mock];
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_endpoint_throws_a_status_only_mesh_client_exception(string $endpoint, string $expectedPath): void
    {
        // The premise, driven: PSR-7 itself refuses this endpoint with a
        // message quoting the password.
        try {
            new \GuzzleHttp\Psr7\Uri($endpoint);
            $this->fail('PSR-7 accepted the endpoint; the row tests nothing');
        } catch (MalformedUriException $raw) {
            $this->assertStringContainsString(str_contains($endpoint, '@') ? self::PASS : self::HOST, $raw->getMessage());
        }

        [$client, $mock] = $this->client();
        $thrown = null;
        try {
            $client->get($endpoint);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(MeshClientException::class, $thrown);
        $this->assertSame('[unparseable endpoint]', $expectedPath, '#6049: every row logs the fixed text');
        $expected = "GET {$expectedPath} refused: the endpoint could not be parsed (".MalformedUriException::class.'); nothing was sent';
        $this->assertSame('The Mesh request endpoint could not be parsed; nothing was sent.', $thrown->getMessage());
        $this->assertSame($thrown->getMessage(), $thrown->statusPhrase('the read'), '#6060: client-detected, not a Mesh-side failure');
        $this->assertTrue($thrown->nothingWasSent());
        $this->assertSame(0, $thrown->getCode());
        $this->assertNull($thrown->getPrevious(), 'the raw-URI exception is not chained');
        $this->assertSame(1, $mock->count(), 'no request reached the handler');

        $lines = array_values(array_filter($this->logged, fn (array $l): bool => str_starts_with($l['message'], '[MeshClient] ')));
        $this->assertCount(1, $lines, 'one status-only line is written');
        $this->assertSame('error', $lines[0]['level']);
        $this->assertSame("[MeshClient] {$expected}", $lines[0]['message']);
        $this->assertSame([], $lines[0]['context']);

        // report() (the uncaught path) writes its own status-only line.
        $this->app->make(ExceptionHandler::class)->report($thrown);

        foreach ($this->leakSurfaces($thrown) as $where => $text) {
            foreach ([self::USER, self::PASS, self::HOST, 'Unable to parse URI'] as $secret) {
                $this->assertStringNotContainsString($secret, $text, "{$where} carries '{$secret}'");
            }
        }
    }

    /** @return array<string, string> every text this test controls that the exception could reach */
    private function leakSurfaces(\Throwable $thrown): array
    {
        $surfaces = ['getMessage()' => $thrown->getMessage()];
        for ($e = $thrown->getPrevious(), $i = 1; $e !== null; $e = $e->getPrevious(), $i++) {
            $surfaces["getPrevious()#{$i}"] = $e->getMessage();
        }
        foreach ($this->logged as $i => $line) {
            $surfaces["log#{$i}"] = $line['message'].' '.json_encode($line['context']);
        }

        return $surfaces;
    }
}
