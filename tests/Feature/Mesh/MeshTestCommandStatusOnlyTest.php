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
 * vacuous. #6053: one full stop after a client-detected refusal. (The
 * uncaught path is MeshUncaughtRenderTest.)
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
            'HTTP 500 with a vendor body' => ['500', 'Connected, but the Mesh host (or something in front of it) answered the customer read with HTTP 500.'],
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
            'HTTP 401' => ['401', 'Mesh connection test failed: the Mesh host (or something in front of it) answered the health read with HTTP 401.'],
            'HTTP 500 with a vendor body' => ['500', 'Mesh connection test failed: the Mesh host (or something in front of it) answered the health read with HTTP 500.'],
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

    /**
     * #6053: a client-detected refusal is the PSA's own sentence, already
     * ending in '.'; the command prints exactly one full stop after it.
     */
    public function test_a_client_detected_refusal_prints_one_full_stop(): void
    {
        $mock = new MockHandler([new Response(200, [], '{"results":[]}')]);
        $client = new MeshClient(['base_url' => 'https://mesh.example.test', 'api_key' => "SECRET_FIXTURE-6053\r\nX: 1"]);
        (new \ReflectionProperty($client, 'http'))->setValue($client, new GuzzleClient(['handler' => HandlerStack::create($mock)]));
        $this->app->instance(MeshClient::class, $client);

        $exit = Artisan::call('mesh:test');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame(1, $mock->count(), 'nothing was sent');
        $this->assertStringContainsString('Mesh connection test failed: The Mesh API key holds a character an HTTP header cannot carry; nothing was sent.'.PHP_EOL, $output);
        $this->assertStringNotContainsString('sent..', $output);
        $this->assertStringNotContainsString('SECRET_FIXTURE-6053', $output);
    }

    /**
     * #6161, #6162: the command's own guard reads the shared definition. A
     * blank key is 'not configured'; a stored '0' is a value, and the line
     * says so. Neither reaches the client.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unsendableKeys(): array
    {
        return [
            'blank' => ['   ', 'Mesh is not configured. Add API key in Settings → Integrations.'],
            "'0'" => ['0', 'Mesh has a stored API key the PSA does not send (zero or true); nothing was sent. Replace it in Settings → Integrations.'],
        ];
    }

    #[DataProvider('unsendableKeys')]
    public function test_an_unsendable_stored_key_is_refused_with_its_own_text(string $stored, string $line): void
    {
        Setting::setEncrypted('mesh_api_key', $stored);
        $this->bindClient([new Response(200, [], '{"results":[]}')]);

        $exit = Artisan::call('mesh:test');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString($line, $output);
        $this->assertSame(1, $this->mock->count(), 'the client was never asked');
    }

    public function test_a_successful_customer_read_still_succeeds(): void
    {
        $this->bindClient([new Response(200, [], '{"results":[]}'), new Response(200, [], '{"results":[]}')]);

        $this->assertSame(Command::SUCCESS, Artisan::call('mesh:test'));
        $this->assertStringContainsString('API responded with customer data.', Artisan::output());
        $this->assertSame(0, $this->mock->count());
    }
}
