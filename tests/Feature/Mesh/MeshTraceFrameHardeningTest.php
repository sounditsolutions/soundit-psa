<?php

namespace Tests\Feature\Mesh;

use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshWriteClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #6214, #6215, #6221: the #[\SensitiveParameter] marks that
 * MeshTraceArgumentRedactionTest cannot reach, each driven by a real throw
 * with zend.exception_ignore_args Off (set here; the production value is
 * not read):
 *
 *  - both constructors' $config (it holds the API key): a base_url that
 *    is not a string makes rtrim() throw a TypeError inside the
 *    constructor, which it does not catch;
 *  - ruleAbsent()'s $ruleId: it catches MeshClientException, so a
 *    handler-thrown RuntimeException (which request() does not catch) is
 *    what reaches its frame;
 *  - request()'s $options on that same non-Guzzle throwable, for both
 *    clients: the Mesh frames carry no key, though the exception left
 *    unwrapped.
 *
 * One row per marked parameter, so dropping any one mark fails its row.
 * Positive control per row: an unmarked argument in the same trace is
 * kept as passed, so the frames do carry arguments in this run.
 *
 * G-5: handler stacks that throw, stray Http requests prevented.
 * Synthetic data (G-13): SECRET_FIXTURE key, example.test host.
 */
class MeshTraceFrameHardeningTest extends TestCase
{
    private const KEY = 'SECRET_FIXTURE-6214';

    private const RULE_ID = 'rule-6215-SYNTH';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** Runs $call with ignore_args Off and returns what it threw. */
    private function thrown(\Closure $call): \Throwable
    {
        $saved = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            $call();
        } catch (\Throwable $e) {
            return $e;
        } finally {
            ini_set('zend.exception_ignore_args', (string) $saved);
        }

        $this->fail('the call must throw');
    }

    /** @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} the frame, all frames */
    private function frame(\Throwable $e, string $class, string $function): array
    {
        $frame = collect($e->getTrace())->first(fn (array $f): bool => ($f['class'] ?? '') === $class && $f['function'] === $function);
        $this->assertNotNull($frame, "premise: a {$class}::{$function}() frame");

        return [$frame, $e->getTrace()];
    }

    /** @return array<string, array{0: class-string}> */
    public static function clients(): array
    {
        return ['read client' => [MeshClient::class], 'write client' => [MeshWriteClient::class]];
    }

    /** #6221: $config is a SensitiveParameterValue in the constructor frame. */
    #[DataProvider('clients')]
    public function test_the_constructor_config_is_redacted(string $class): void
    {
        $e = $this->thrown(fn () => new $class(['api_key' => self::KEY, 'base_url' => ['not-a-string']]));

        $this->assertInstanceOf(\TypeError::class, $e, 'premise: rtrim() refused the array');
        [$ctor, $frames] = $this->frame($e, $class, '__construct');
        $this->assertInstanceOf(\SensitiveParameterValue::class, $ctor['args'][0], "{$class}::__construct() shows \$config");

        // Positive control: rtrim()'s own frame keeps its argument (the
        // base_url array), so this trace does carry arguments.
        $rtrim = collect($frames)->first(fn (array $f): bool => $f['function'] === 'rtrim');
        $this->assertSame(['not-a-string'], $rtrim['args'][0] ?? null, 'positive control: rtrim() was passed the base_url');
        $this->assertStringNotContainsString(self::KEY, self::flatten(array_column($frames, 'args')));
    }

    /**
     * A transport whose handler throws a new $class, built inside the
     * handler so its trace runs through the Mesh client's frames.
     *
     * @param  class-string<\Throwable>  $class
     */
    private static function throwingGuzzle(string $class): GuzzleClient
    {
        return new GuzzleClient([
            'base_uri' => 'https://mesh-6214.example.test/',
            'handler' => HandlerStack::create(function () use ($class): never {
                throw new $class('synthetic handler failure');
            }),
        ]);
    }

    /** #6215: ruleAbsent()'s $ruleId, reached by a throwable it does not catch. */
    public function test_rule_absent_rule_id_is_redacted(): void
    {
        $client = new MeshWriteClient(['api_key' => self::KEY], self::throwingGuzzle(\RuntimeException::class));

        $e = $this->thrown(fn () => $client->ruleAbsent(self::RULE_ID));

        $this->assertInstanceOf(\RuntimeException::class, $e);
        [$frame] = $this->frame($e, MeshWriteClient::class, 'ruleAbsent');
        $this->assertInstanceOf(\SensitiveParameterValue::class, $frame['args'][0], 'ruleAbsent() shows $ruleId');
        [$request] = $this->frame($e, MeshWriteClient::class, 'request');
        $this->assertSame('GET', $request['args'][0], 'positive control: $method is kept');
    }

    /** @return array<string, array{0: class-string, 1: class-string<\Throwable>}> */
    public static function foreignThrowables(): array
    {
        return [
            'read, RuntimeException' => [MeshClient::class, \RuntimeException::class],
            'read, TypeError' => [MeshClient::class, \TypeError::class],
            'write, RuntimeException' => [MeshWriteClient::class, \RuntimeException::class],
            'write, Error' => [MeshWriteClient::class, \Error::class],
        ];
    }

    /**
     * #6214: a non-Guzzle throwable leaves request() as thrown; the Mesh
     * frames on its trace show $options redacted and carry no key.
     */
    #[DataProvider('foreignThrowables')]
    public function test_a_foreign_throwable_leaves_no_key_in_a_mesh_frame(string $class, string $throw): void
    {
        $guzzle = self::throwingGuzzle($throw);
        if ($class === MeshClient::class) {
            $client = new MeshClient(['api_key' => self::KEY, 'base_url' => 'https://mesh-6214.example.test']);
            (new \ReflectionProperty($client, 'http'))->setValue($client, $guzzle);
            $call = fn () => $client->get('api/customers/', ['_size' => 1]);
        } else {
            $client = new MeshWriteClient(['api_key' => self::KEY], $guzzle);
            $call = fn () => $client->listCustomerRules('tenant-6214-SYNTH');
        }

        $e = $this->thrown($call);

        $this->assertSame($throw, $e::class, 'premise: the handler\'s throwable left the client as thrown');
        [$request, $frames] = $this->frame($e, $class, 'request');
        $this->assertInstanceOf(\SensitiveParameterValue::class, $request['args'][2], 'request() shows $options');
        $this->assertSame('GET', $request['args'][0], 'positive control: $method is kept');
        $mesh = array_filter($frames, static fn (array $f): bool => in_array($f['class'] ?? '', [MeshClient::class, MeshWriteClient::class], true));
        $this->assertStringNotContainsString(self::KEY, self::flatten(array_column($mesh, 'args')), 'a Mesh frame carries the key');
    }

    private static function flatten(mixed $value): string
    {
        return match (true) {
            is_array($value) => implode(' ', array_map(self::flatten(...), array_merge(array_keys($value), array_values($value)))),
            is_scalar($value) => (string) $value,
            is_object($value) => $value::class,
            default => '',
        };
    }
}
