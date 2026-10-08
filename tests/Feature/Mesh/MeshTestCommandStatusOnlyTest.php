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
use Tests\TestCase;

/**
 * #5882: mesh:test catches a MeshClientException from getCustomers() and
 * prints a statusPhrase() line, exiting non-zero. Uncaught, the console
 * renderer prints the previous-exception chain: Guzzle's message quoting
 * the request URI (user-info, host) and a vendor body summary.
 *
 * G-5: Http::preventStrayRequests(), and the container's MeshClient is a
 * real one whose Guzzle is a MockHandler: the health read answers 200,
 * then the customer read fails. Synthetic data only (G-13).
 */
class MeshTestCommandStatusOnlyTest extends TestCase
{
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
            'handler' => HandlerStack::create($this->mock),
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
        foreach ([self::USER, self::PASS, self::HOST, self::BODY, 'cURL error', 'Mesh API error', 'resulted in'] as $leak) {
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
