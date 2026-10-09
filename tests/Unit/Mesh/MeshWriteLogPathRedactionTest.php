<?php

namespace Tests\Unit\Mesh;

use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshWriteClient;
use App\Services\Mesh\MeshWriteRejectedException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #6107, #6117, #6118: MeshWriteClient::logPath() is an allowlist. The
 * rule collection path is logged as it is; a path under it (a rule id,
 * encoded or not) is logged as 'api/rule-allows-blocks/<rule>/'; anything
 * else that parses is '[endpoint not written as the rule route]' (#6157:
 * they parse, so not '[unparseable endpoint]'; #6218: the label says how
 * the endpoint is written, so '/api/rule-allows-blocks/x/' and an
 * absolute rule-route URL, which may resolve onto the route, get it too).
 * #6217: an endpoint PSR-7 cannot parse is '[unparseable endpoint]' from
 * logPath() itself, whatever calls it. Each row names the predicate it kills. The 400 warning line and the other failure lines are driven
 * through the public methods with a rule id, so they no longer log the
 * same text as the raw endpoint (#6117).
 *
 * G-5: MockHandler only. Synthetic data (G-13).
 */
class MeshWriteLogPathRedactionTest extends TestCase
{
    private const RULE_ID = 'rule-6107-SYNTH';

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = $e->message.' '.json_encode($e->context);
        });
    }

    private static function logPath(string $endpoint): string
    {
        return (new \ReflectionMethod(MeshWriteClient::class, 'logPath'))->invoke(null, $endpoint);
    }

    /** @return array<string, array{0: string, 1: string}> endpoint => logged */
    public static function endpoints(): array
    {
        // #6157: not '[unparseable endpoint]': these all parse.
        $u = MeshWriteClient::OTHER_ENDPOINT;

        return [
            // Kept: the collection path itself, with a query or a fragment cut.
            'collection' => ['api/rule-allows-blocks/', 'api/rule-allows-blocks/'],
            'collection, query cut' => ['api/rule-allows-blocks/?_from=0&_size=100', 'api/rule-allows-blocks/'],
            'collection, fragment cut' => ['api/rule-allows-blocks/#frag', 'api/rule-allows-blocks/'],
            // Redacted: any id under it.
            'rule id' => ['api/rule-allows-blocks/'.self::RULE_ID.'/', 'api/rule-allows-blocks/<rule>/'],
            'uuid rule id' => ['api/rule-allows-blocks/99999999-1111-2222-3333-444455556666/', 'api/rule-allows-blocks/<rule>/'],
            'encoded mailbox id' => ['api/rule-allows-blocks/billing%40vendor.example.test/', 'api/rule-allows-blocks/<rule>/'],
            'encoded colon id' => ['api/rule-allows-blocks/a%3Ab/', 'api/rule-allows-blocks/<rule>/'],
            'id then query' => ['api/rule-allows-blocks/'.self::RULE_ID.'/?x=1', 'api/rule-allows-blocks/<rule>/'],
            // Refused: not this client's route.
            'scheme-less host' => ['mesh.example.test/api/', $u],
            'colon only' => ['mesh.example.test:8443/x', $u],
            'double slash only' => ['//mesh.example.test/x', $u],
            'at only' => ['user@mesh.example.test/x', $u],
            'absolute' => ['https://mesh.example.test/api/rule-allows-blocks/', $u],
            'another route' => ['api/customers/11111111-2222-3333-4444-555555555555/', $u],
            'collection without its slash' => ['api/rule-allows-blocks', $u],
            'empty' => ['', $u],
            // #6218: written otherwise, though each may resolve onto the
            // rule route: the label says how it is written.
            'rooted rule route' => ['/api/rule-allows-blocks/abc/', $u],
            'dot-relative rule route' => ['./api/rule-allows-blocks/abc/', $u],
            'upper-case rule route' => ['API/rule-allows-blocks/', $u],
            // #6217: unparseable, so the fixed fail-closed label from
            // logPath() itself, not the 'not written as' label.
            'unparseable: out-of-range port' => ['//mesh.example.test:99999/api/rule-allows-blocks/', MeshClient::UNPARSEABLE_ENDPOINT],
            'unparseable: empty authority' => ['https:///api/rule-allows-blocks/x/', MeshClient::UNPARSEABLE_ENDPOINT],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_log_path_keeps_only_the_rule_collection_shape(string $endpoint, string $logged): void
    {
        $this->assertSame($logged, self::logPath($endpoint));
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function failures(): array
    {
        return ['400 warning' => ['400', 400], '503 error' => ['503', 503], '302 error' => ['302', 302]];
    }

    /**
     * #6117: the lines are driven with a rule id, so a log line built from
     * the raw endpoint would carry it.
     */
    #[DataProvider('failures')]
    public function test_failure_lines_for_a_rule_carry_no_rule_id(string $mode, int $status): void
    {
        $mock = new MockHandler([new Response($status, $status === 302 ? ['Location' => 'https://elsewhere.example.test/'] : [], '{"detail":"x"}')]);
        $client = new MeshWriteClient(['api_key' => 'test-placeholder-not-a-key'], new GuzzleClient([
            'base_uri' => 'https://mesh-6107.example.test/',
            'handler' => HandlerStack::create($mock),
        ]));

        try {
            $client->deleteRule(self::RULE_ID);
            $this->fail('the delete must fail');
        } catch (MeshClientException $e) {
            $this->assertSame($status === 400, $e instanceof MeshWriteRejectedException);
            $this->assertStringNotContainsString(self::RULE_ID, $e->getMessage());
        }
        $this->assertSame(0, $mock->count(), 'premise: Mesh was asked');

        $log = implode("\n", $this->logged);
        $this->assertStringContainsString('DELETE api/rule-allows-blocks/<rule>/ ', $log, 'positive control: the line names the redacted path');
        $this->assertStringNotContainsString(self::RULE_ID, $log);
    }
}
