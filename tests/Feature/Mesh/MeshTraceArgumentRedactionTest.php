<?php

namespace Tests\Feature\Mesh;

use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshWriteClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #6154, #6155, #6166: with zend.exception_ignore_args Off, getTrace()
 * keeps every argument of every frame, and an array argument is kept
 * whole (getTraceAsString() prints it as 'Array'; an args-collecting
 * reporter does not). Each row drives one public method of a Mesh client
 * into a failure and reads the clients' own frames in getTrace():
 *
 *  - every parameter a row names is a SensitiveParameterValue in its
 *    frame, so dropping that attribute fails the row. #6215: not every
 *    #[\SensitiveParameter] has a row here: ruleAbsent()'s $ruleId (it
 *    catches the MeshClientException) and the constructors' $config are
 *    measured in MeshTraceFrameHardeningTest, and getCustomers()' $filter
 *    only as a non-null value;
 *  - no argument of any Mesh client frame carries the key, tenant id,
 *    filter, sender, comment, rule id or patch value;
 *  - positive control: an unmarked argument ($method) is kept, so the
 *    frames do carry arguments in this run.
 *
 * Only this ini setting is measured; the production value is not read
 * here. G-5: MockHandler only, stray Http requests prevented. Synthetic
 * data (G-13).
 */
class MeshTraceArgumentRedactionTest extends TestCase
{
    private const KEY = 'SECRET_FIXTURE-6154';

    private const TENANT = 'tenant-6154-SYNTH';

    private const FILTER = 'filter-6154-SYNTH';

    private const SENDER = 'sender-6154@vendor.example.test';

    private const COMMENT = 'PSA allow 6154SYNTH';

    private const RULE_ID = 'rule-6166-SYNTH';

    private const EXPIRY = 'expiry-6166-SYNTH';

    private const MARKERS = [self::KEY, self::TENANT, self::FILTER, self::SENDER, self::COMMENT, self::RULE_ID, self::EXPIRY];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** @return array<string, array{0: string, 1: list<array{0: string, 1: string}>}> call => [[frame method, parameter], ...] */
    public static function calls(): array
    {
        $r = MeshClient::class;
        $w = MeshWriteClient::class;

        return [
            'read get()' => ['read:get', [[$r.'::get', 'endpoint'], [$r.'::get', 'params'], [$r.'::request', 'endpoint'], [$r.'::request', 'options']]],
            'read getCustomers()' => ['read:getCustomers', [[$r.'::getCustomers', 'filter'], [$r.'::get', 'params'], [$r.'::request', 'options']]],
            'read getCustomer()' => ['read:getCustomer', [[$r.'::getCustomer', 'uuid'], [$r.'::get', 'endpoint'], [$r.'::request', 'endpoint']]],
            'read preflight()' => ['read:preflight', [[$r.'::preflight', 'endpoint'], [$r.'::request', 'options']]],
            'write createAllowRule()' => ['write:create', [[$w.'::createAllowRule', 'customerId'], [$w.'::createAllowRule', 'sender'], [$w.'::createAllowRule', 'comment'], [$w.'::request', 'endpoint'], [$w.'::request', 'options']]],
            'write listCustomerRules()' => ['write:list', [[$w.'::listCustomerRules', 'customerId'], [$w.'::request', 'options']]],
            'write findRuleByComment()' => ['write:findByComment', [[$w.'::findRuleByComment', 'customerId'], [$w.'::findRuleByComment', 'sender'], [$w.'::findRuleByComment', 'comment'], [$w.'::listCustomerRules', 'customerId']]],
            'write findRulesByComment()' => ['write:findAllByComment', [[$w.'::findRulesByComment', 'customerId'], [$w.'::findRulesByComment', 'sender'], [$w.'::findRulesByComment', 'comment']]],
            'write findRuleById()' => ['write:findById', [[$w.'::findRuleById', 'customerId'], [$w.'::findRuleById', 'ruleId']]],
            'write patchRule()' => ['write:patch', [[$w.'::patchRule', 'ruleId'], [$w.'::patchRule', 'fields'], [$w.'::request', 'endpoint'], [$w.'::request', 'options']]],
            'write deleteRule()' => ['write:delete', [[$w.'::deleteRule', 'ruleId'], [$w.'::request', 'endpoint']]],
            'write preflight()' => ['write:preflight', [[$w.'::preflight', 'endpoint'], [$w.'::request', 'options']]],
        ];
    }

