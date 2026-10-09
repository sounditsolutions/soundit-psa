<?php

namespace Tests\Unit\Mesh;

use App\Models\Setting;
use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshWriteClient;
use App\Support\MeshConfig;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #6161, #6162, #6163, #6160: one definition of a missing Mesh API key.
 *
 *  - 'is not configured' only for a key that is really absent: null,
 *    false, '' or only spaces and tabs (#6161).
 *  - a stored '0' (with or without spaces and tabs around it, which
 *    PSR-7 trims from a header value), 0, 0.0 or true is a value the
 *    PSA does not send; its
 *    text says it is set (MeshClient::UNUSABLE_KEY), never 'not
 *    configured' (#6161).
 *  - MeshConfig::isConfigured(), MeshWriteClient::isConfigured() and
 *    MeshClient::apiKeyRefusal() agree on every row (#6162).
 *  - preflight()'s key arm, unreachable through the write client's
 *    public methods for a missing or unusable key, is driven directly
 *    with each (#6160, #6206).
 *  - an array key, even [], is not configured: the old empty() test
 *    refused [] (#6208); the header rule still refuses it before send.
 *
 * G-5: MockHandler only, stray Http requests prevented. Synthetic data
 * (G-13): SECRET_FIXTURE keys, example.test hosts.
 */
class MeshKeyDefinitionTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-2222-3333-4444-555555555555';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** @return array<string, array{0: mixed, 1: string|null, 2: bool}> key => [refusal, sendable] */
    public static function keys(): array
    {
        $unusable = MeshClient::UNUSABLE_KEY;

        return [
            'null' => [null, 'is not configured', false],
            'false' => [false, 'is not configured', false],
            'empty' => ['', 'is not configured', false],
            'blanks' => [" \t ", 'is not configured', false],
            "'0'" => ['0', $unusable, false],
            'int 0' => [0, $unusable, false],
            'float 0.0' => [0.0, $unusable, false],
            'true' => [true, $unusable, false],
            // PSR-7 trims spaces and tabs from a header value, so these
            // would go out as '0'.
            "' 0'" => [' 0', $unusable, false],
            "'0' then a tab" => ["0\t", $unusable, false],
            'spaces and a tab around 0' => [" \t0 ", $unusable, false],
            // Not degenerate: a key that merely contains or reads as zero.
            "'00'" => ['00', null, true],
            'int 1' => [1, null, true],
            'plain key' => ['SECRET_FIXTURE-6161', null, true],
            // #6208: never sendable; refused before send by the header rule.
            'empty array' => [[], 'is a list of values, and the PSA sends one key as one header value', false],
            'array holding a key' => [['SECRET_FIXTURE-6208'], 'is a list of values, and the PSA sends one key as one header value', false],
        ];
    }

    #[DataProvider('keys')]
    public function test_one_definition_of_a_missing_key(mixed $key, ?string $refusal, bool $sendable): void
    {
        $this->assertSame($refusal, MeshClient::apiKeyRefusal($key));
        $this->assertSame($refusal === 'is not configured', MeshClient::apiKeyMissing($key), 'apiKeyMissing() is the not-configured set');
        $this->assertSame($refusal === 'is not configured', MeshConfig::apiKeyMissing($key));
        $this->assertSame($sendable, MeshConfig::isSendableKey($key));
        $this->assertSame($sendable, (new MeshWriteClient(['api_key' => $key]))->isConfigured(), 'the write client agrees');
    }

    /**
     * #6162: the settings check reads the same definition. Only strings
     * can be stored, so the string rows.
     *
     * @return array<string, array{0: string|null, 1: bool}>
     */
    public static function storedKeys(): array
    {
        return [
            'none stored' => [null, false],
            'blank' => ['   ', false],
            "'0'" => ['0', false],
            'plain key' => ['SECRET_FIXTURE-6162', true],
        ];
    }

    #[DataProvider('storedKeys')]
    public function test_mesh_config_is_configured_agrees_with_the_clients(?string $stored, bool $configured): void
    {
        if ($stored !== null) {
            Setting::setEncrypted('mesh_api_key', $stored);
        }

        $this->assertSame($configured, MeshConfig::isConfigured());
        $this->assertSame($configured, (new MeshWriteClient(['api_key' => MeshConfig::get('api_key')]))->isConfigured());
        $this->assertSame($configured, MeshClient::apiKeyRefusal(MeshConfig::get('api_key')) === null);
    }

    /** @return array<string, array{0: mixed}> */
    public static function unusableKeys(): array
    {
        return ["'0'" => ['0'], "' 0'" => [' 0'], "'0' then a tab" => ["0\t"], 'int 0' => [0], 'float 0.0' => [0.0], 'true' => [true]];
    }

    /**
     * #6161 end to end: a stored unusable key is refused before send by
     * both clients with the 'is set' text, never 'not configured'.
     */
    #[DataProvider('unusableKeys')]
    public function test_both_clients_refuse_an_unusable_key_as_set_not_as_missing(mixed $key): void
    {
        $expected = 'Mesh API key '.MeshClient::UNUSABLE_KEY.'; nothing was sent.';
        foreach (['read', 'write'] as $which) {
            $mock = new MockHandler([new Response(200, [], '{"results":[],"count":0}')]);
            $guzzle = new GuzzleClient(['base_uri' => 'https://mesh-key.example.test/', 'handler' => HandlerStack::create($mock)]);
            try {
                if ($which === 'read') {
                    $client = new MeshClient(['api_key' => $key, 'base_url' => 'https://mesh-key.example.test']);
                    (new \ReflectionProperty($client, 'http'))->setValue($client, $guzzle);
                    $client->get('api/customers/', ['_size' => 1]);
                } else {
                    (new MeshWriteClient(['api_key' => $key], $guzzle))->listCustomerRules(self::TENANT);
                }
                $this->fail("{$which}: an unusable key must be refused before send");
            } catch (MeshClientException $e) {
                $this->assertStringEndsWith($expected, $e->getMessage(), $which);
                $this->assertStringNotContainsString('not configured', $e->getMessage(), $which);
                $this->assertTrue($e->nothingWasSent(), $which);
            }
            $this->assertSame(1, $mock->count(), "{$which}: no request reached the handler");
        }
    }

    /**
     * #6160: every public write method refuses a missing key in
     * assertConfigured() first, so preflight()'s missing-key arm is driven
     * here directly. Its own text is 'The Mesh API key is not configured',
     * which assertConfigured()'s 'Mesh API key is not configured' is not.
     */
    /**
     * #6206: preflight()'s unusable arm, driven directly for every unusable
     * key: the 'is set' text, nothing sent, nothing at the handler.
     */
    #[DataProvider('unusableKeys')]
    public function test_the_write_preflight_refuses_an_unusable_key_itself(mixed $key): void
    {
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $client = new MeshWriteClient(['api_key' => $key], new GuzzleClient(['handler' => HandlerStack::create($mock)]));

        try {
            (new \ReflectionMethod($client, 'preflight'))->invoke($client, 'GET', MeshWriteClient::RULE_ENDPOINT);
            $this->fail('preflight must refuse an unusable key');
        } catch (MeshClientException $e) {
            $this->assertSame('The Mesh API key '.MeshClient::UNUSABLE_KEY.'; nothing was sent.', $e->getMessage());
            $this->assertTrue($e->nothingWasSent());
        }
        $this->assertSame(1, $mock->count(), 'no request reached the handler');
    }

    public function test_the_write_preflight_refuses_a_missing_key_itself(): void
    {
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $client = new MeshWriteClient(['api_key' => " \t "], new GuzzleClient(['handler' => HandlerStack::create($mock)]));

        try {
            (new \ReflectionMethod($client, 'preflight'))->invoke($client, 'GET', MeshWriteClient::RULE_ENDPOINT);
            $this->fail('preflight must refuse a blank key');
        } catch (MeshClientException $e) {
            $this->assertSame('The Mesh API key is not configured; nothing was sent.', $e->getMessage());
            $this->assertTrue($e->nothingWasSent());
        }
        $this->assertSame(1, $mock->count());

        // And the public path still refuses first, in assertConfigured().
        try {
            $client->listCustomerRules(self::TENANT);
            $this->fail('a blank key must be refused');
        } catch (MeshClientException $e) {
            $this->assertSame('Mesh API key is not configured; nothing was sent.', $e->getMessage());
        }
    }
}
