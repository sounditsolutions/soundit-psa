<?php

namespace Tests\Unit\Mesh;

use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshWriteClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * #5884: MeshWriteClient::request() builds the MeshClientException message
 * from method, logPath(), status and class only, on both arms (the
 * never-sent connect arm and the general failure arm), never from Guzzle's
 * getMessage(), which quotes the request URI (user-info and host) and a
 * summary of the vendor body. The Guzzle exception stays chained as the
 * previous one (its handling is MeshClientException's report()).
 *
 * G-5: the client's own Guzzle seam with a MockHandler; Guzzle's
 * http_errors middleware builds the real ServerException message from a
 * base_uri carrying user-info. Synthetic data only (G-13).
 */
class MeshWriteClientStatusOnlyMessageTest extends TestCase
{
    private const USER = 'synthuser-5884';

    private const PASS = 'SYNTHPASS-5884';

    private const HOST = 'mesh-write.example.test';

    private const BODY = 'VENDOR-BODY-MARKER-5884';

    private const TENANT = '11111111-2222-3333-4444-555555555555';

    /** @return array<string, array{0: string, 1: string, 2: int}> mode => exact message => code */
    public static function failures(): array
    {
        return [
            'HTTP 503' => ['503', 'Mesh API error: GET api/rule-allows-blocks/ failed with HTTP 503 ('.ServerException::class.')', 503],
            'connect, never sent (errno 7)' => ['connect', 'Mesh API unreachable: GET api/rule-allows-blocks/ could not connect (cURL errno 7, '.ConnectException::class.'); nothing was sent.', 0],
            'timeout, no status (errno 28)' => ['timeout', 'Mesh API error: GET api/rule-allows-blocks/ failed with no HTTP status ('.ConnectException::class.')', 0],
        ];
    }

    #[DataProvider('failures')]
    public function test_the_exception_message_is_status_only(string $mode, string $expected, int $code): void
    {
        $mock = new MockHandler([function (RequestInterface $request) use ($mode) {
            return match ($mode) {
                '503' => new Response(503, ['Content-Type' => 'text/plain'], self::BODY),
                'connect' => throw new ConnectException('cURL error 7: Failed to connect for '.$request->getUri(), $request, null, ['errno' => 7]),
                'timeout' => throw new ConnectException('cURL error 28: timed out for '.$request->getUri(), $request, null, ['errno' => 28]),
            };
        }]);
        $stack = HandlerStack::create($mock);
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
        // Positive control: the chained Guzzle message carries what must
        // not reach the client's own message.
        $raw = $thrown->getPrevious()?->getMessage() ?? '';
        $this->assertStringContainsString(self::HOST, $raw, 'positive control: Guzzle quotes the host');
        $this->assertStringContainsString(self::USER, $raw, 'positive control: Guzzle quotes the user');
        if ($mode === '503') {
            $this->assertStringContainsString(self::BODY, $raw, 'positive control: Guzzle quotes the body');
        }

        $this->assertSame($expected, $thrown->getMessage());
        $this->assertSame($code, $thrown->getCode());
        foreach ([self::USER, self::PASS, self::HOST, self::BODY, 'cURL error', 'resulted in'] as $leak) {
            $this->assertStringNotContainsString($leak, $thrown->getMessage(), "message carries '{$leak}'");
        }
    }
}