    #[DataProvider('calls')]
    public function test_each_sensitive_parameter_is_redacted_in_its_own_frame(string $call, array $expected): void
    {
        $saved = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            $e = $this->failingCall($call);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $saved);
        }

        $frames = array_values(array_filter(
            $e->getTrace(),
            static fn (array $f): bool => in_array($f['class'] ?? '', [MeshClient::class, MeshWriteClient::class], true),
        ));
        $this->assertNotSame([], $frames, 'premise: the clients\' frames are in the trace');

        foreach ($expected as [$method, $param]) {
            [$class, $function] = explode('::', $method);
            $frame = collect($frames)->first(fn (array $f): bool => $f['class'] === $class && $f['function'] === $function);
            $this->assertNotNull($frame, "premise: a {$method}() frame");
            $index = self::parameterIndex($class, $function, $param);
            $this->assertArrayHasKey($index, $frame['args'], "premise: {$method}() was passed \${$param}");
            $this->assertInstanceOf(\SensitiveParameterValue::class, $frame['args'][$index], "{$method}() frame shows \${$param}");
        }

        // Positive control: an unmarked argument is kept as passed.
        $request = collect($frames)->first(fn (array $f): bool => $f['function'] === 'request');
        $this->assertNotNull($request, 'premise: a request() frame');
        $this->assertContains($request['args'][0], ['GET', 'POST', 'PATCH', 'DELETE'], 'positive control: $method is in the frame');

        $text = self::flatten(array_map(static fn (array $f): array => $f['args'] ?? [], $frames));
        foreach (self::MARKERS as $marker) {
            $this->assertStringNotContainsString($marker, $text, "a Mesh client frame carries '{$marker}'");
        }
    }

    private function failingCall(string $call): MeshClientException
    {
        [$side, $what] = explode(':', $call);
        // A key a header cannot carry makes preflight() throw; else a 503.
        $key = $what === 'preflight' ? self::KEY."\n" : self::KEY;
        $mock = new MockHandler(array_fill(0, 2, new Response(503, [], '{"detail":"x"}')));
        $guzzle = new GuzzleClient(['base_uri' => 'https://mesh-6154.example.test/', 'handler' => HandlerStack::create($mock)]);

        try {
            if ($side === 'read') {
                $client = new MeshClient(['api_key' => $key, 'base_url' => 'https://mesh-6154.example.test']);
                (new \ReflectionProperty($client, 'http'))->setValue($client, $guzzle);
                match ($what) {
                    'get' => $client->get('api/customers/'.self::TENANT.'/', ['filter' => self::FILTER]),
                    'getCustomers' => $client->getCustomers(self::FILTER),
                    'getCustomer', 'preflight' => $client->getCustomer(self::TENANT),
                };
            } else {
                $client = new MeshWriteClient(['api_key' => $key], $guzzle);
                match ($what) {
                    'create' => $client->createAllowRule(self::TENANT, self::SENDER, self::COMMENT, null),
                    'list', 'preflight' => $client->listCustomerRules(self::TENANT),
                    'findByComment' => $client->findRuleByComment(self::TENANT, self::SENDER, self::COMMENT),
                    'findAllByComment' => $client->findRulesByComment(self::TENANT, self::SENDER, self::COMMENT),
                    'findById' => $client->findRuleById(self::TENANT, self::RULE_ID),
                    'patch' => $client->patchRule(self::RULE_ID, ['date_expiry' => self::EXPIRY]),
                    'delete' => $client->deleteRule(self::RULE_ID),
                };
            }
        } catch (MeshClientException $e) {
            return $e;
        }

        $this->fail("{$call} must fail");
    }

    private static function parameterIndex(string $class, string $function, string $param): int
    {
        foreach ((new \ReflectionMethod($class, $function))->getParameters() as $p) {
            if ($p->getName() === $param) {
                return $p->getPosition();
            }
        }

        throw new \LogicException("{$class}::{$function}() has no \${$param}");
    }

    /** Every string and scalar in the frames' arguments, arrays walked whole. */
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
