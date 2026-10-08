<?php

namespace Tests\Feature\Mesh;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\MeshAllowRule;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Mesh\MeshAllowRuleReaper;
use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshWriteClient;
use App\Support\McpConfig;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mockery;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * #6062: the preflight refusals and the class-only arm, driven through
 * their consumers with a REAL MeshWriteClient: the approval of a staged
 * add (StaffMeshAdminToolExecutor::createMayHaveCommitted()), ruleAbsent()
 * and the reaper.
 *
 *  - a base URL PSR-7 refused (null http) and an API key a header cannot
 *    carry: nothingSent, so the create is a determinate rejection. The
 *    card stays AwaitingApproval, the audit row is 'rejected', and no
 *    mesh_allow_rules row is written.
 *  - a handler-thrown InvalidArgumentException: nothingSent unset (#6061),
 *    so the create may have committed and is reconciled, fail-closed: an
 *    UNRESOLVED row, executed_with_fault.
 *  - ruleAbsent() and the reaper with a null http: not measured, so the
 *    row is reap_failed, never reaped.
 *
 * G-5: Http faked with stray requests prevented; the write client's Guzzle
 * is a MockHandler, or none at all (null http). Synthetic data (G-13).
 */
class MeshAllowRuleConsumerRefusalTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-2222-3333-4444-555555555555';

    private const SENDER = 'billing@vendor.example.test';

    private const BAD_BASE_URL = '//synthuser-6062:SYNTHPASS-6062@mesh-6062.example.test:99999';

    private ?string $token = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([]);
        Http::preventStrayRequests();
        $this->app->instance(MeshClient::class, Mockery::mock(MeshClient::class));
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    private function actor(): User
    {
        Setting::setEncrypted('mesh_api_key', 'test-placeholder-not-a-key');
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        return $actor;
    }

    private function bindWrite(MeshWriteClient $client): void
    {
        $this->app->instance(MeshWriteClient::class, $client);
    }

    private function callTool(string $name, array $arguments): TestResponse
    {
        $this->token ??= McpConfig::rotateStaffToken(allowedTools: ['mesh_add_allow_rule:staged'], label: 'opsbot');

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    private function stageAdd(): TechnicianRun
    {
        $client = Client::factory()->create(['name' => 'Example Client', 'mesh_customer_id' => self::TENANT]);
        $ticket = Ticket::factory()->for($client)->create(['subject' => 'Vendor mail quarantined']);
        $result = json_decode((string) $this->callTool('mesh_add_allow_rule', [
            'client_id' => $client->id,
            'ticket_id' => $ticket->id,
            'sender' => self::SENDER,
            'confirm_domain' => 'vendor.example.test',
            'reason' => 'Vendor invoices are being quarantined; approved by the client contact.',
        ])->json('result.content.0.text'), true) ?? [];
        $this->assertArrayHasKey('run_id', $result, 'staging failed: '.json_encode($result));

        return TechnicianRun::findOrFail($result['run_id']);
    }

    private function auditStatuses(): array
    {
        return TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->pluck('result_status')->all();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function neverSent(): array
    {
        return [
            'base URL PSR-7 refused (null http)' => ['null-http', 'The Mesh base URL could not be parsed; nothing was sent.'],
            'API key a header cannot carry' => ['bad-key', 'The Mesh API key holds a character an HTTP header cannot carry; nothing was sent.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('neverSent')]
    public function test_a_preflight_refusal_rejects_the_approval_and_writes_no_row(string $mode, string $phrase): void
    {
        $actor = $this->actor();
        $mock = new MockHandler([new Response(201, [], '{"added_for":["'.self::TENANT.'"]}')]);
        $write = $mode === 'null-http'
            ? new MeshWriteClient(['api_key' => 'test-placeholder-not-a-key', 'base_url' => self::BAD_BASE_URL])
            : new MeshWriteClient(['api_key' => "SECRET_FIXTURE-6062\r\nX: 1"], new GuzzleClient(['handler' => HandlerStack::create($mock)]));
        $this->bindWrite($write);
        $http = new \ReflectionProperty($write, 'http');
        if ($mode === 'null-http') {
            // #6110: this row's client has no transport at all, so the
            // MockHandler below is not wired into it and cannot witness a
            // send. What can: the client holds no transport to send with.
            $this->assertNull($http->getValue($write), 'premise: PSR-7 refused the base URL, so the client holds no transport');
        } else {
            $this->assertNotNull($http->getValue($write), 'premise: the MockHandler is this client\'s transport');
        }
        $run = $this->stageAdd();

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');

        $expected = 'The allow rule was not created: '.$phrase;
        $this->assertSame($expected, (string) session('error'));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state, 'a determinate rejection releases the card');
        $this->assertSame(['rejected'], $this->auditStatuses());
        $this->assertSame($expected, TechnicianActionLog::where('result_status', 'rejected')->value('summary'));
        $this->assertSame(0, MeshAllowRule::count(), 'no phantom row for a rule that never existed');
        if ($mode === 'null-http') {
            $this->assertNull($http->getValue($write), 'still no transport after the approval');
        } else {
            $this->assertSame(1, $mock->count(), 'nothing reached the handler');
        }
    }

    /**
     * #6061: a handler-thrown InvalidArgumentException may follow a send,
     * so the create is reconciled: a re-read (empty here) and an
     * UNRESOLVED row, fail-closed; never a clean rejection.
     */
    public function test_a_class_only_failure_is_reconciled_fail_closed(): void
    {
        $actor = $this->actor();
        $mock = new MockHandler([
            function (RequestInterface $request): never {
                throw new \InvalidArgumentException('synthetic: refused after '.$request->getUri()->getHost());
            },
            new Response(200, [], '{"results":[],"count":0}'),
        ]);
        $this->bindWrite(new MeshWriteClient(['api_key' => 'test-placeholder-not-a-key'], new GuzzleClient([
            'base_uri' => 'https://mesh-6062.example.test/',
            'handler' => HandlerStack::create($mock),
        ])));
        $run = $this->stageAdd();

        $this->actingAs($actor)->post(route('cockpit.approve', $run));

        $this->assertSame(0, $mock->count(), 'the create and the reconciling re-read were both made');
        $this->assertSame(['executed_with_fault'], $this->auditStatuses());
        $record = MeshAllowRule::sole();
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $record->state);
        $this->assertFalse((bool) $record->scope_proved);
        $this->assertNull($record->mesh_rule_id);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $summary = (string) TechnicianActionLog::where('result_status', 'executed_with_fault')->value('summary');
        // #6104: the client threw before recording a status; whether Mesh
        // answered is not known, so the text does not say it gave none.
        $this->assertStringStartsWith("Mesh did not acknowledge the create (the create failed in the PSA's HTTP client with no Mesh status recorded)", $summary);
        $this->assertStringNotContainsString('without an HTTP status from Mesh', $summary);
        $this->assertStringNotContainsString('nothing was sent', $summary);
        $this->assertStringNotContainsString('mesh-6062', $summary);
    }

    /**
     * #6105: Mesh answers the create with a redirect, which is not
     * followed. Mesh received the create, so it may have committed: the
     * approval reconciles (re-read, UNRESOLVED row, executed_with_fault),
     * never a clean rejection, and nothing goes to the Location's host.
     */
    public function test_a_redirected_create_is_reconciled_fail_closed(): void
    {
        $actor = $this->actor();
        $history = [];
        $mock = new MockHandler([
            new Response(307, ['Location' => 'http://elsewhere-6105.example.test/api/rule-allows-blocks/']),
            new Response(200, [], '{"results":[],"count":0}'),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $this->bindWrite(new MeshWriteClient(['api_key' => 'test-placeholder-not-a-key'], new GuzzleClient([
            'base_uri' => 'https://mesh-6062.example.test/',
            'handler' => $stack,
        ])));
        $run = $this->stageAdd();

        $this->actingAs($actor)->post(route('cockpit.approve', $run));

        $this->assertSame(['POST', 'GET'], array_map(fn (array $h): string => $h['request']->getMethod(), $history), 'the create, then the reconciling re-read; no hop to the Location');
        foreach ($history as $h) {
            $this->assertSame('mesh-6062.example.test', $h['request']->getUri()->getHost());
        }
        $this->assertSame(['executed_with_fault'], $this->auditStatuses());
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, MeshAllowRule::sole()->state);
        $summary = (string) TechnicianActionLog::where('result_status', 'executed_with_fault')->value('summary');
        $this->assertStringStartsWith('Mesh did not acknowledge the create (Mesh answered the create with HTTP 307)', $summary);
        $this->assertStringNotContainsString('elsewhere-6105', $summary);
    }

    /** ruleAbsent() with a null http: unmeasured (null), never absent. */
    public function test_rule_absent_with_a_null_http_is_unmeasured(): void
    {
        $client = new MeshWriteClient(['api_key' => 'test-placeholder-not-a-key', 'base_url' => self::BAD_BASE_URL]);

        $this->assertNull($client->ruleAbsent('rule-6062'));
    }

    /** The reaper with a null http: the row is reap_failed, never reaped. */
    public function test_the_reaper_with_a_null_http_does_not_reap(): void
    {
        $client = Client::factory()->create(['name' => 'Example Client', 'mesh_customer_id' => self::TENANT]);
        $record = MeshAllowRule::create([
            'client_id' => $client->id,
            'mesh_customer_id' => self::TENANT,
            'sender' => self::SENDER,
            'comment' => 'PSA allow 6062token',
            'mesh_rule_id' => 'rule-6062',
            'expires_at' => now()->subHour()->startOfSecond(),
            'state' => MeshAllowRule::STATE_ACTIVE,
            'scope_proved' => true,
        ]);

        $counts = (new MeshAllowRuleReaper(new MeshWriteClient(['api_key' => 'test-placeholder-not-a-key', 'base_url' => self::BAD_BASE_URL])))->reap();

        $this->assertSame(['examined' => 1, 'reaped' => 0, 'unresolved' => 0, 'failed' => 1], $counts);
        $record->refresh();
        $this->assertSame(MeshAllowRule::STATE_REAP_FAILED, $record->state);
        $this->assertNull($record->reaped_at);
        $this->assertSame('The post-condition read could not be measured, so absence was not proved; the rule is treated as still live.', $record->last_error);
    }
}
