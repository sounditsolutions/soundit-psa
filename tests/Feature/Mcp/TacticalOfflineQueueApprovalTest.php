<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\TacticalScript;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use App\Services\Technician\TechnicianApprovalService;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * Offline-script queue (bd psa-xr84) — approving a staged script whose device is
 * offline parks it in queued_offline instead of the silent gate_declined dead-end,
 * and a duplicate approval coalesces onto the existing queued row.
 */
class TacticalOfflineQueueApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function configure(): User
    {
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'secret');
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        return $actor;
    }

    private function token(array $tools): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: 'opsbot');
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

    /** @return array{client: Client, asset: Asset, ticket: Ticket} */
    private function endpointFixture(string $hostname = 'PC-01'): array
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
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => $hostname, 'name' => $hostname]);
        TacticalAsset::create([
            'asset_id' => $asset->id, 'agent_id' => 'agent-1', 'hostname' => $hostname,
            'status' => 'offline', 'synced_at' => now(),
        ]);
        $ticket = Ticket::factory()->for($client)->create(['contact_id' => $contact->id, 'subject' => 'Endpoint issue']);
        $ticket->assets()->attach($asset->id, ['is_primary' => true]);

        return compact('client', 'asset', 'ticket');
    }

    private function stageScript(Client $client, Ticket $ticket, TacticalScript $script, string $args = '-Check Disk'): TechnicianRun
    {
        $token = $this->token(['tactical_stage_script']);
        $this->callTool($token, 'tactical_stage_script', [
            'client_id' => $client->id,
            'ticket_id' => $ticket->id,
            'hostname' => 'PC-01',
            'script_id' => $script->id,
            'args' => $args,
            'timeout' => 120,
            'reason' => 'Run a scripted health check when the device is up.',
        ])->assertOk();

        return TechnicianRun::where('action_type', 'tactical_stage_script')
            ->where('ticket_id', $ticket->id)->firstOrFail();
    }

    private function offlineClient(): TacticalClient
    {
        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('runScript')
            ->andThrow(new TacticalClientException('Tactical agent is unreachable', transportFailure: true));
        $this->app->instance(TacticalClient::class, $tactical);

        return $tactical;
    }

    private function script(): TacticalScript
    {
        return TacticalScript::create([
            'tactical_script_id' => 201, 'name' => 'Disk Health', 'shell' => 'powershell',
            'hidden' => false, 'synced_at' => now(),
        ]);
    }

    public function test_approving_a_script_for_an_offline_device_queues_instead_of_dead_ending(): void
    {
        $approver = $this->configure();
        $fixture = $this->endpointFixture();
        $script = $this->script();
        $run = $this->stageScript($fixture['client'], $fixture['ticket'], $script);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        $this->offlineClient();

        $result = app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id);

        $this->assertSame('queued_offline', $result->status);

        $fresh = $run->fresh();
        $this->assertSame(TechnicianRunState::QueuedOffline, $fresh->state);
        $this->assertSame('agent-1', $fresh->queued_agent_id);
        $this->assertNotNull($fresh->queued_at);
        $this->assertNotNull($fresh->expires_at);
        $this->assertNotNull($fresh->queued_dedup_key);

        // Raw offline recorded in the bus ledger; the approval ledger records the queue.
        $this->assertDatabaseHas('tactical_action_logs', ['action_key' => 'tactical.run_script', 'result_status' => 'offline']);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_stage_script',
            'result_status' => 'queued_offline',
            'approver_user_id' => $approver->id,
        ]);
    }

    public function test_cockpit_approve_route_shows_a_queued_message_for_an_offline_device(): void
    {
        $approver = $this->configure();
        $fixture = $this->endpointFixture();
        $run = $this->stageScript($fixture['client'], $fixture['ticket'], $this->script());
        $this->offlineClient();

        $this->actingAs($approver)
            ->post(route('cockpit.approve', $run))
            ->assertRedirect()
            ->assertSessionHas('success', 'Device offline — queued to run automatically when it comes back online.');

        $this->assertSame(TechnicianRunState::QueuedOffline, $run->fresh()->state);
    }

    public function test_disabled_feature_falls_back_to_the_dead_end(): void
    {
        $approver = $this->configure();
        Setting::setValue('tactical_offline_queue_enabled', '0');
        $fixture = $this->endpointFixture();
        $run = $this->stageScript($fixture['client'], $fixture['ticket'], $this->script());
        $this->offlineClient();

        $result = app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    public function test_duplicate_offline_approval_coalesces_onto_the_existing_queued_row(): void
    {
        $approver = $this->configure();
        $script = $this->script();
        $fixture = $this->endpointFixture();

        // Second ticket on the SAME device → same (agent, script, args) dedup key,
        // distinct content_hash (ticket-scoped), so two staged runs exist.
        $ticket2 = Ticket::factory()->for($fixture['client'])->create(['subject' => 'Same device, another ticket']);
        $ticket2->assets()->attach($fixture['asset']->id, ['is_primary' => true]);

        $runA = $this->stageScript($fixture['client'], $fixture['ticket'], $script);
        $runB = $this->stageScript($fixture['client'], $ticket2, $script);

        $this->offlineClient();
        $svc = app(TechnicianApprovalService::class);

        $svc->approveStagedTacticalAction($runA, $approver->id);
        // Past the per-asset run cooldown, so the duplicate reaches the queue (rather
        // than being cooldown-blocked) and coalesces onto the still-queued row.
        $this->travel(3)->minutes();
        $svc->approveStagedTacticalAction($runB, $approver->id);

        // First stays queued with a bumped coalesce count; the duplicate is superseded (not a second queued row).
        $this->assertSame(TechnicianRunState::QueuedOffline, $runA->fresh()->state);
        $this->assertSame(1, $runA->fresh()->coalesce_count);
        $this->assertSame(TechnicianRunState::Superseded, $runB->fresh()->state);
        $this->assertSame(1, TechnicianRun::where('state', TechnicianRunState::QueuedOffline->value)->count());
    }
}
