<?php

namespace Tests\Feature\Integrations;

use App\Models\Setting;
use App\Services\Litsrmm\LitsrmmClient;
use App\Services\Litsrmm\LitsrmmClientException;
use App\Services\Litsrmm\LitsrmmDevice;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * LITSRMM `GET /v1/devices/{id}`: one device plus what its agent reported
 * about the machine's insides. READ ONLY, and served by a fake transport, so
 * nothing here can reach a real host.
 *
 * The list endpoint carries no CPU, RAM, disk or IP; this call is where they
 * come from, the same list-then-detail shape as LevelClient's
 * GET /v2/devices/{id}.
 */
class LitsrmmDeviceDetailTest extends TestCase
{
    use RefreshDatabase;

    private const ID = '8f14e45f-ceea-467a-9f38-000000000001';

    private array $history = [];

    private string $fakeBearer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeBearer = 'fake-'.bin2hex(random_bytes(8));
        Setting::setEncrypted('litsrmm_api_key', $this->fakeBearer);
        Setting::setValue('litsrmm_base_url', 'https://litsrmm.test');
    }

    private function client(array $responses): LitsrmmClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $this->history = [];
        $stack->push(Middleware::history($this->history));

        return new LitsrmmClient([
            'api_key' => $this->fakeBearer,
            'base_url' => 'https://litsrmm.test',
            'handler' => $stack,
            'request_timeout' => 5,
        ]);
    }

    /** Captured list row 0 plus the inventory block the detail call adds. */
    private static function detail(array $overrides = []): array
    {
        $capture = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/litsrmm/devices-capture-2026-09-24.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return array_merge($capture['devices'][0], [
            'inventory' => [
                'hardware' => ['collectedAt' => '2026-09-30T09:58:35.835Z', 'payload' => ['cpu' => 'Example CPU', 'ramBytes' => 17179869184]],
            ],
        ], $overrides);
    }

    private static function respond(array|string $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], is_string($body) ? $body : json_encode($body));
    }

    public function test_it_reads_one_device_and_its_inventory_on_the_fixed_path(): void
    {
        $client = $this->client([self::respond(self::detail())]);

        $result = $client->getDevice(self::ID);

        $this->assertInstanceOf(LitsrmmDevice::class, $result['device']);
        $this->assertSame(self::ID, $result['device']->id);
        $this->assertSame('Example CPU', $result['inventory']['hardware']['payload']['cpu']);

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/v1/devices/'.self::ID, $request->getUri()->getPath());
        $this->assertSame('', $request->getUri()->getQuery());
        $this->assertSame('Bearer '.$this->fakeBearer, $request->getHeaderLine('Authorization'));
    }

    public function test_an_empty_inventory_is_a_device_without_the_agent_not_an_error(): void
    {
        // `{}` decodes to []. Most of the vendor's devices have no agent, so
        // nothing was ever collected from them.
        $client = $this->client([self::respond(json_encode(array_merge(self::detail(), ['inventory' => new \stdClass])))]);

        $this->assertSame([], $client->getDevice(self::ID)['inventory']);
    }

    public function test_a_body_without_an_inventory_field_is_refused(): void
    {
        // Absent is not empty: a body missing the field cannot say whether the
        // machine reported nothing, and reading it as "nothing" would let a
        // shape change pass as a device with no hardware.
        $body = self::detail();
        unset($body['inventory']);

        $this->expectException(LitsrmmClientException::class);
        $this->expectExceptionMessage('no inventory');

        $this->client([self::respond($body)])->getDevice(self::ID);
    }

    public function test_an_inventory_that_is_not_an_object_is_refused(): void
    {
        $this->expectException(LitsrmmClientException::class);

        $this->client([self::respond(self::detail(['inventory' => 'nope']))])->getDevice(self::ID);
    }

    public function test_an_answer_about_a_different_device_is_refused(): void
    {
        // Writing another machine's hardware onto this asset is the one
        // mistake a detail read must never make.
        $this->expectException(LitsrmmClientException::class);
        $this->expectExceptionMessage('different device');

        $this->client([self::respond(self::detail(['id' => '8f14e45f-ceea-467a-9f38-000000000999']))])->getDevice(self::ID);
    }

    public function test_a_404_is_its_own_refusal(): void
    {
        // The device can leave between the list and the detail read. The sync
        // tells that apart from a failure, so the code must carry through.
        try {
            $this->client([self::respond(['error' => 'Device not found'], 404)])->getDevice(self::ID);
            $this->fail('a 404 must throw');
        } catch (LitsrmmClientException $e) {
            $this->assertSame(404, $e->getCode());
        }
    }

    public function test_401_and_403_keep_their_distinct_meanings(): void
    {
        foreach ([401 => 'invalid key', 403 => 'devices scope'] as $status => $phrase) {
            try {
                $this->client([self::respond(['error' => 'no'], $status)])->getDevice(self::ID);
                $this->fail("{$status} must throw");
            } catch (LitsrmmClientException $e) {
                $this->assertSame($status, $e->getCode());
                $this->assertStringContainsString($phrase, $e->getMessage());
            }
        }
    }

    #[DataProvider('unsafeIds')]
    public function test_an_id_that_could_change_the_path_is_refused_before_any_request(string $id): void
    {
        $client = $this->client([self::respond(self::detail())]);

        try {
            $client->getDevice($id);
            $this->fail("'{$id}' must be refused");
        } catch (LitsrmmClientException) {
            $this->assertCount(0, $this->history, 'nothing may leave for an unsafe id');
        }
    }

    public static function unsafeIds(): array
    {
        return [
            'empty' => [''],
            'traversal' => ['../clients'],
            'extra segment' => [self::ID.'/x'],
            'query smuggling' => [self::ID.'?limit=1'],
            'encoded slash' => ['a%2Fb'],
            'too long' => [str_repeat('a', 65)],
        ];
    }

    public function test_a_disabled_integration_reads_nothing(): void
    {
        Setting::setValue('litsrmm_enabled', '0');
        $client = $this->client([self::respond(self::detail())]);

        try {
            $client->getDevice(self::ID);
            $this->fail('a disabled integration must refuse');
        } catch (LitsrmmClientException) {
            $this->assertCount(0, $this->history);
        }
    }
}
