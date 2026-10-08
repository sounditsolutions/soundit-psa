<?php

namespace Tests\Unit\Mesh;

use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshWriteClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\Support\RecordsGuzzleRejections;
use Tests\TestCase;

/**
 * #5884: MeshWriteClient::request() builds the MeshClientException message
 * from method, logPath(), status and class only, on both arms (the
 * never-sent connect arm and the general failure arm), never from Guzzle's
 * getMessage(), which quotes the request URI (user-info and host) and a
 * summary of the vendor body. #5978: the Guzzle exception is not chained, so
 * neither (string) $e (what a queue worker stores in failed_jobs) nor the
 * console renderer, which both walk getPrevious(), can print it. #5982: the
 * never-sent arm names the resolve, connect or TLS stage for every errno in
 * NEVER_SENT_CURL_ERRNOS, one row each.
 *
 * G-5: the client's own Guzzle seam with a MockHandler; Guzzle's
 * http_errors middleware builds the real ServerException message from a
 * base_uri carrying user-info. Synthetic data only (G-13).
 */
class MeshWriteClientStatusOnlyMessageTest extends TestCase
{
    use RecordsGuzzleRejections;

    private const USER = 'synthuser-5884';

    private const PASS = 'SYNTHPASS-5884';

    private const HOST = 'mesh-write.example.test';

    private const BODY = 'VENDOR-BODY-MARKER-5884';

    private const TENANT = '11111111-2222-3333-4444-555555555555';

    /** @return array<string, array{0: string, 1: string, 2: int}> mode => exact message => code */
    public static function failures(): array
    {
        $neverSent = fn (int $errno, string $class) => 'Mesh API unreachable: GET api/rule-allows-blocks/ failed at the resolve, connect or TLS stage (cURL errno '.$errno.', '.$class.'); nothing was sent.';

        return [
            'HTTP 503' => ['503', 'Mesh API error: GET api/rule-allows-blocks/ failed with HTTP 503 ('.ServerException::class.')', 503],
            'proxy not resolved, never sent (errno 5)' => ['5', $neverSent(5, RequestException::class), 0],
            'host not resolved, never sent (errno 6)' => ['6', $neverSent(6, ConnectException::class), 0],
            'connect, never sent (errno 7)' => ['7', $neverSent(7, ConnectException::class), 0],
            'TLS handshake, never sent (errno 35)' => ['35', $neverSent(35, ConnectException::class), 0],
            'peer certificate, never sent (errno 51)' => ['51', $neverSent(51, RequestException::class), 0],
            'CA certificate, never sent (errno 60)' => ['60', $neverSent(60, RequestException::class), 0],
            'timeout, no status (errno 28)' => ['28', 'Mesh API error: GET api/rule-allows-blocks/ failed with no HTTP status ('.ConnectException::class.')', 0],
        ];
    }

    #[DataProvider('failures')]
    public function test_the_exception_message_is_status_only(string $mode, string $expected, int $code): void
    {
        $mock = new MockHandler([function (RequestInterface $request) use ($mode) {
            $raw = 'cURL error '.$mode.': failed for '.$request->getUri();

            return match ($mode) {
                '503' => new Response(503, ['Content-Type' => 'text/plain'], self::BODY),
                // Guzzle's CurlFactory promotes 6, 7, 28, 35 (and 52) to
                // ConnectException; 5, 51 and 60 arrive as a plain
                // RequestException with no response.
                '6', '7', '28', '35' => throw new ConnectException($raw, $request, null, ['errno' => (int) $mode]),
                default => throw new RequestException($raw, $request, null, null, ['errno' => (int) $mode]),
            };
        }]);
        $stack = $this->recordRejections(HandlerStack::create($mock));
        $client = new MeshWriteClient(['api_key' => 'test-placeholder-not-a-key'], new GuzzleClient([
            'base_uri' => 'https://'.self::USER.':'.self::PASS.'@'.self::HOST.'/',
            'handler' => $stack,
        ]));

        $thrown = null;
        try {
            $client->listCustomerRules(self::TENANT);
        } catch (MeshClientException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'the scripted read must fail');
        $this->assertSame(0, $mock->count(), 'the request went through the MockHandler');
        // Positive control: the raw Guzzle message the client caught carries
        // what must not reach any surface of the client's own exception.
        $raw = $this->lastRejection()->getMessage();
        $this->assertStringContainsString(self::HOST, $raw, 'positive control: Guzzle quotes the host');
        $this->assertStringContainsString(self::USER, $raw, 'positive control: Guzzle quotes the user');
        if ($mode === '503') {
            $this->assertStringContainsString(self::BODY, $raw, 'positive control: Guzzle quotes the body');
        }

        $this->assertSame($expected, $thrown->getMessage());
        $this->assertSame($code, $thrown->getCode());
        $this->assertSame($mode !== '503' && $mode !== '28', $thrown->nothingWasSent());
        $this->assertNull($thrown->getPrevious(), 'the Guzzle exception is not chained (#5978)');
        $this->assertStringNotContainsString('could not connect', $thrown->getMessage(), 'not every never-sent errno is a connect failure (#5982)');
        // (string) $e is what a queue worker stores in failed_jobs; it
        // prints every previous exception's message.
        foreach (['getMessage()' => $thrown->getMessage(), '(string)' => (string) $thrown] as $where => $text) {
            foreach ([self::USER, self::PASS, self::HOST, self::BODY, 'cURL error', 'resulted in'] as $leak) {
                $this->assertStringNotContainsString($leak, $text, "{$where} carries '{$leak}'");
            }
        }
    }
}
