<?php

namespace Tests\Feature\Triage;

use App\Models\Asset;
use App\Models\Client;
use App\Models\TacticalAsset;
use App\Models\Ticket;
use App\Services\Tactical\TacticalClient;
use App\Services\Triage\TriageToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TriageTacticalToolExecutorTest extends TestCase
{
    use RefreshDatabase;

    public function test_tactical_hostname_resolution_searches_within_ticket_client_before_denying_collisions(): void
    {
        $otherClient = Client::factory()->create();
        $targetClient = Client::factory()->create();

        $otherAsset = Asset::factory()->create([
            'client_id' => $otherClient->id,
            'hostname' => 'PC-01',
        ]);
        TacticalAsset::create([
            'asset_id' => $otherAsset->id,
            'agent_id' => 'agent-other',
            'hostname' => 'PC-01',
        ]);

        $targetAsset = Asset::factory()->create([
            'client_id' => $targetClient->id,
            'hostname' => 'PC-01',
        ]);
        TacticalAsset::create([
            'asset_id' => $targetAsset->id,
            'agent_id' => 'agent-target',
            'hostname' => 'PC-01',
        ]);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getAgent')
            ->once()
            ->with('agent-target')
            ->andReturn([
                'hostname' => 'PC-01',
                'status' => 'online',
                'total_ram' => 16,
                'logged_in_username' => 'None',
            ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $ticket = Ticket::factory()->create(['client_id' => $targetClient->id]);
        $result = (new TriageToolExecutor($ticket))->execute('tactical_get_device', [
            'hostname' => 'pc-01',
        ]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('PC-01', $result['hostname']);
        $this->assertSame('online', $result['status']);
        $this->assertSame(16.0, $result['ram_gb']);
    }
}
