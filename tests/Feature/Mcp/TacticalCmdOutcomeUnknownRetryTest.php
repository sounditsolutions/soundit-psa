<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TacticalActionLog;
use App\Models\TacticalAsset;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use App\Services\Technician\TechnicianApprovalService;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * #3971: an outcome_unknown command may have run, so neither the identical-
 * content dedup nor the (zeroed) cooldown may let an immediate identical retry
 * through as if nothing happened. Each guard is paired with a control proving
 * the same retry DOES go through after a genuine offline — so a guard that
 * blocked every retry would fail here too.
 */
class TacticalCmdOutcomeUnknownRetryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{client: Client, asset: Asset, ticket: Ticket, actor: User} */
    private function fixture(): array
    {
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'secret');
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        $client = Client::factory()->create(['name' => 'Acme']);
        $contact = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Client',
            'last_name' => 'Contact',
            'email' => 'client@example.test',
            'is_active' => true,
        ]);
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'PC-01', 'name' => 'PC-01']);
        TacticalAsset::create(['asset_id' => $asset->id, 'agent_id' => 'agent-1', 'hostname' => 'PC-01', 'status' => 'online', 'synced_at' => now()]);
        $ticket = Ticket::factory()->for($client)->create(['contact_id' => $contact->id, 'subject' => 'Workstation issue']);
        $ticket->assets()->attach($asset->id, ['is_primary' => true]);

        return compact('client', 'asset', 'ticket', 'actor');
    }

    private function callTool(string $token, string $name, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    private function text(TestResponse $response): string
    {
        return (string) $response->json('result.content.0.text');
    }

    /** @return array<string, mixed> */
    private function runArgs(Client $client, string $cmd = 'Long-Job'): array
    {
        return [
            'client_id' => $client->id,
            'hostname' => 'PC-01',
            'confirm_hostname' => 'PC-01',
            'shell' => 'powershell',
            'cmd' => $cmd,
            'timeout' => 25,
            'reason' => 'Run the long job.',
        ];
    }

    private function sentThenTimedOut(): TacticalClientException
    {
        return new TacticalClientException('Tactical API error (transport failure)', transportFailure: true, timedOutAfterSend: true);
    }

    private function neverConnected(): TacticalClientException
    {
        return new TacticalClientException('Tactical API error (transport failure)', transportFailure: true);
    }

    // ── direct dispatch ─────────────────────────────────────────────────

    public function test_an_identical_command_is_not_resent_after_an_outcome_unknown(): void
    {
        $f = $this->fixture();
        $token = McpConfig::rotateStaffToken(allowedTools: ['tactical_run_command'], label: 'opsbot');

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('cmd')->once()->with('agent-1', 'Long-Job', 'powershell', 25)->andThrow($this->sentThenTimedOut());
        $tactical->shouldReceive('cmd')->once()->with('agent-1', 'hostname', 'powershell', 25)->andReturn('PC-01');
        $this->app->instance(TacticalClient::class, $tactical);

        $first = $this->callTool($token, 'tactical_run_command', $this->runArgs($f['client']));
        $this->assertTrue((bool) $first->json('result.isError'));
        $this->assertStringContainsString('may have run', $this->text($first));
        $this->assertStringNotContainsString('offline', $this->text($first));
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'tactical_run_command')->where('result_status', 'outcome_unknown')->count());

        // The immediate identical retry: no second cmd() call (Mockery ->once()).
        $retry = $this->callTool($token, 'tactical_run_command', $this->runArgs($f['client']));
        $this->assertTrue((bool) $retry->json('result.isError'));
        $this->assertStringContainsString('outcome is unknown; it may have run', $this->text($retry));
        $this->assertStringContainsString('No tactical_run_command was sent', $this->text($retry));
        $this->assertSame('blocked', json_decode($this->text($retry), true)['tactical_status'] ?? null, 'the refusal itself sent nothing');
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'tactical_run_command')->where('result_status', 'blocked')->count());

        // Only IDENTICAL content is held: a different command still goes out.
        $other = $this->callTool($token, 'tactical_run_command', $this->runArgs($f['client'], 'hostname'));
        $this->assertFalse((bool) $other->json('result.isError'), $this->text($other));
    }

    public function test_control_an_identical_command_is_resent_after_a_genuine_offline(): void
    {
        $f = $this->fixture();
        $token = McpConfig::rotateStaffToken(allowedTools: ['tactical_run_command'], label: 'opsbot');

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('cmd')->once()->ordered()->andThrow($this->neverConnected());
        $tactical->shouldReceive('cmd')->once()->ordered()->andReturn('done');
        $this->app->instance(TacticalClient::class, $tactical);

        $first = $this->callTool($token, 'tactical_run_command', $this->runArgs($f['client']));
        $this->assertStringContainsString('offline', $this->text($first));

        // Nothing was sent, so the retry is let through (both cmd() calls happen).
        $retry = $this->callTool($token, 'tactical_run_command', $this->runArgs($f['client']));
        $this->assertFalse((bool) $retry->json('result.isError'), $this->text($retry));
    }

    // ── staged approval ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function stageArgs(array $f): array
    {
        return [
            'client_id' => $f['client']->id,
            'ticket_id' => $f['ticket']->id,
            'hostname' => 'PC-01',
            'shell' => 'powershell',
            'cmd' => 'Long-Job',
            'timeout' => 25,
            'reason' => 'Run the long job.',
        ];
    }

    private function stageAndApprove(array $f, string $token, TacticalClientException $failure): array
    {
        $staged = $this->callTool($token, 'tactical_stage_command', $this->stageArgs($f));
        $this->assertFalse((bool) $staged->json('result.isError'), $this->text($staged));
        $run = TechnicianRun::findOrFail(json_decode($this->text($staged), true)['run_id']);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('cmd')->once()->andThrow($failure);
        $this->app->instance(TacticalClient::class, $tactical);

        $approval = app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $f['actor']->id);

        return [$run, $approval];
    }

    public function test_an_approved_command_that_times_out_after_send_is_held_not_reopened(): void
    {
        $f = $this->fixture();
        $token = McpConfig::rotateStaffToken(allowedTools: ['tactical_stage_command'], label: 'opsbot');

        [$run, $approval] = $this->stageAndApprove($f, $token, $this->sentThenTimedOut());

        $this->assertSame('gate_declined', $approval->status);
        $this->assertStringContainsString('may have run', (string) $approval->message);
        $this->assertStringContainsString('closed, not reopened', (string) $approval->message);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state, 'landed terminal: neither released for one-tap re-approval nor stranded in Executing');
        $this->assertSame('outcome_unknown', TechnicianActionLog::where('run_id', $run->id)->latest('id')->value('result_status'));

        // Staging the identical command again is refused; nothing new is staged.
        $again = $this->callTool($token, 'tactical_stage_command', $this->stageArgs($f));
        $this->assertTrue((bool) $again->json('result.isError'));
        $this->assertStringContainsString('outcome is unknown; it may have run', $this->text($again));
        $this->assertSame(1, TechnicianRun::where('action_type', 'tactical_stage_command')->count());
    }

    public function test_control_an_approved_command_that_never_connected_is_reopened(): void
    {
        $f = $this->fixture();
        $token = McpConfig::rotateStaffToken(allowedTools: ['tactical_stage_command'], label: 'opsbot');

        [$run, $approval] = $this->stageAndApprove($f, $token, $this->neverConnected());

        $this->assertSame('gate_declined', $approval->status);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state, 'offline: nothing ran, so it stays approvable');
    }

    /**
     * #6197: another approval claims the run while cmd() is in flight (the run was reopened
     * and claimed again), so this request's advanceTo(Done) loses the claim-owner fence.
     * $answer is cmd()'s outcome once the other claim is in place.
     *
     * @return array{0: TechnicianRun, 1: \App\Services\Technician\TechnicianApprovalResult}
     */
    private function stageAndApproveLosingTheClaim(array $f, string $token, \Closure $answer): array
    {
        $staged = $this->callTool($token, 'tactical_stage_command', $this->stageArgs($f));
        $this->assertFalse((bool) $staged->json('result.isError'), $this->text($staged));
        $run = TechnicianRun::findOrFail(json_decode($this->text($staged), true)['run_id']);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('cmd')->once()->andReturnUsing(function () use ($run, $answer) {
            TechnicianRun::whereKey($run->id)->update(['claimed_at' => now()->addMinute()]);

            return $answer();
        });
        $this->app->instance(TacticalClient::class, $tactical);

        return [$run, app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $f['actor']->id)];
    }

    /** #6197: an outcome-unknown whose close loses the fence does not say the run is closed. */
    public function test_an_outcome_unknown_whose_close_loses_the_claim_does_not_say_closed(): void
    {
        $f = $this->fixture();
        $token = McpConfig::rotateStaffToken(allowedTools: ['tactical_stage_command'], label: 'opsbot');
        $failure = $this->sentThenTimedOut();

        [$run, $approval] = $this->stageAndApproveLosingTheClaim($f, $token, fn () => throw $failure);

        $this->assertSame('gate_declined', $approval->status);
        $this->assertSame('Tactical did not answer before the timeout after the approved action was sent; it may have run. The run was not closed: this approval no longer holds it, and it was not reopened for re-approval. Check the device to find out whether it ran, and check the run before acting on it again.', $approval->message);
        $this->assertSame(TechnicianRunState::Executing, $run->fresh()->state, 'the other claim is untouched');
        $this->assertSame('outcome_unknown', TechnicianActionLog::where('run_id', $run->id)->latest('id')->value('result_status'), 'positive control: the outcome-unknown arm ran');
    }

    /** #6197: an executed command whose close loses the fence is reported executed_with_fault, not executed. */
    public function test_an_executed_command_whose_close_loses_the_claim_is_executed_with_fault(): void
    {
        $f = $this->fixture();
        $token = McpConfig::rotateStaffToken(allowedTools: ['tactical_stage_command'], label: 'opsbot');

        [$run, $approval] = $this->stageAndApproveLosingTheClaim($f, $token, fn () => 'done');

        $this->assertSame('executed_with_fault', $approval->status);
        $this->assertSame('The approved Tactical action executed, but the run was not closed because this approval no longer holds it. Do NOT re-approve it; check the device and the run.', $approval->message);
        $this->assertNull($approval->secret);
        $this->assertSame(TechnicianRunState::Executing, $run->fresh()->state, 'the other claim is untouched');
        $this->assertSame(1, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'executed')->count(), 'positive control: the action executed');
    }

    public function test_a_command_staged_under_the_old_600s_maximum_is_refused_at_approval_with_a_named_reason(): void
    {
        $f = $this->fixture();
        $token = McpConfig::rotateStaffToken(allowedTools: ['tactical_stage_command'], label: 'opsbot');

        $staged = $this->callTool($token, 'tactical_stage_command', $this->stageArgs($f));
        $this->assertFalse((bool) $staged->json('result.isError'), $this->text($staged));
        $run = TechnicianRun::findOrFail(json_decode($this->text($staged), true)['run_id']);

        // A proposal stored before the maximum was lowered: the old 10..600 schema accepted 120.
        $meta = $run->proposed_meta;
        $meta['encrypted_payload'] = Crypt::encryptString(json_encode([
            'direct_tool' => 'tactical_run_command',
            'asset_id' => $f['asset']->id,
            'ticket_id' => $f['ticket']->id,
            'client_id' => $f['client']->id,
            'params' => ['cmd' => 'Long-Job', 'shell' => 'powershell', 'timeout' => 120],
        ], JSON_THROW_ON_ERROR));
        $run->forceFill(['proposed_meta' => $meta])->save();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldNotReceive('cmd');
        $this->app->instance(TacticalClient::class, $tactical);

        $approval = app(TechnicianApprovalService::class)->approveStagedTacticalAction($run->fresh(), $f['actor']->id);

        $this->assertSame('gate_declined', $approval->status);
        $this->assertStringContainsString('lowered from 600 to 30 seconds', (string) $approval->message);
        $this->assertStringContainsString('deny this proposal and stage it again', (string) $approval->message);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state, 'nothing was sent; the approver can deny it');
        $this->assertStringContainsString(
            'lowered from 600 to 30 seconds',
            (string) TacticalActionLog::where('result_status', 'rejected')->sole()->message,
            'the bus audit row names the change',
        );
    }

    public function test_the_same_command_is_held_across_lanes_tickets_and_timeouts_after_an_outcome_unknown(): void
    {
        $f = $this->fixture();
        $token = McpConfig::rotateStaffToken(allowedTools: ['tactical_run_command', 'tactical_stage_command'], label: 'opsbot');

        // One cmd() only (Mockery ->once()): every retry below must be held before the transport.
        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('cmd')->once()->andThrow($this->sentThenTimedOut());
        $this->app->instance(TacticalClient::class, $tactical);

        $first = $this->callTool($token, 'tactical_run_command', $this->runArgs($f['client']));
        $this->assertStringContainsString('may have run', $this->text($first));

        // Direct again, now on a ticket and with another timeout: a different content hash, so the
        // executor's identical-content hold does not match it; the bus hold does.
        $retry = $this->callTool($token, 'tactical_run_command', ['ticket_id' => $f['ticket']->id, 'timeout' => 26] + $this->runArgs($f['client']));
        $this->assertTrue((bool) $retry->json('result.isError'));
        $this->assertStringContainsString('It was not sent again', $this->text($retry));

        // The other lane: staged, approved, declined at the bus with nothing sent, and left approvable.
        $staged = $this->callTool($token, 'tactical_stage_command', $this->stageArgs($f));
        $this->assertFalse((bool) $staged->json('result.isError'), $this->text($staged));
        $run = TechnicianRun::findOrFail(json_decode($this->text($staged), true)['run_id']);

        $approval = app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $f['actor']->id);

        $this->assertSame('gate_declined', $approval->status);
        $this->assertStringContainsString('Nothing was sent', (string) $approval->message);
        $this->assertStringContainsString('may have run', (string) $approval->message, 'the approver is told why, at the point of decision');
        $this->assertStringContainsString('It was not sent again', (string) $approval->message);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state, 'nothing was sent, so it stays approvable');
        $this->assertSame(1, TacticalActionLog::where('result_status', 'outcome_unknown')->count());
        $this->assertSame(2, TacticalActionLog::where('result_status', 'blocked')->count());
    }
}
