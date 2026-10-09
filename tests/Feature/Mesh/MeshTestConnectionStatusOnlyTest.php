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
use Tests\Support\RecordsGuzzleRejections;
use Tests\TestCase;

/**
 * #5879: IntegrationsController::testMesh() reports a failed connection
 * test by status and exception class only. Guzzle's message (request URI
 * with user-info and host, a vendor body summary) and a
 * MalformedUriException's (the whole URI, password included) reach neither
 * the JSON nor the log. The response still says the test failed, and how.
 *
 * #5981: the warning line is asserted, text and level. #5989: the client
 * the container is asked for carries the 10 s timeout. #5987: every leak
 * marker is first shown on the raw text the controller caught. #5980: a
 * failure after Mesh answered 200 is not reported as a connection failure.
 * #6052: a base URL PSR-7 cannot parse and an API key a header cannot carry
 * are settings faults: the test 'did not run' and the handler is never
 * reached. #6059: a non-200 answer writes the same warning line.
 *
 * G-5: Http::preventStrayRequests(), and the controller's own Guzzle client
 * is resolved from the container with a MockHandler (an unparseable base
 * URL never reaches it). Synthetic data only (G-13).
 */
class MeshTestConnectionStatusOnlyTest extends TestCase
{
    use RecordsGuzzleRejections;
    use RefreshDatabase;

    private const USER = 'synthuser-5879';

    private const PASS = 'SYNTHPASS-5879';

    private const HOST = 'mesh-test.example.test';

    private const BODY = 'VENDOR-BODY-MARKER-5879';

    private const BAD_KEY = "SECRET_FIXTURE-6052\r\nX-Injected: 1";

    /** @var list<string> */
    private array $logged = [];

    /** @var list<array{level: string, message: string}> */
    private array $records = [];

    /** @var list<array<string, mixed>> the config each container build was asked for */
    private array $configs = [];

