<?php

namespace Tests\Feature\Mesh;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * #5879: IntegrationsController::testMesh() reports a failed connection
 * test by status and exception class only. Guzzle's message (request URI
 * with user-info and host, a vendor body summary) and a
 * MalformedUriException's (the whole URI, password included) reach neither
 * the JSON nor the log. The response still says the test failed, and how.
 *
 * G-5: Http::preventStrayRequests(), and the controller's own Guzzle client
 * is resolved from the container with a MockHandler (an unparseable base
 * URL never reaches it). Synthetic data only (G-13).
 */
class MeshTestConnectionStatusOnlyTest extends TestCase
{
    use RefreshDatabase;

    private const USER = 'synthuser-5879';

    private const PASS = 'SYNTHPASS-5879';

    private const HOST = 'mesh-test.example.test';

    private const BODY = 'VENDOR-BODY-MARKER-5879';

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
        Setting::setEncrypted('mesh_api_key', 'test-placeholder-not-a-key');
    }

    private function bindMock(callable $answer): void
    {
        $this->mock = new MockHandler([$answer]);
        $this->app->bind(GuzzleClient::class, fn ($app, array $params) => new GuzzleClient(
            array_merge($params['config'] ?? [], ['handler' => HandlerStack::create($this->mock)])
        ));
    }

    /** @return array<string, array{0: string, 1: string}> mode => exact JSON message */
    public static function failures(): array
    {
        return [
            'HTTP 503 with a vendor body' => ['503', 'Mesh connection test failed with HTTP 503 ('.ServerException::class.').'],
            'connect failure, no status' => ['connect', 'Mesh connection test failed without an HTTP status ('.ConnectException::class.').'],
            'unparseable base URL' => ['malformed', 'Mesh connection test failed without an HTTP status ('.MalformedUriException::class.').'],
        ];
    }

    #[DataProvider('failures')]
    public function test_a_failed_connection_test_answers_status_only(string $mode, string $expected): void
    {
        Setting::setValue('mesh_base_url', $mode === 'malformed'
            ? '//'.self::USER.':'.self::PASS.'@'.self::HOST.':99999'
            : 'https://'.self::USER.':'.self::PASS.'@'.self::HOST);
        $this->bindMock(fn (RequestInterface $request) => match ($mode) {
            '503' => new Response(503, ['Content-Type' => 'text/plain'], self::BODY),
            default => throw new ConnectException('cURL error 7: Failed to connect for '.$request->getUri(), $request, null, ['errno' => 7]),
        });

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $response = $this->postJson(route('settings.integrations.mesh.test'));

        $response->assertOk()->assertExactJson(['success' => false, 'message' => $expected]);
        $this->assertSame($mode === 'malformed' ? 1 : 0, $this->mock->count(), 'the handler was reached exactly when the URI parses');
        $this->assertNull(Setting::getValue('mesh_connected_at'));

        $surfaces = ['response' => (string) $response->getContent(), 'log' => implode("\n", $this->logged)];
        foreach ($surfaces as $where => $text) {
            foreach ([self::USER, self::PASS, self::HOST, self::BODY, 'cURL error', 'Unable to parse URI'] as $leak) {
                $this->assertStringNotContainsString($leak, $text, "{$where} carries '{$leak}'");
            }
        }
    }

    public function test_a_successful_connection_test_still_connects(): void
    {
        Setting::setValue('mesh_base_url', 'https://'.self::HOST);
        $this->bindMock(fn () => new Response(200, [], '{"results":[]}'));

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->postJson(route('settings.integrations.mesh.test'))
            ->assertOk()->assertExactJson(['success' => true, 'message' => 'Connected to Mesh Email Security!']);
        $this->assertSame(0, $this->mock->count());
        $this->assertNotNull(Setting::getValue('mesh_connected_at'));
    }
}
