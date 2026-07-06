<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use App\Services\Technician\TechnicianApprovalService;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * bd psa-k4s0 sibling audit: StaffTacticalActionToolExecutor::stageAction (~line 502) has
 * the SAME two-root-cause shape as the primary CIPP bug — a post-create supersede keyed
 * only on ticket_id + action_type + AwaitingApproval (Root A), and a dedup guard
 * (alreadyAwaitingOrExecuted) that consults the immutable TechnicianActionLog instead of
 * the live runs table, so a stale 'awaiting_approval' audit row can produce
 * idempotent:true with run_id:null after the live run is gone (Root B). Fixed identically:
 * content-hash-scoped supersede (via firstOrCreate, respecting the technician_runs
 * idempotency unique key) + live-runs-table liveness.
 */
class TacticalStagedActionSupersedeTest extends TestCase
{
    use RefreshDatabase;

    private function configureTactical(): void
    {
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'secret');
    }

    private function configureAiActor(): User
    {
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        return $actor;
    }

    private function token(array $tools): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: 'opsbot');
    }

    private function callTool(string $token, string $name, array $arguments = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** @return array<string, mixed> */
    private function decodedResult(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    /** @return array{client: Client, assetA: Asset, assetB: Asset, ticket: Ticket} */
    private function twoAssetFixture(): array
    {
        $client = Client::factory()->create(['name' => 'Acme']);
        $contact = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Client',
            'last_name' => 'Contact',
            'email' => 'client@example.test',
            'is_active' => true,
        ]);

        $assetA = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'PC-01', 'name' => 'PC-01']);
        TacticalAsset::create(['asset_id' => $assetA->id, 'agent_id' => 'agent-1', 'hostname' => 'PC-01', 'status' => 'online', 'synced_at' => now()]);

        $assetB = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'PC-02', 'name' => 'PC-02']);
        TacticalAsset::create(['asset_id' => $assetB->id, 'agent_id' => 'agent-2', 'hostname' => 'PC-02', 'status' => 'online', 'synced_at' => now()]);

        $ticket = Ticket::factory()->for($client)->create(['contact_id' => $contact->id, 'subject' => 'Two-device incident']);
        $ticket->assets()->attach([$assetA->id, $assetB->id]);

        return compact('client', 'assetA', 'assetB', 'ticket');
    }

    /** @return array<string, mixed> */
    private function stageCommandArgs(array $fixture, string $hostname, string $cmd, string $reason): array
    {
        return [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'hostname' => $hostname,
            'shell' => 'cmd',
            'cmd' => $cmd,
            'timeout' => 30,
            'reason' => $reason,
        ];
    }

    public function test_two_different_content_staged_commands_coexist(): void
    {
        $this->configureTactical();
        $actor = $this->configureAiActor();
        $fixture = $this->twoAssetFixture();
        $token = $this->token(['tactical_stage_command']);

        $blocked = Mockery::mock(TacticalClient::class);
        $blocked->shouldNotReceive('cmd');
        $this->app->instance(TacticalClient::class, $blocked);

        $first = $this->callTool($token, 'tactical_stage_command', $this->stageCommandArgs(
            $fixture, 'PC-01', 'ipconfig /flushdns', 'Flush DNS on PC-01.',
        ));
        $first->assertOk();
        $this->assertFalse((bool) $first->json('result.isError'), (string) $first->json('result.content.0.text'));
        $firstResult = $this->decodedResult($first);
        $this->assertArrayNotHasKey('idempotent', $firstResult);
        $runIdA = $firstResult['run_id'];

        // proposalCooldownActive here is ticket+action_type scoped (not per-asset), so
        // step past the 300s cooldown before staging the SECOND, unrelated command —
        // this test is about supersede/coexistence, not the (separate, unrelated) cooldown.
        $this->travel(301)->seconds();

        // A materially DIFFERENT staged action (different asset, different command).
        $second = $this->callTool($token, 'tactical_stage_command', $this->stageCommandArgs(
            $fixture, 'PC-02', 'ipconfig /release', 'Release the lease on PC-02 instead.',
        ));
        $second->assertOk();
        $this->assertFalse((bool) $second->json('result.isError'), (string) $second->json('result.content.0.text'));
        $secondResult = $this->decodedResult($second);
        $this->assertArrayNotHasKey('idempotent', $secondResult);
        $runIdB = $secondResult['run_id'];

        $this->assertNotSame($runIdA, $runIdB, 'distinct-content staged actions must produce distinct runs');

        $runA = TechnicianRun::findOrFail($runIdA);
        $runB = TechnicianRun::findOrFail($runIdB);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $runA->fresh()->state, 'run A must NOT be superseded by the unrelated run B');
        $this->assertSame(TechnicianRunState::AwaitingApproval, $runB->fresh()->state);

        $this->assertSame(2, TechnicianRun::where('ticket_id', $fixture['ticket']->id)
            ->where('action_type', 'tactical_stage_command')
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->count());

        // Approving A must leave B fully intact and still approvable.
        $approveClient = Mockery::mock(TacticalClient::class);
        $approveClient->shouldReceive('cmd')->once()->with('agent-1', 'ipconfig /flushdns', 'cmd', 30)->andReturn('done');
        $this->app->instance(TacticalClient::class, $approveClient);

        app(TechnicianApprovalService::class)->approveStagedTacticalAction($runA, $actor->id);

        $this->assertSame(TechnicianRunState::Done, $runA->fresh()->state);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $runB->fresh()->state, 'approving A must not disturb the unrelated B run');
    }

    public function test_restaging_identical_content_while_awaiting_returns_idempotent_with_real_run_id(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->twoAssetFixture();
        $token = $this->token(['tactical_stage_command']);

        $client = Mockery::mock(TacticalClient::class);
        $client->shouldNotReceive('cmd');
        $this->app->instance(TacticalClient::class, $client);

        $first = $this->callTool($token, 'tactical_stage_command', $this->stageCommandArgs(
            $fixture, 'PC-01', 'ipconfig /flushdns', 'Flush DNS on PC-01.',
        ));
        $runId = $this->decodedResult($first)['run_id'];
        $this->assertNotNull($runId);

        $again = $this->callTool($token, 'tactical_stage_command', $this->stageCommandArgs(
            $fixture, 'PC-01', 'ipconfig /flushdns', 'Chet re-sent the identical staging call.',
        ));
        $again->assertOk();
        $this->assertFalse((bool) $again->json('result.isError'), (string) $again->json('result.content.0.text'));
        $againResult = $this->decodedResult($again);

        $this->assertTrue($againResult['idempotent'] ?? false);
        $this->assertNotNull($againResult['run_id'], 'idempotent:true must never be paired with a null run_id');
        $this->assertSame($runId, $againResult['run_id']);

        $this->assertSame(1, TechnicianRun::where('ticket_id', $fixture['ticket']->id)
            ->where('action_type', 'tactical_stage_command')
            ->count(), 'no duplicate row should be created for an identical, still-live restage');
    }

    public function test_restaging_after_supersede_stages_fresh_not_false_idempotent(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->twoAssetFixture();
        $token = $this->token(['tactical_stage_command']);

        $client = Mockery::mock(TacticalClient::class);
        $client->shouldNotReceive('cmd');
        $this->app->instance(TacticalClient::class, $client);

        $first = $this->callTool($token, 'tactical_stage_command', $this->stageCommandArgs(
            $fixture, 'PC-01', 'ipconfig /flushdns', 'Flush DNS on PC-01.',
        ));
        $firstRunId = $this->decodedResult($first)['run_id'];
        $originalRun = TechnicianRun::findOrFail($firstRunId);

        $originalRun->markSuperseded();
        $this->assertSame(TechnicianRunState::Superseded, $originalRun->fresh()->state);

        $this->travel(301)->seconds();

        $restaged = $this->callTool($token, 'tactical_stage_command', $this->stageCommandArgs(
            $fixture, 'PC-01', 'ipconfig /flushdns', 'Chet needs to restage the lost command.',
        ));
        $restaged->assertOk();
        $this->assertFalse((bool) $restaged->json('result.isError'), (string) $restaged->json('result.content.0.text'));
        $restagedResult = $this->decodedResult($restaged);

        $this->assertFalse($restagedResult['idempotent'] ?? false, 'restaging after the live run is gone must not report a false idempotent hit');
        $this->assertNotNull($restagedResult['run_id']);

        // technician_runs_idempotency (UNIQUE ticket_id+action_type+content_hash) forbids
        // a second row for identical content — the fix revives the SAME row in place.
        $this->assertSame($firstRunId, $restagedResult['run_id'], 'restaging after supersede revives the SAME idempotency-keyed row');
        $this->assertSame(TechnicianRunState::AwaitingApproval, $originalRun->fresh()->state);

        $this->assertSame(1, TechnicianRun::where('ticket_id', $fixture['ticket']->id)
            ->where('action_type', 'tactical_stage_command')
            ->count());
    }

    public function test_executed_dedup_still_short_circuits_off_the_audit_log_with_a_real_run_id(): void
    {
        $this->configureTactical();
        $actor = $this->configureAiActor();
        $fixture = $this->twoAssetFixture();
        $token = $this->token(['tactical_stage_command']);

        $stageClient = Mockery::mock(TacticalClient::class);
        $stageClient->shouldNotReceive('cmd');
        $this->app->instance(TacticalClient::class, $stageClient);

        $first = $this->callTool($token, 'tactical_stage_command', $this->stageCommandArgs(
            $fixture, 'PC-01', 'ipconfig /flushdns', 'Flush DNS on PC-01.',
        ));
        $runId = $this->decodedResult($first)['run_id'];
        $run = TechnicianRun::findOrFail($runId);

        $approveClient = Mockery::mock(TacticalClient::class);
        $approveClient->shouldReceive('cmd')->once()->with('agent-1', 'ipconfig /flushdns', 'cmd', 30)->andReturn('done');
        $this->app->instance(TacticalClient::class, $approveClient);

        app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $actor->id);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);

        $this->travel(301)->seconds();

        $restageClient = Mockery::mock(TacticalClient::class);
        $restageClient->shouldNotReceive('cmd');
        $this->app->instance(TacticalClient::class, $restageClient);

        $again = $this->callTool($token, 'tactical_stage_command', $this->stageCommandArgs(
            $fixture, 'PC-01', 'ipconfig /flushdns', 'Restaging the same command after it already ran.',
        ));
        $again->assertOk();
        $this->assertFalse((bool) $again->json('result.isError'), (string) $again->json('result.content.0.text'));
        $againResult = $this->decodedResult($again);

        $this->assertTrue($againResult['idempotent'] ?? false);
        $this->assertNotNull($againResult['run_id'], 'idempotent:true must never be paired with a null run_id, even for the executed-dedup path');
        $this->assertSame($run->id, $againResult['run_id']);

        $this->assertSame(1, TechnicianRun::where('ticket_id', $fixture['ticket']->id)
            ->where('action_type', 'tactical_stage_command')
            ->count());
    }
}