    private ?MockHandler $mock = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = $e->message.' '.json_encode($e->context);
            $this->records[] = ['level' => $e->level, 'message' => $e->message];
        });
        Setting::setEncrypted('mesh_api_key', 'test-placeholder-not-a-key');
    }

    private function bindMock(callable $answer): void
    {
        $this->mock = new MockHandler([$answer]);
        $this->app->bind(GuzzleClient::class, function ($app, array $params) {
            $this->configs[] = $params['config'] ?? [];

            return new GuzzleClient(array_merge($params['config'] ?? [], ['handler' => $this->recordRejections(HandlerStack::create($this->mock))]));
        });
    }

    /** #5981: exactly one warning, this text. #5989: one build, with the 10 s timeout. */
    private function assertWarnedAndTimed(string $expected): void
    {
        $lines = array_values(array_filter($this->records, fn (array $r): bool => str_starts_with($r['message'], '[IntegrationsController]')));
        $this->assertSame([['level' => 'warning', 'message' => '[IntegrationsController] '.$expected]], $lines, 'one warning line, the response text');
        $this->assertSame([['timeout' => 10]], $this->configs, 'the container built one client, with the 10 s timeout');
    }

    /** @return array<string, array{0: string, 1: string}> mode => exact JSON message */
    public static function failures(): array
    {
        return [
            'HTTP 503 with a vendor body' => ['503', 'Mesh connection test failed with HTTP 503 ('.ServerException::class.').'],
            'connect failure, no status' => ['connect', 'Mesh connection test failed without an HTTP status ('.ConnectException::class.').'],
            'unparseable base URL' => ['malformed', 'Mesh connection test did not run: the Mesh base URL could not be parsed; nothing was sent.'],
            'API key a header cannot carry' => ['badkey', 'Mesh connection test did not run: the Mesh API key holds a character an HTTP header cannot carry; nothing was sent.'],
        ];
    }

    #[DataProvider('failures')]
    public function test_a_failed_connection_test_answers_status_only(string $mode, string $expected): void
    {
        Setting::setValue('mesh_base_url', $mode === 'malformed'
            ? '//'.self::USER.':'.self::PASS.'@'.self::HOST.':99999'
            : 'https://'.self::USER.':'.self::PASS.'@'.self::HOST);
        if ($mode === 'badkey') {
            Setting::setEncrypted('mesh_api_key', self::BAD_KEY);
        }
        $this->bindMock(fn (RequestInterface $request) => match ($mode) {
            '503' => new Response(503, ['Content-Type' => 'text/plain'], self::BODY),
            default => throw new ConnectException('cURL error 7: Failed to connect for '.$request->getUri(), $request, null, ['errno' => 7]),
        });

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $response = $this->postJson(route('settings.integrations.mesh.test'));

        $response->assertOk()->assertExactJson(['success' => false, 'message' => $expected]);
        $this->assertSame(in_array($mode, ['malformed', 'badkey'], true) ? 1 : 0, $this->mock->count(), 'the handler is reached only when the URI and the key would pass PSR-7');
        $this->assertNull(Setting::getValue('mesh_connected_at'));
        $this->assertWarnedAndTimed($expected);

        // #5987: positive control. Each marker is on the raw text the
        // controller caught, so its absence below is not vacuous. Guzzle
        // masks a parsed password in its RequestException message
        // (Psr7\Utils::redactUserInfo), so for the 503 row PASS is read on
        // the request URI instead.
        if ($mode === 'malformed') {
            // #6057: the URI the controller parses (rtrim, as it does).
            try {
                new \GuzzleHttp\Psr7\Uri(rtrim((string) Setting::getValue('mesh_base_url'), '/').'/api/customers/');
                $this->fail('PSR-7 accepted the base URL; the row tests nothing');
            } catch (MalformedUriException $raw) {
                $rawText = $raw->getMessage();
            }
            $markers = [self::USER, self::PASS, self::HOST, 'Unable to parse URI'];
        } elseif ($mode === 'badkey') {
            // The premise, driven: PSR-7 refuses this header value and quotes it.
            try {
                new \GuzzleHttp\Psr7\Request('GET', 'https://'.self::HOST.'/', ['API-KEY' => self::BAD_KEY]);
                $this->fail('PSR-7 accepted the header value; the row tests nothing');
            } catch (\InvalidArgumentException $raw) {
                $rawText = $raw->getMessage();
            }
            $markers = ['SECRET_FIXTURE-6052', 'is not valid header value'];
        } else {
            $raw = $this->lastRejection();
            $rawText = $raw->getMessage().' '.$raw->getRequest()->getUri();
            $markers = $mode === '503' ? [self::USER, self::PASS, self::HOST, self::BODY] : [self::USER, self::PASS, self::HOST, 'cURL error'];
        }
        foreach ($markers as $marker) {
            $this->assertStringContainsString($marker, $rawText, "positive control: the raw text carries '{$marker}'");
        }

        $surfaces = ['response' => (string) $response->getContent(), 'log' => implode("\n", $this->logged)];
        foreach ($surfaces as $where => $text) {
            foreach ([self::USER, self::PASS, self::HOST, self::BODY, 'cURL error', 'Unable to parse URI', 'SECRET_FIXTURE-6052', 'is not valid header value'] as $leak) {
                $this->assertStringNotContainsString($leak, $text, "{$where} carries '{$leak}'");
            }
        }
    }

    /**
     * #6105: testMesh does not follow a redirect. The container's client is
     * built with Guzzle's default redirect setting, so the option on the
     * request is what is graded: one request reached the handler, the
     * queued 200 from the other host was never asked for, and the answer is
     * the 3xx by status, not 'Connected'.
     */
    public function test_a_redirect_is_not_followed_and_is_reported_by_status(): void
    {
        Setting::setValue('mesh_base_url', 'https://'.self::HOST);
        $history = [];
        $this->mock = new MockHandler([
            new Response(302, ['Location' => 'http://elsewhere-6105.example.test/api/customers/']),
            new Response(200, [], '{"results":[]}'),
        ]);
        $this->app->bind(GuzzleClient::class, function ($app, array $params) use (&$history) {
            $this->configs[] = $params['config'] ?? [];
            $stack = HandlerStack::create($this->mock);
            $stack->push(\GuzzleHttp\Middleware::history($history));

            return new GuzzleClient(array_merge($params['config'] ?? [], ['handler' => $stack]));
        });

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $response = $this->postJson(route('settings.integrations.mesh.test'));

        $expected = 'The Mesh host (or something in front of it) answered the connection test with HTTP 302, not 200.';
        $response->assertOk()->assertExactJson(['success' => false, 'message' => $expected]);
        $this->assertCount(1, $history, 'one request: the redirect was not followed');
        $this->assertSame(self::HOST, $history[0]['request']->getUri()->getHost());
        $this->assertSame(1, $this->mock->count(), 'the other host\'s 200 was never asked for');
        $this->assertWarnedAndTimed($expected);
        $this->assertNull(Setting::getValue('mesh_connected_at'));
        $this->assertStringNotContainsString('elsewhere-6105', implode("\n", $this->logged).$response->getContent());
    }

    /**
     * #6115: an InvalidArgumentException from inside the HTTP client that
     * the pre-checks do not cover is not reported as a Mesh failure: no
     * status was recorded and whether a request was sent is not known.
     */
    public function test_a_non_transport_exception_in_the_client_is_not_called_a_mesh_failure(): void
    {
        Setting::setValue('mesh_base_url', 'https://'.self::HOST);
        $this->bindMock(function () {
            throw new \InvalidArgumentException('synthetic: option refused for '.self::HOST);
        });

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $response = $this->postJson(route('settings.integrations.mesh.test'));

        $expected = "Mesh connection test stopped in the PSA's HTTP client with no Mesh status recorded (InvalidArgumentException); whether a request was sent is not known.";
        $response->assertOk()->assertExactJson(['success' => false, 'message' => $expected]);
        $this->assertWarnedAndTimed($expected);
        $this->assertStringNotContainsString(self::HOST, implode("\n", $this->logged).$response->getContent());
    }

    /**
     * #6103: a blank key is refused before send as the clients refuse it.
     * #6162: MeshConfig::isConfigured() now agrees (MeshKeyDefinitionTest).
     */
    public function test_a_blank_key_is_refused_before_send(): void
    {
        Setting::setValue('mesh_base_url', 'https://'.self::HOST);
        Setting::setEncrypted('mesh_api_key', '   ');
        $this->bindMock(fn () => new Response(200, [], '{"results":[]}'));

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $response = $this->postJson(route('settings.integrations.mesh.test'));

        $expected = 'Mesh connection test did not run: the Mesh API key is not configured; nothing was sent.';
        $response->assertOk()->assertExactJson(['success' => false, 'message' => $expected]);
        $this->assertSame(1, $this->mock->count(), 'nothing reached the handler');
    }

    /**
     * #6161: a stored key of '0' is a value, not a missing key: the text
     * says it is set and is not sent, never 'is not configured'.
     */
    public function test_a_stored_zero_key_is_refused_as_set_but_unusable_not_as_missing(): void
    {
        Setting::setValue('mesh_base_url', 'https://'.self::HOST);
        Setting::setEncrypted('mesh_api_key', '0');
        $this->bindMock(fn () => new Response(200, [], '{"results":[]}'));

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $response = $this->postJson(route('settings.integrations.mesh.test'));

        $expected = 'Mesh connection test did not run: the Mesh API key is set, but to zero or true, which the PSA does not send as a key; nothing was sent.';
        $response->assertOk()->assertExactJson(['success' => false, 'message' => $expected]);
        $this->assertStringNotContainsString('not configured', (string) $response->getContent());
        $this->assertSame(1, $this->mock->count(), 'nothing reached the handler');
    }

    /** #6059: a non-200 answer goes through the helper: one warning line, the response text. */
    public function test_a_non_200_answer_is_logged_as_a_failed_test(): void
    {
        Setting::setValue('mesh_base_url', 'https://'.self::HOST);
        $this->bindMock(fn () => new Response(204));

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $response = $this->postJson(route('settings.integrations.mesh.test'));

        $expected = 'The Mesh host (or something in front of it) answered the connection test with HTTP 204, not 200.';
        $response->assertOk()->assertExactJson(['success' => false, 'message' => $expected]);
        $this->assertSame(0, $this->mock->count(), 'Mesh was asked, and answered');
        $this->assertWarnedAndTimed($expected);
        $this->assertNull(Setting::getValue('mesh_connected_at'));
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
        $this->assertSame([['timeout' => 10]], $this->configs, 'the container built one client, with the 10 s timeout (#5989)');
    }

    /**
     * #5980: Mesh answered 200 and only the PSA's own write of the
     * connection time failed. The text says exactly that: not 'failed', and
     * not 'without an HTTP status'.
     */
    public function test_a_settings_write_failure_after_a_200_is_not_a_connection_failure(): void
    {
        Setting::setValue('mesh_base_url', 'https://'.self::HOST);
        $this->bindMock(fn () => new Response(200, [], '{"results":[]}'));
        Setting::saving(function (Setting $setting): void {
            if ($setting->key === 'mesh_connected_at') {
                throw new \RuntimeException('synthetic: the settings write fails at '.self::HOST);
            }
        });

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $response = $this->postJson(route('settings.integrations.mesh.test'));

        $expected = 'The Mesh host (or something in front of it) answered the connection test with HTTP 200, but the PSA could not record the connection time (RuntimeException).';
        $response->assertOk()->assertExactJson(['success' => false, 'message' => $expected]);
        $this->assertSame(0, $this->mock->count(), 'Mesh was asked, and answered');
        $this->assertWarnedAndTimed($expected);
        $this->assertStringNotContainsString(self::HOST, implode("\n", $this->logged));
    }

    /** #5980: a fault before any request is sent says the test did not run. */
    public function test_a_fault_preparing_the_request_says_the_test_did_not_run(): void
    {
        $this->app->bind(GuzzleClient::class, function () {
            throw new \RuntimeException('synthetic: the client cannot be built for '.self::HOST);
        });

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $response = $this->postJson(route('settings.integrations.mesh.test'));

        $expected = 'Mesh connection test did not run: the PSA could not prepare the request (RuntimeException); nothing was sent.';
        $response->assertOk()->assertExactJson(['success' => false, 'message' => $expected]);
        $lines = array_values(array_filter($this->records, fn (array $r): bool => str_starts_with($r['message'], '[IntegrationsController]')));
        $this->assertSame([['level' => 'warning', 'message' => '[IntegrationsController] '.$expected]], $lines);
        $this->assertNull(Setting::getValue('mesh_connected_at'));
        $this->assertStringNotContainsString(self::HOST, implode("\n", $this->logged).$response->getContent());
    }
}
