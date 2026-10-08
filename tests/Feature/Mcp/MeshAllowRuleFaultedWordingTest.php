<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianTier;
use App\Models\Client;
use App\Models\MeshAllowRule;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshWriteClient;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * #5923: the in-window faultedExecution() refusal says only what was
 * measured, as changedSinceStaging() already does (#5747, contract-s2:3).
 *
 *  - An UNACKNOWLEDGED create (MeshClientException status 0, the re-read
 *    finds nothing, the record insert fails) leaves a record_unwritable
 *    fault row. Nothing measured that the write reached Mesh, so the text
 *    must not say it did; A's run was looked up and carries no record, so
 *    "no PSA record of it exists" stands.
 *  - A RUN-LESS fault row (hand-written) is matched with no record lookup,
 *    so the text claims nothing about a PSA record existing or not.
 *
 * Behaviour is unchanged: both refuse and no second create runs.
 *
 * G-5: Http is faked with stray requests prevented; MeshClient and
 * MeshWriteClient are container doubles and creates are COUNTED. Synthetic
 * data only (G-13).
 */
class MeshAllowRuleFaultedWordingTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-2222-3333-4444-555555555555';

    private const SENDER = 'billing@vendor.example.test';

    private const DOMAIN = 'vendor.example.test';

    private const TAIL = 'so a rule may be live upstream with nothing tracking it and a second rule was not created. '
        .'Check for that rule in the Mesh portal and remove it by hand if it is there — the earlier audit row for this write says what happened — '
        .'and note that the lifetime on this proposal is NOT in force. No upstream call was made.';

    private ?string $token = null;

    private int $creates = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([]);
        Http::preventStrayRequests();
        $this->app->instance(MeshClient::class, Mockery::mock(MeshClient::class));
        $write = Mockery::mock(MeshWriteClient::class);
        $write->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        $write->shouldReceive('createAllowRule')->andReturnUsing(function (): never {
            $this->creates++;

            throw new MeshClientException('synthetic timeout', 0);
        });
        $write->shouldReceive('findRuleByComment')->andReturn(null);
        $this->app->instance(MeshWriteClient::class, $write);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    private function configure(): User
    {
        Setting::setEncrypted('mesh_api_key', 'test-placeholder-not-a-key');
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        return $actor;
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

    private function client(): Client
    {
        return Client::factory()->create(['name' => 'Example Client', 'mesh_customer_id' => self::TENANT]);
    }

    private function stageAdd(Client $client): TechnicianRun
    {
        $ticket = Ticket::factory()->for($client)->create(['subject' => 'Vendor mail quarantined']);
        $response = $this->callTool('mesh_add_allow_rule', [
            'client_id' => $client->id,
            'ticket_id' => $ticket->id,
            'sender' => self::SENDER,
            'confirm_domain' => self::DOMAIN,
            'reason' => 'Vendor invoices are being quarantined; approved by the client contact.',
        ]);
        $result = json_decode((string) $response->json('result.content.0.text'), true) ?? [];
        $this->assertArrayHasKey('run_id', $result, 'staging failed: '.json_encode($result));

        return TechnicianRun::findOrFail($result['run_id']);
    }

    private function approve(User $actor, TechnicianRun $run): TestResponse
    {
        return $this->actingAs($actor)->post(route('cockpit.approve', $run));
    }

    /** Approve $card and assert the in-window fault refusal, whole text, audited, no create. */
    private function assertFaultRefusal(User $actor, TechnicianRun $card, string $expected): void
    {
        $creates = $this->creates;
        $blocked = TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'blocked')->count();
        $this->approve($actor, $card)->assertSessionHas('error');
        $this->assertSame($expected, (string) session('error'));
        $this->assertSame($blocked + 1, TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'blocked')->count());
        $this->assertSame($expected, TechnicianActionLog::where('result_status', 'blocked')->latest('id')->value('summary'));
        $this->assertStringNotContainsString('after the write reached Mesh', $expected);
        $this->assertSame($creates, $this->creates, 'no second create');
    }

    /**
     * Card A's create is never acknowledged (status 0), the re-read finds no
     * rule and the record insert fails: reconcileUnacknowledgedCreate audits
     * executed_with_fault (record_unwritable) against A's run. Card C, inside
     * the window, is refused without the text saying the write reached Mesh.
     */
    public function test_an_unacknowledged_record_unwritable_fault_is_refused_without_saying_the_write_reached_mesh(): void
    {
        $actor = $this->configure();
        $client = $this->client();

        $cardA = $this->stageAdd($client);
        $unwritable = true;
        MeshAllowRule::creating(function () use (&$unwritable): void {
            if ($unwritable) {
                throw new \RuntimeException('synthetic: the record insert fails');
            }
        });
        $this->approve($actor, $cardA);
        $unwritable = false;
        $this->assertSame(1, $this->creates);
        $fault = TechnicianActionLog::where('result_status', 'executed_with_fault')->sole();
        $this->assertSame($cardA->id, (int) $fault->run_id);
        $this->assertStringStartsWith('Mesh did not acknowledge the create', (string) $fault->summary);
        $this->assertStringNotContainsString('re-read found the rule live', (string) $fault->summary);
        $this->assertSame(0, MeshAllowRule::count(), 'no record was written');

        $this->travel(1)->hours();
        $cardC = $this->stageAdd($client);
        $this->assertFaultRefusal($actor, $cardC,
            "An earlier create for '".self::SENDER."' on this client FAULTED (this refusal does not show whether a rule reached Mesh), and no PSA record of it exists, ".self::TAIL);
    }

    /**
     * A hand-written run-less executed_with_fault row (the verb is
     * staged-only, so no verb writes one) inside the window. No record
     * lookup is made for it, so the text says no record can be tied to it,
     * never that none exists.
     */
    public function test_a_run_less_fault_is_refused_without_claiming_a_record_lookup(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $card = $this->stageAdd($client);

        TechnicianActionLog::create([
            'actor_id' => $actor->id,
            'actor_label' => 'hand-written',
            'action_type' => 'mesh_add_allow_rule',
            'tier' => TechnicianTier::Approve->value,
            'result_status' => 'executed_with_fault',
            'client_id' => $client->id,
            'run_id' => null,
            'content_hash' => hash('sha256', json_encode([
                'tool' => 'mesh_stage_add_allow_rule',
                'client_id' => $client->id,
                'target' => 'allow-rule-'.self::SENDER,
                'params' => ['mesh_customer_id' => self::TENANT],
            ])),
            'summary' => 'Synthetic run-less fault.',
            'correlation_id' => (string) Str::uuid(),
        ]);

        $expected = "An earlier create for '".self::SENDER."' on this client was audited against no proposal and FAULTED (this refusal does not show whether a rule reached Mesh), and no PSA record can be tied to it, ".self::TAIL;
        $this->assertFaultRefusal($actor, $card, $expected);
        $this->assertStringNotContainsString('no PSA record of it exists', $expected);
        $this->assertSame(0, $this->creates);
    }

    /**
     * Both shapes in the window: the measured one (a run looked up, no
     * record) is the one the text reports, though the run-less row is older.
     */
    public function test_a_run_fault_beside_a_run_less_fault_reports_the_measured_lookup(): void
    {
        $actor = $this->configure();
        $client = $this->client();
        $hash = hash('sha256', json_encode([
            'tool' => 'mesh_stage_add_allow_rule',
            'client_id' => $client->id,
            'target' => 'allow-rule-'.self::SENDER,
            'params' => ['mesh_customer_id' => self::TENANT],
        ]));
        $cardA = $this->stageAdd($client);
        $card = $this->stageAdd($client);
        foreach ([null, $cardA->id] as $runId) {
            TechnicianActionLog::create([
                'actor_id' => $actor->id,
                'actor_label' => 'hand-written',
                'action_type' => 'mesh_add_allow_rule',
                'tier' => TechnicianTier::Approve->value,
                'result_status' => 'executed_with_fault',
                'client_id' => $client->id,
                'run_id' => $runId,
                'content_hash' => $hash,
                'summary' => 'Synthetic fault.',
                'correlation_id' => (string) Str::uuid(),
            ]);
        }

        $this->assertFaultRefusal($actor, $card,
            "An earlier create for '".self::SENDER."' on this client FAULTED (this refusal does not show whether a rule reached Mesh), and no PSA record of it exists, ".self::TAIL);
    }
}
