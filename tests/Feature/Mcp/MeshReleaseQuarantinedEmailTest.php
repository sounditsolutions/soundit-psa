<?php

namespace Tests\Feature\Mcp;

use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\Ticket;
use App\Services\Mcp\StaffMeshAdminToolExecutor as Executor;
use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshReadTools;
use App\Services\Mesh\MeshWriteClient;
use App\Support\McpToolModes;
use App\Support\McpToolRegistry;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MeshReleaseQuarantinedEmailTest extends TestCase
{
    use RefreshDatabase;

    private const QUEUE = '11111111-2222-4333-8444-555555555555';

    private Client $client;

    private Ticket $ticket;

    private array $history = [];

    private array $events;

    private array $row;

    private int $status = 200;

    private bool $transportError = false;

    private string $body = '{}';

    private mixed $duringPatch = null;

    private int $patchAttempts = 0;

    private bool $readError = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([]);
        Http::preventStrayRequests();
        Cache::flush();
        Setting::setEncrypted('mesh_api_key', 'synthetic');
        Setting::setValue('mesh_enabled', '1');
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-03-02T12:00:00Z'));
        $this->client = Client::factory()->create(['mesh_customer_id' => 'synthetic-tenant']);
        $this->ticket = Ticket::factory()->create(['client_id' => $this->client->id]);
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/mesh/events-released.json')), true);
        $this->events = $fixture['items'][0]['events'];
        $this->row = [
            'Queue-Id' => self::QUEUE, 'Customer-ID' => 'synthetic-tenant',
            'Timestamp' => '2027-01-01T12:00:00.000000Z', 'Service' => 'Mesh Unified',
            'Recipient' => ['recipient1@example.test', 'recipient2@example.test'],
        ];
        $read = \Mockery::mock(MeshClient::class);
        $read->shouldReceive('get')->with('api/emaillogs/', \Mockery::type('array'))
            ->andReturnUsing(fn () => ['list' => [$this->row]]);
        $read->shouldReceive('get')->with('api/emaillogs/events', ['queue_id' => self::QUEUE])
            ->andReturnUsing(function () {
                if ($this->readError) {
                    throw new \RuntimeException('synthetic read error');
                }

                return $this->events;
            });
        $this->app->instance(MeshClient::class, $read);
        $stack = HandlerStack::create(function ($request) {
            $this->patchAttempts++;
            $this->assertSame('PATCH', $request->getMethod());
            $this->assertSame('/api/message/', $request->getUri()->getPath());
            if ($this->duringPatch) {
                ($this->duringPatch)();
            }
            if ($this->transportError) {
                throw new \GuzzleHttp\Exception\ConnectException('synthetic transport failure', $request);
            }

            return Create::promiseFor(new Response($this->status, [], $this->body));
        });
        $stack->push(Middleware::history($this->history));
        $this->app->instance(MeshWriteClient::class, new MeshWriteClient(['api_key' => 'synthetic'], new Guzzle([
            'base_uri' => 'https://mesh.example.test/', 'handler' => $stack,
        ])));
    }

    private function serve(): void
    {
        $result = app(MeshReadTools::class)->search($this->client, $this->client->id, [], '[test]');
        $this->assertCount(1, $result['emails']);
    }

    private function release(array $extra = [], ?int $clientId = null): array
    {
        return app(Executor::class)->execute(Executor::RELEASE_TOOL, array_replace([
            'ticket_id' => $this->ticket->id, 'queue_id' => self::QUEUE, 'reason' => 'Charlie requested this item',
        ], $extra), $clientId ?? $this->client->id, 'test:operator');
    }

    public function test_fixture_delivery_audit_note_and_iso_year_boundary(): void
    {
        $this->serve();
        $this->duringPatch = function () {
            $this->assertSame(['attempted'], TechnicianActionLog::pluck('result_status')->all());
        };
        $result = $this->release();
        $this->assertSame('delivered', $result['status']);
        $logs = TechnicianActionLog::orderBy('id')->get();
        $this->assertSame(['attempted', 'delivered'], $logs->pluck('result_status')->all());
        $this->assertSame($logs[0]->correlation_id, $logs[1]->correlation_id);
        $note = $this->ticket->notes()->firstOrFail();
        $this->assertTrue($note->is_private);
        foreach ([self::QUEUE, 'Charlie requested this item', 'test:operator', 'delivered', 'recipient count 2'] as $text) {
            $this->assertStringContainsString($text, $note->body);
        }
        $this->assertStringNotContainsString('@', $note->body);
        $this->assertCount(1, $this->history);
        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('202653', $body['messages'][0]['yearweek']);
        $this->assertSame('deliver', $body['action']);
        $this->assertCount(1, $body['messages']);
        $this->assertArrayHasKey('error', $this->release());
        $this->assertCount(1, $this->history);
    }

    public function test_missing_recipient_delivery_is_unconfirmed_and_deduplicated(): void
    {
        $this->serve();
        $this->events = array_values(array_filter($this->events, fn ($e) => $e['action'] !== 'Email delivered' || $e['to'] === ['recipient1@example.test']));
        $this->assertSame('requested-not-confirmed', $this->release()['status']);
        $this->assertArrayHasKey('error', $this->release());
        $this->assertCount(1, $this->history);
    }

    public function test_old_delivery_does_not_confirm_new_patch(): void
    {
        $this->serve();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-03-03T00:00:00Z'));
        $this->assertSame('requested-not-confirmed', $this->release()['status']);
    }

    public function test_released_is_not_delivered(): void
    {
        $this->serve();
        $this->events = array_values(array_filter($this->events, fn ($e) => $e['action'] !== 'Email delivered'));
        $this->assertSame('requested-not-confirmed', $this->release()['status']);
    }

    public function test_bare_string_to_and_narrowed_recipient(): void
    {
        $this->serve();
        foreach ($this->events as &$event) {
            if ($event['action'] === 'Email delivered') {
                $event['to'] = strtoupper($event['to'][0]);
            }
        }
        $this->assertSame('delivered', $this->release(['recipient' => ['recipient1@example.test']])['status']);
        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame(['recipient1@example.test'], $body['messages'][0]['recipient']);
    }

    public function test_unreadable_readback_and_non_json_2xx_are_not_failed(): void
    {
        $this->serve();
        $this->events = ['error' => 'synthetic read failure'];
        $this->body = '<html>not JSON</html>';
        $result = $this->release();
        $this->assertSame('requested-not-confirmed', $result['status']);
        $this->assertSame('Mesh accepted the release; delivery not yet confirmed in the log', $result['message']);
        $this->assertCount(1, $this->history);
    }

    public function test_non_2xx_never_retries(): void
    {
        $this->serve();
        foreach ([401, 403, 404] as $status) {
            $this->status = $status;
            $result = $this->release();
            $this->assertSame('failed', $result['status']);
            if ($status !== 404) {
                $this->assertSame("The Mesh host (or something in front of it) answered the release with HTTP {$status}; the PSA key may lack Deliver access, and partner_access is Charlie's. No retry.", $result['message']);
            } else {
                $this->assertSame('The Mesh host (or something in front of it) answered the release with HTTP 404; no automatic retry', $result['message']);
            }
            $this->assertStringNotContainsString('Mesh refused', $result['message']);
            $summary = TechnicianActionLog::where('result_status', 'failed')->orderByDesc('id')->value('summary');
            $this->assertSame($result['message'], $summary);
        }
        $this->status = 500;
        $this->assertSame('outcome-unknown', $this->release()['status']);
        $this->status = 200;
        $this->assertStringContainsString('unknown outcome', $this->release()['error']);
        $this->assertCount(4, $this->history);
    }

    public function test_post_send_transport_failure_is_outcome_unknown_and_blocks_resend(): void
    {
        $this->serve();
        $this->transportError = true;
        $this->assertSame('outcome-unknown', $this->release()['status']);
        $this->assertSame(['attempted', 'outcome-unknown'], TechnicianActionLog::pluck('result_status')->all());
        $this->transportError = false;
        $this->assertStringContainsString('unknown outcome', $this->release()['error']);
        $this->assertSame(1, $this->patchAttempts);
    }

    public function test_unserved_other_client_batch_and_foreign_recipient_are_refused(): void
    {
        $this->assertArrayHasKey('error', $this->release());
        $this->serve();
        foreach ([['queue_id' => [self::QUEUE]], ['messages' => [self::QUEUE]], ['recipient' => ['foreign@example.test']], ['reason' => ' '], ['service' => 'typed']] as $args) {
            $this->assertArrayHasKey('error', $this->release($args));
        }
        $other = Client::factory()->create(['mesh_customer_id' => 'other-synthetic']);
        $ticket = Ticket::factory()->create(['client_id' => $other->id]);
        $this->assertArrayHasKey('error', $this->release(['ticket_id' => $ticket->id], $other->id));
        $this->assertSame([], $this->history);
    }

    public function test_attempt_audit_failure_prevents_patch(): void
    {
        $this->serve();
        TechnicianActionLog::creating(function ($log) {
            if ($log->action_type === Executor::RELEASE_TOOL) {
                throw new \RuntimeException('synthetic audit failure');
            }
        });
        $this->assertStringContainsString('no PATCH sent', $this->release()['error']);
        $this->assertSame([], $this->history);
    }

    public function test_outcome_audit_failure_preserves_2xx_result_and_warns(): void
    {
        $this->serve();
        TechnicianActionLog::creating(function ($log) {
            if ($log->action_type === Executor::RELEASE_TOOL && $log->result_status !== 'attempted') {
                throw new \RuntimeException('synthetic audit failure');
            }
        });
        $handler = new \Monolog\Handler\TestHandler;
        \Illuminate\Support\Facades\Log::getLogger()->pushHandler($handler);
        $result = $this->release();
        $this->assertSame('delivered', $result['status']);
        $this->assertSame('audit outcome not recorded; attempt row '.$result['attempt_id'], $result['warning']);
        $this->assertStringContainsString($result['warning'], $this->ticket->notes()->firstOrFail()->body);
        $records = array_values(array_filter($handler->getRecords(), fn ($r) => $r->message === '[Mesh] Delivery outcome audit'));
        $this->assertCount(1, $records);
        $this->assertSame(\Monolog\Level::Error, $records[0]->level);
        $this->assertStringContainsString('attempt row '.$result['attempt_id'].') recorded no outcome', $this->release()['error']);
        $this->assertCount(1, $this->history);
    }

    public function test_attempt_without_outcome_blocks_release_after_lock_is_gone(): void
    {
        $this->serve();
        $this->duringPatch = function () {
            $this->duringPatch = null;
            Cache::lock('mesh-release:'.hash('sha256', self::QUEUE))->forceRelease();
            $this->assertStringContainsString('recorded no outcome', $this->release()['error'] ?? '');
        };
        $this->assertSame('delivered', $this->release()['status']);
        $this->assertSame(1, $this->patchAttempts);
    }

    public function test_same_queue_in_flight_is_refused(): void
    {
        $this->serve();
        $this->duringPatch = function () {
            $this->assertStringContainsString('in flight', $this->release()['error']);
        };
        $this->assertSame('delivered', $this->release()['status']);
        $this->assertCount(1, $this->history);
    }

    public function test_readback_exception_is_requested_not_confirmed(): void
    {
        $this->serve();
        $this->readError = true;
        $this->assertSame('requested-not-confirmed', $this->release()['status']);
        $this->assertSame(1, $this->patchAttempts);
    }

    public function test_to_fallback_missing_row_fields_and_remapping(): void
    {
        unset($this->row['Recipient']);
        $this->row['To'] = 'recipient1@example.test';
        $this->serve();
        $this->assertSame('delivered', $this->release()['status']);
        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame(['recipient1@example.test'], $body['messages'][0]['recipient']);
        $this->client->update(['mesh_customer_id' => 'remapped-synthetic']);
        $this->assertArrayHasKey('error', $this->release());
        $this->assertSame(1, $this->patchAttempts);
    }

    public function test_served_queue_proof_cannot_be_replaced_by_cached_fields(): void
    {
        $this->serve();
        Cache::forget('mesh-read-scope:queue:'.$this->client->id.':'.sha1(self::QUEUE));
        $this->assertArrayHasKey('error', $this->release());
        $this->assertSame(0, $this->patchAttempts);
    }

    public function test_missing_delivery_fields_fail_closed(): void
    {
        foreach (['Timestamp', 'Service', 'Recipient'] as $field) {
            $original = $this->row;
            unset($this->row[$field]);
            $this->serve();
            $this->assertArrayHasKey('error', $this->release());
            $this->row = $original;
        }
        $this->assertSame(0, $this->patchAttempts);
    }

    public function test_mcp_boundary_requires_named_grant_and_client(): void
    {
        $this->serve();
        $token = \App\Support\McpConfig::rotateStaffToken(allowedTools: [Executor::RELEASE_TOOL], label: 'synthetic-release');
        $payload = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
            'name' => Executor::RELEASE_TOOL,
            'arguments' => ['client_id' => $this->client->id, 'ticket_id' => $this->ticket->id, 'queue_id' => self::QUEUE, 'reason' => 'Charlie requested this item'],
        ]];
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', $payload);
        $response->assertOk();
        $result = json_decode($response->json('result.content.0.text'), true);
        $this->assertSame('delivered', $result['status'] ?? $result, $response->getContent());
        $full = \App\Support\McpConfig::rotateStaffToken(allowedTools: null, label: 'synthetic-full');
        $denied = $this->withHeaders(['Authorization' => 'Bearer '.$full])->postJson('/api/mcp/staff', $payload);
        $this->assertTrue((bool) $denied->json('result.isError'));
        $this->assertSame(1, $this->patchAttempts);
    }

    public function test_switch_config_registry_and_modes(): void
    {
        $groups = McpToolRegistry::groups();
        $this->assertContains(Executor::RELEASE_TOOL, array_column($groups['mesh_admin']['tools'], 'name'));
        $this->assertSame('staged', McpToolModes::defaultMode(Executor::RELEASE_TOOL));
        $this->assertFalse(McpToolModes::isHeldOnly(Executor::RELEASE_TOOL));
        $this->assertContains(Executor::RELEASE_TOOL, array_column(\App\Support\McpToolSurface::liveClientScopedToolDefinitions(), 'name'));
        Setting::setValue('mesh_enabled', '0');
        $this->assertNotContains(Executor::RELEASE_TOOL, array_column(\App\Support\McpToolSurface::liveClientScopedToolDefinitions(), 'name'));
        $this->assertArrayHasKey('error', $this->release());
        Setting::setValue('mesh_enabled', '1');
        Setting::setEncrypted('mesh_api_key', '');
        $this->assertNotContains(Executor::RELEASE_TOOL, array_column(\App\Support\McpToolSurface::liveClientScopedToolDefinitions(), 'name'));
        $this->assertArrayHasKey('error', $this->release());
        $this->assertSame([], $this->history);
    }
}
