<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * bd psa-k4s0 sibling audit: StaffTacticalAdminToolExecutor has TWO staged actions
 * (tactical_stage_run_policy_task_all, tactical_stage_reset_patch_policies) with the
 * same Root B shape as the primary CIPP bug — alreadyAwaitingOrExecuted() consults the
 * immutable TechnicianActionLog instead of the live runs table, so a stale
 * 'awaiting_approval' audit row can produce idempotent:true with run_id:null once the
 * live run is no longer awaiting (e.g. an operator denied it — this class has no
 * post-create supersede at all, so Root A does not apply here; distinct-content staged
 * actions of the same type already coexist today).
 */
class TacticalAdminStagedActionDedupTest extends TestCase
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

    /** @return array{client: Client, ticket: Ticket} */
    private function fixture(): array
    {
        $client = Client::factory()->create(['name' => 'Acme', 'tactical_site_id' => 'Acme|Main']);
        $contact = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Client',
            'last_name' => 'Contact',
            'email' => 'client@example.test',
            'is_active' => true,
        ]);
        $ticket = Ticket::factory()->for($client)->create(['contact_id' => $contact->id, 'subject' => 'Fleet-wide maintenance']);

        return compact('client', 'ticket');
    }

    /** @return array<int, array<string, mixed>> */
    private function policies(): array
    {
        return [[
            'id' => 7,
            'name' => 'Workstations',
            'desc' => 'Default workstations',
            'active' => true,
            'enforced' => false,
        ]];
    }

    /** @return array<int, array<string, mixed>> */
    private function policyTasks(): array
    {
        return [
            ['id' => 1, 'name' => 'Quick Scan'],
            ['id' => 2, 'name' => 'Full Scan'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function tacticalClients(): array
    {
        return [[
            'id' => 55,
            'name' => 'Acme',
            'sites' => [['id' => 1, 'name' => 'Main']],
        ]];
    }

    // ---- tactical_stage_run_policy_task_all -------------------------------------

    /** @return array<string, mixed> */
    private function stagePolicyTaskRunAllArgs(array $fixture, string $reason): array
    {
        return [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'policy_id' => 7,
            'task_id' => 1,
            'confirm_policy_name' => 'Workstations',
            'confirm_task_name' => 'Quick Scan',
            'confirm_run_all' => 'run policy task for all affected agents',
            'reason' => $reason,
        ];
    }

    public function test_policy_task_run_all_restaging_identical_content_while_awaiting_returns_idempotent_with_real_run_id(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $token = $this->token(['tactical_stage_run_policy_task_all']);

        $client = Mockery::mock(TacticalClient::class);
        $client->shouldReceive('getPolicies')->andReturn($this->policies());
        $client->shouldReceive('getPolicyTasks')->with(7)->andReturn($this->policyTasks());
        $this->app->instance(TacticalClient::class, $client);

        $first = $this->callTool($token, 'tactical_stage_run_policy_task_all', $this->stagePolicyTaskRunAllArgs(
            $fixture, 'Run a quick scan fleet-wide.',
        ));
        $first->assertOk();
        $this->assertFalse((bool) $first->json('result.isError'), (string) $first->json('result.content.0.text'));
        $runId = $this->decodedResult($first)['run_id'];
        $this->assertNotNull($runId);

        $again = $this->callTool($token, 'tactical_stage_run_policy_task_all', $this->stagePolicyTaskRunAllArgs(
            $fixture, 'Chet re-sent the identical staging call.',
        ));
        $again->assertOk();
        $this->assertFalse((bool) $again->json('result.isError'), (string) $again->json('result.content.0.text'));
        $againResult = $this->decodedResult($again);

        $this->assertTrue($againResult['idempotent'] ?? false);
        $this->assertNotNull($againResult['run_id'], 'idempotent:true must never be paired with a null run_id');
        $this->assertSame($runId, $againResult['run_id']);

        $this->assertSame(1, TechnicianRun::where('ticket_id', $fixture['ticket']->id)
            ->where('action_type', 'tactical_stage_run_policy_task_all')
            ->count());
    }

    public function test_policy_task_run_all_restaging_after_denial_stages_fresh_not_false_idempotent(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $token = $this->token(['tactical_stage_run_policy_task_all']);

        $client = Mockery::mock(TacticalClient::class);
        $client->shouldReceive('getPolicies')->andReturn($this->policies());
        $client->shouldReceive('getPolicyTasks')->with(7)->andReturn($this->policyTasks());
        $this->app->instance(TacticalClient::class, $client);

        $first = $this->callTool($token, 'tactical_stage_run_policy_task_all', $this->stagePolicyTaskRunAllArgs(
            $fixture, 'Run a quick scan fleet-wide.',
        ));
        $firstRunId = $this->decodedResult($first)['run_id'];
        $originalRun = TechnicianRun::findOrFail($firstRunId);

        // This class has no post-create supersede at all — the realistic route to "no
        // longer live but the audit trail is still within the dedup window" here is an
        // operator denying the proposal from the cockpit.
        $this->assertTrue($originalRun->deny());
        $this->assertSame(TechnicianRunState::Denied, $originalRun->fresh()->state);

        // Past this tool's cooldown (900s per COOLDOWNS) so the restage below succeeds on
        // the FIX being tested (live-run liveness), not merely on timing.
        $this->travel(901)->seconds();

        $restaged = $this->callTool($token, 'tactical_stage_run_policy_task_all', $this->stagePolicyTaskRunAllArgs(
            $fixture, 'Chet needs to restage the denied proposal.',
        ));
        $restaged->assertOk();
        $this->assertFalse((bool) $restaged->json('result.isError'), (string) $restaged->json('result.content.0.text'));
        $restagedResult = $this->decodedResult($restaged);

        $this->assertFalse($restagedResult['idempotent'] ?? false, 'restaging after the live run is gone must not report a false idempotent hit');
        $this->assertNotNull($restagedResult['run_id']);
        $this->assertSame($firstRunId, $restagedResult['run_id'], 'restaging after denial revives the SAME idempotency-keyed row');
        $this->assertSame(TechnicianRunState::AwaitingApproval, $originalRun->fresh()->state);

        $this->assertSame(1, TechnicianRun::where('ticket_id', $fixture['ticket']->id)
            ->where('action_type', 'tactical_stage_run_policy_task_all')
            ->count());
    }

    public function test_policy_task_run_all_executed_dedup_still_short_circuits_with_a_real_run_id(): void
    {
        $this->configureTactical();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $token = $this->token(['tactical_stage_run_policy_task_all']);

        $client = Mockery::mock(TacticalClient::class);
        $client->shouldReceive('getPolicies')->andReturn($this->policies());
        $client->shouldReceive('getPolicyTasks')->with(7)->andReturn($this->policyTasks());
        $client->shouldReceive('runPolicyTask')->once()->with(1)->andReturn(['success' => true]);
        $this->app->instance(TacticalClient::class, $client);

        $first = $this->callTool($token, 'tactical_stage_run_policy_task_all', $this->stagePolicyTaskRunAllArgs(
            $fixture, 'Run a quick scan fleet-wide.',
        ));
        $runId = $this->decodedResult($first)['run_id'];
        $run = TechnicianRun::findOrFail($runId);

        $result = app(\App\Services\Technician\TechnicianApprovalService::class)->approveStagedTacticalAdminAction($run, $actor->id);
        $this->assertSame('executed', $result->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);

        $this->travel(901)->seconds();

        $again = $this->callTool($token, 'tactical_stage_run_policy_task_all', $this->stagePolicyTaskRunAllArgs(
            $fixture, 'Restaging the same policy task run after it already ran.',
        ));
        $again->assertOk();
        $this->assertFalse((bool) $again->json('result.isError'), (string) $again->json('result.content.0.text'));
        $againResult = $this->decodedResult($again);

        $this->assertTrue($againResult['idempotent'] ?? false);
        $this->assertNotNull($againResult['run_id'], 'idempotent:true must never be paired with a null run_id, even for the executed-dedup path');
        $this->assertSame($run->id, $againResult['run_id']);

        $this->assertSame(1, TechnicianRun::where('ticket_id', $fixture['ticket']->id)
            ->where('action_type', 'tactical_stage_run_policy_task_all')
            ->count());
    }

    // ---- tactical_stage_reset_patch_policies -------------------------------------

    /** @return array<string, mixed> */
    private function stagePatchResetArgs(array $fixture, string $reason): array
    {
        return [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'reason' => $reason,
        ];
    }

    public function test_patch_reset_restaging_identical_content_while_awaiting_returns_idempotent_with_real_run_id(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $token = $this->token(['tactical_stage_reset_patch_policies']);

        $client = Mockery::mock(TacticalClient::class);
        $client->shouldReceive('getClients')->andReturn($this->tacticalClients());
        $this->app->instance(TacticalClient::class, $client);

        $first = $this->callTool($token, 'tactical_stage_reset_patch_policies', $this->stagePatchResetArgs(
            $fixture, 'Reset patch policies for the site.',
        ));
        $first->assertOk();
        $this->assertFalse((bool) $first->json('result.isError'), (string) $first->json('result.content.0.text'));
        $runId = $this->decodedResult($first)['run_id'];
        $this->assertNotNull($runId);

        $again = $this->callTool($token, 'tactical_stage_reset_patch_policies', $this->stagePatchResetArgs(
            $fixture, 'Chet re-sent the identical staging call.',
        ));
        $again->assertOk();
        $this->assertFalse((bool) $again->json('result.isError'), (string) $again->json('result.content.0.text'));
        $againResult = $this->decodedResult($again);

        $this->assertTrue($againResult['idempotent'] ?? false);
        $this->assertNotNull($againResult['run_id'], 'idempotent:true must never be paired with a null run_id');
        $this->assertSame($runId, $againResult['run_id']);

        $this->assertSame(1, TechnicianRun::where('ticket_id', $fixture['ticket']->id)
            ->where('action_type', 'tactical_stage_reset_patch_policies')
            ->count());
    }

    public function test_patch_reset_restaging_after_denial_stages_fresh_not_false_idempotent(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $token = $this->token(['tactical_stage_reset_patch_policies']);

        $client = Mockery::mock(TacticalClient::class);
        $client->shouldReceive('getClients')->andReturn($this->tacticalClients());
        $this->app->instance(TacticalClient::class, $client);

        $first = $this->callTool($token, 'tactical_stage_reset_patch_policies', $this->stagePatchResetArgs(
            $fixture, 'Reset patch policies for the site.',
        ));
        $firstRunId = $this->decodedResult($first)['run_id'];
        $originalRun = TechnicianRun::findOrFail($firstRunId);

        $this->assertTrue($originalRun->deny());
        $this->assertSame(TechnicianRunState::Denied, $originalRun->fresh()->state);

        // Past this tool's cooldown (900s per COOLDOWNS).
        $this->travel(901)->seconds();

        $restaged = $this->callTool($token, 'tactical_stage_reset_patch_policies', $this->stagePatchResetArgs(
            $fixture, 'Chet needs to restage the denied reset.',
        ));
        $restaged->assertOk();
        $this->assertFalse((bool) $restaged->json('result.isError'), (string) $restaged->json('result.content.0.text'));
        $restagedResult = $this->decodedResult($restaged);

        $this->assertFalse($restagedResult['idempotent'] ?? false, 'restaging after the live run is gone must not report a false idempotent hit');
        $this->assertNotNull($restagedResult['run_id']);
        $this->assertSame($firstRunId, $restagedResult['run_id'], 'restaging after denial revives the SAME idempotency-keyed row');
        $this->assertSame(TechnicianRunState::AwaitingApproval, $originalRun->fresh()->state);

        $this->assertSame(1, TechnicianRun::where('ticket_id', $fixture['ticket']->id)
            ->where('action_type', 'tactical_stage_reset_patch_policies')
            ->count());
    }

    public function test_patch_reset_executed_dedup_still_short_circuits_with_a_real_run_id(): void
    {
        $this->configureTactical();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $token = $this->token(['tactical_stage_reset_patch_policies']);

        $client = Mockery::mock(TacticalClient::class);
        $client->shouldReceive('getClients')->andReturn($this->tacticalClients());
        $client->shouldReceive('resetPatchPolicies')->once()->andReturn(['success' => true]);
        $this->app->instance(TacticalClient::class, $client);

        $first = $this->callTool($token, 'tactical_stage_reset_patch_policies', $this->stagePatchResetArgs(
            $fixture, 'Reset patch policies for the site.',
        ));
        $runId = $this->decodedResult($first)['run_id'];
        $run = TechnicianRun::findOrFail($runId);

        $result = app(\App\Services\Technician\TechnicianApprovalService::class)->approveStagedTacticalAdminAction($run, $actor->id);
        $this->assertSame('executed', $result->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);

        $this->travel(901)->seconds();

        $again = $this->callTool($token, 'tactical_stage_reset_patch_policies', $this->stagePatchResetArgs(
            $fixture, 'Restaging the same reset after it already ran.',
        ));
        $again->assertOk();
        $this->assertFalse((bool) $again->json('result.isError'), (string) $again->json('result.content.0.text'));
        $againResult = $this->decodedResult($again);

        $this->assertTrue($againResult['idempotent'] ?? false);
        $this->assertNotNull($againResult['run_id'], 'idempotent:true must never be paired with a null run_id, even for the executed-dedup path');
        $this->assertSame($run->id, $againResult['run_id']);

        $this->assertSame(1, TechnicianRun::where('ticket_id', $fixture['ticket']->id)
            ->where('action_type', 'tactical_stage_reset_patch_policies')
            ->count());
    }
}
