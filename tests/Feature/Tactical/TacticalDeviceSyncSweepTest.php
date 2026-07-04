<?php

namespace Tests\Feature\Tactical;

use App\Enums\TechnicianRunState;
use App\Jobs\SweepQueuedActionsForAgent;
use App\Models\Asset;
use App\Models\TacticalAsset;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalDeviceSyncService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Device-sync offline→online hook (bd psa-xr84): when a synced agent flips from
 * offline to online AND has queued actions waiting, dispatch a sweep to run them.
 * This is the authoritative trigger — see G4: Tactical availability alerts are
 * dropped for workstations, so the webhook fast-path alone would miss them.
 */
class TacticalDeviceSyncSweepTest extends TestCase
{
    use RefreshDatabase;

    private function syncService(array $queue): TacticalDeviceSyncService
    {
        $http = new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => HandlerStack::create(new MockHandler($queue)),
            'timeout' => 30,
        ]);

        return new TacticalDeviceSyncService(new TacticalClient($http));
    }

    private function linkedAsset(string $agentId, string $status): Asset
    {
        $asset = Asset::factory()->create(['hostname' => 'BOX-1']);
        TacticalAsset::create([
            'asset_id' => $asset->id, 'agent_id' => $agentId, 'hostname' => 'BOX-1',
            'status' => $status, 'synced_at' => now()->subDay(),
        ]);

        return $asset->refresh();
    }

    private function queueAction(string $agentId): void
    {
        $ticket = Ticket::factory()->create();
        TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'tactical_stage_script',
            'content_hash' => str_repeat('a', 64),
            'state' => TechnicianRunState::QueuedOffline,
            'queued_agent_id' => $agentId,
            'queued_dedup_key' => 'k',
            'queued_at' => now()->subMinutes(10),
            'expires_at' => now()->addDays(7),
        ]);
    }

    public function test_detail_sync_dispatches_sweep_on_offline_to_online_transition(): void
    {
        Queue::fake();
        $asset = $this->linkedAsset('AGENT-1', 'offline');
        $this->queueAction('AGENT-1');

        $this->syncService([new Response(200, [], json_encode(['agent_id' => 'AGENT-1', 'status' => 'online']))])
            ->syncDeviceDetail($asset);

        Queue::assertPushed(SweepQueuedActionsForAgent::class, fn ($job) => $job->agentId === 'AGENT-1');
    }

    public function test_detail_sync_does_not_dispatch_when_already_online(): void
    {
        Queue::fake();
        $asset = $this->linkedAsset('AGENT-1', 'online');
        $this->queueAction('AGENT-1');

        $this->syncService([new Response(200, [], json_encode(['agent_id' => 'AGENT-1', 'status' => 'online']))])
            ->syncDeviceDetail($asset);

        Queue::assertNotPushed(SweepQueuedActionsForAgent::class);
    }

    public function test_detail_sync_does_not_dispatch_when_no_queued_actions(): void
    {
        Queue::fake();
        $asset = $this->linkedAsset('AGENT-1', 'offline');
        // No queued action for this agent.

        $this->syncService([new Response(200, [], json_encode(['agent_id' => 'AGENT-1', 'status' => 'online']))])
            ->syncDeviceDetail($asset);

        Queue::assertNotPushed(SweepQueuedActionsForAgent::class);
    }

    public function test_detail_sync_does_not_dispatch_when_read_fails_offline(): void
    {
        Queue::fake();
        $asset = $this->linkedAsset('AGENT-1', 'offline');
        $this->queueAction('AGENT-1');

        // A failed read leaves the snapshot offline — no transition, no sweep.
        $this->syncService([new Response(500, [], 'boom')])->syncDeviceDetail($asset);

        Queue::assertNotPushed(SweepQueuedActionsForAgent::class);
    }

    public function test_bulk_sync_dispatches_sweep_on_offline_to_online_transition(): void
    {
        Queue::fake();
        \App\Models\Client::factory()->create(['tactical_site_id' => 'Acme|Main', 'is_active' => true]);
        // Pre-existing offline agent (its pre-sync status is snapshotted before the loop).
        $asset = Asset::factory()->create(['hostname' => 'BULKBOX']);
        TacticalAsset::create([
            'asset_id' => $asset->id, 'agent_id' => 'AGENT-BULK', 'hostname' => 'BULKBOX',
            'status' => 'offline', 'synced_at' => now()->subDay(),
        ]);
        $this->queueAction('AGENT-BULK');

        $this->syncService([new Response(200, [], json_encode([[
            'agent_id' => 'AGENT-BULK', 'hostname' => 'BULKBOX',
            'client_name' => 'Acme', 'site_name' => 'Main', 'status' => 'online',
        ]]))])->syncDevices();

        $this->assertSame('online', TacticalAsset::where('agent_id', 'AGENT-BULK')->value('status'));
        Queue::assertPushed(SweepQueuedActionsForAgent::class, fn ($job) => $job->agentId === 'AGENT-BULK');
    }
}
