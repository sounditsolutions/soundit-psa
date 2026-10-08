<?php

namespace Tests\Feature\Mesh;

use App\Models\Setting;
use App\Services\Mesh\MeshClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Command\Command;
use Tests\Support\RecordsGuzzleRejections;
use Tests\TestCase;

/**
 * #5882: mesh:test catches a MeshClientException from getCustomers() and
 * prints a statusPhrase() line, exiting non-zero. #5988: a failed health
 * read is reported the same way, never as 'Failed to connect' (Mesh may
 * have answered it). #5987: every leak marker is first shown on the raw
 * Guzzle text the client caught, so its absence from the output is not
 * vacuous. (The uncaught path is MeshUncaughtRenderTest.)
 *
 * G-5: Http::preventStrayRequests(), and the container's MeshClient is a
 * real one whose Guzzle is a MockHandler: the health read answers 200,
 * then the customer read fails. Synthetic data only (G-13).
 */
class MeshTestCommandStatusOnlyTest extends TestCase
{
    use RecordsGuzzleRejections;
    use RefreshDatabase;

    private const USER = 'synthuser-5882';

    private const PASS = 'SYNTHPASS-5882';

    private const HOST = 'mesh-cli.example.test';

    private const BODY = 'VENDOR-BODY-MARKER-5882';

    private MockHandler $mock;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::setEncrypted('mesh_api_key', 'test-placeholder-not-a-key');
    }

    /** @param  list<callable|Response>  $answers */
    private function bindClient(array $answers): void
    {
        $this->mock = new MockHandler($answers);
        $client = new MeshClient(['base_url' => 'https://mesh.example.test', 'api_key' => 'test-placeholder-not-a-key']);
        (new \ReflectionProperty($client, 'http'))->setValue($client, new GuzzleClient([
            'base_uri' => 'https://'.self::USER.':'.self::PASS.'@'.self::HOST.'/',
            'handler' => $this->recordRejections(HandlerStack::create($this->mock)),
        ]));
        $this->app->instance(MeshClient::class, $client);
    }

    /** @return array<string, array{0: string, 1: string}> mode => the exact failure line */
    public static function failures(): array
    {
        return [
            'HTTP 500 with a vendor body' => ['500', 'Connected, but Mesh answered the customer read with HTTP 500.'],
            'connect failure, no status' => ['connect', 'Connected, but the customer read failed without an HTTP status from Mesh.'],
        ];
    }

    #[DataProvider('failures')]
    public function test_a_failed_customer_read_prints_status_only_and_fails(string $mode, string $line): void
    {
        $this->bindClient([
            new Response(200, [], '{"results":[]}'),
            fn (RequestInterface $request) => $mode === '500'
                ? new Response(500, ['Content-Type' => 'text/plain'], self::BODY)
                : throw new ConnectException('cURL error 7: Failed to connect for '.$request->getUri(), $request, null, ['errno' => 7]),
        ]);

        $exit = Artisan::call('mesh:test');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame(0, $this->mock->count(), 'both reads went through the MockHandler');
        $this->assertStringContainsString('Connected to Mesh Email Security successfully!', $output);
        $this->assertStringContainsString($line, $output);
        $this->assertStringNotContainsString('API responded with customer data.', $output);
        $this->assertNoLeak($mode, $output);
    }

    /** #5988: the health read's failure, by statusPhrase(); nothing else runs. */
    public static function healthFailures(): array
    {
        return [
            'HTTP 401' => ['401', 'Mesh connection test failed: Mesh answered the health read with HTTP 401.'],
            'HTTP 500 with a vendor body' => ['500', 'Mesh connection test failed: Mesh answered the health read with HTTP 500.'],
            'connect failure, no status' => ['connect', 'Mesh connection test failed: the health read failed without an HTTP status from Mesh.'],
        ];
    }

    #[DataProvider('healthFailures')]
    public function test_a_failed_health_read_prints_status_only_and_fails(string $mode, string $line): void
    {
        $this->bindClient([
            fn (RequestInterface $request) => $mode === 'connect'
                ? throw new ConnectException('cURL error 7: Failed to connect for '.$request->getUri(), $request, null, ['errno' => 7])
                : new Response((int) $mode, ['Content-Type' => 'text/plain'], self::BODY),
            new Response(200, [], '{"results":[]}'),
        ]);

        $exit = Artisan::call('mesh:test');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame(1, $this->mock->count(), 'only the health read was made');
        $this->assertStringContainsString($line, $output);
        $this->assertStringNotContainsString('Failed to connect', $output, 'not a connect claim when Mesh may have answered');
        $this->assertStringNotContainsString('Connected', $output);
        $this->assertNoLeak($mode, $output);
    }

    /**
     * #5987: each marker is on the raw text the client caught (positive
     * control), then absent from the output. Guzzle masks a parsed password
     * in its message, so PASS is read on the request URI it carries.
     */
    private function assertNoLeak(string $mode, string $output): void
    {
        $raw = $this->lastRejection();
        $rawText = $raw->getMessage().' '.$raw->getRequest()->getUri();
        $markers = [self::USER, self::PASS, self::HOST, $mode === 'connect' ? 'cURL error' : self::BODY];
        foreach ($markers as $marker) {
            $this->assertStringContainsString($marker, $rawText, "positive control: the raw text carries '{$marker}'");
        }
        foreach ([...$markers, 'Mesh API error', 'resulted in'] as $leak) {
            $this->assertStringNotContainsString($leak, $output, "the output carries '{$leak}'");
        }
    }

    public function test_a_successful_customer_read_still_succeeds(): void
    {
        $this->bindClient([new Response(200, [], '{"results":[]}'), new Response(200, [], '{"results":[]}')]);

        $this->assertSame(Command::SUCCESS, Artisan::call('mesh:test'));
        $this->assertStringContainsString('API responded with customer data.', Artisan::output());
        $this->assertSame(0, $this->mock->count());
    }
}
