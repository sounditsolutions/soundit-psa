<?php

namespace Tests\Feature\Tactical;

use App\Enums\TechnicianRunState;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\TacticalScript;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tactical\OfflineActionSweep;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Tests\TestCase;

/**
 * Reconnect-run + expiry sweep for the offline-script queue (bd psa-xr84). A queued
 * action runs through the SAME gate-checked path as a live approval when its device
 * returns online; it never auto-runs past its safety window.
 */
class OfflineActionSweepTest extends TestCase
{
    use RefreshDatabase;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'secret');
        $this->approver = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $this->approver->id);
        TacticalScript::firstOrCreate(['tactical_script_id' => 201], [
            'name' => 'Disk Health', 'shell' => 'powershell', 'hidden' => false, 'synced_at' => now(),
        ]);
    }

    private function queuedRun(string $agentId = 'agent-1', string $status = 'online', ?CarbonInterface $expiresAt = null): TechnicianRun
    {
        $client = Client::factory()->create();
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'PC-01', 'name' => 'PC-01']);
        TacticalAsset::create([
            'asset_id' => $asset->id, 'agent_id' => $agentId, 'hostname' => 'PC-01',
            'status' => $status, 'synced_at' => now(),
        ]);
        $ticket = Ticket::factory()->for($client)->create();
        $ticket->assets()->attach($asset->id, ['is_primary' => true]);

        $payload = [
            'direct_tool' => 'tactical_run_script',
            'asset_id' => $asset->id,
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            // args stored as the raw string exactly as staging persists it; the action
            // argv-tokenizes it at dispatch time.
            'params' => ['tactical_script_id' => 201, 'args' => '-Check Disk', 'timeout' => 120],
        ];

        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => 'tactical_stage_script',
            'content_hash' => hash('sha256', 'run-'.$ticket->id),
            'state' => TechnicianRunState::QueuedOffline,
            'proposed_meta' => [
                'encrypted_payload' => Crypt::encryptString((string) json_encode($payload)),
                'queued_approver_id' => $this->approver->id,
            ],
            'queued_agent_id' => $agentId,
            'queued_dedup_key' => hash('sha256', 'dedup-'.$ticket->id),
            'queued_at' => now()->subMinutes(30),
            'expires_at' => $expiresAt ?? now()->addDays(7),
        ]);
    }

    private function fakeClient(callable $expect): void
    {
        $mock = Mockery::mock(TacticalClient::class);
        $expect($mock);
        $this->app->instance(TacticalClient::class, $mock);
    }

    public function test_sweep_runs_a_queued_action_when_the_agent_is_online(): void
    {
        $run = $this->queuedRun('agent-1', 'online');
        $this->fakeClient(fn ($m) => $m->shouldReceive('runScript')->once()
            ->with('agent-1', 201, ['-Check', 'Disk'], 120)->andReturn(['stdout' => 'Healthy', 'retcode' => 0]));

        $ran = app(OfflineActionSweep::class)->sweepAgent('agent-1');

        $this->assertSame(1, $ran);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.run_script', 'agent_id' => 'agent-1', 'result_status' => 'ok',
        ]);
    }

    public function test_still_offline_reconnect_requeues_preserving_the_expiry(): void
    {
        $run = $this->queuedRun('agent-1', 'online');
        $originalExpiry = $run->expires_at->timestamp;
        $this->fakeClient(fn ($m) => $m->shouldReceive('runScript')->once()
            ->andThrow(new TacticalClientException('offline', transportFailure: true)));

        $ran = app(OfflineActionSweep::class)->sweepAgent('agent-1');

        $this->assertSame(0, $ran);
        $fresh = $run->fresh();
        $this->assertSame(TechnicianRunState::QueuedOffline, $fresh->state);
        $this->assertSame($originalExpiry, $fresh->expires_at->timestamp, 'expiry window must not reset on a failed reconnect');
    }

    public function test_expire_due_marks_past_window_runs_without_running_them(): void
    {
        $run = $this->queuedRun('agent-1', 'online', now()->subDay());
        $this->fakeClient(fn ($m) => $m->shouldNotReceive('runScript'));

        // sweepAgent skips a run past its window (never auto-runs stale)...
        $this->assertSame(0, app(OfflineActionSweep::class)->sweepAgent('agent-1'));
        $this->assertSame(TechnicianRunState::QueuedOffline, $run->fresh()->state);

        // ...expireDue terminates it as expired for cockpit re-confirm.
        $this->assertSame(1, app(OfflineActionSweep::class)->expireDue());
        $this->assertSame(TechnicianRunState::Expired, $run->fresh()->state);
    }

    public function test_sweep_due_runs_online_agents_and_leaves_offline_ones_queued(): void
    {
        $online = $this->queuedRun('agent-online', 'online');
        $stillOffline = $this->queuedRun('agent-offline', 'offline');
        $this->fakeClient(fn ($m) => $m->shouldReceive('runScript')->once()->andReturn(['stdout' => 'ok', 'retcode' => 0]));

        $summary = app(OfflineActionSweep::class)->sweepDue();

        $this->assertSame(1, $summary['ran']);
        $this->assertSame(TechnicianRunState::Done, $online->fresh()->state);
        $this->assertSame(TechnicianRunState::QueuedOffline, $stillOffline->fresh()->state);
    }

    public function test_sweep_is_a_no_op_when_the_feature_is_disabled(): void
    {
        Setting::setValue('tactical_offline_queue_enabled', '0');
        $run = $this->queuedRun('agent-1', 'online');
        $this->fakeClient(fn ($m) => $m->shouldNotReceive('runScript'));

        $this->assertSame(0, app(OfflineActionSweep::class)->sweepAgent('agent-1'));
        $this->assertSame(TechnicianRunState::QueuedOffline, $run->fresh()->state);
    }
}
