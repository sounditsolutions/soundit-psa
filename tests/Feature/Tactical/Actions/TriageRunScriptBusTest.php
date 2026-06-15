<?php

namespace Tests\Feature\Tactical\Actions;

use App\Models\Asset;
use App\Models\Client;
use App\Models\TacticalActionLog;
use App\Models\TacticalAsset;
use App\Models\Ticket;
use App\Services\Tactical\TacticalClient;
use App\Services\Triage\TriageToolExecutor;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 6 / amendment M1: the AI-triage diagnostic path flows through the bus,
 * attributing as actor_label='ai-triage' (actor_id null), AND preserving its
 * existing per-client scoping (resolve agent by hostname WITHIN the ticket's
 * client).
 */
class TriageRunScriptBusTest extends TestCase
{
    use RefreshDatabase;

    private function bindClient(array $queue): void
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $http = new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack]);
        $this->app->instance(TacticalClient::class, new TacticalClient($http));
    }

    public function test_ai_diagnostic_dispatches_through_bus_with_ai_attribution(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'AI-HOST-1']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-AI',
            'hostname' => 'AI-HOST-1',
            'status' => 'online',
        ]);

        $this->bindClient([new Response(200, [], json_encode(['stdout' => 'diag output', 'retcode' => 0]))]);

        $out = (new TriageToolExecutor($ticket))->execute('tactical_run_diagnostic', [
            'hostname' => 'AI-HOST-1',
            'diagnostic' => 'event_log_errors',
        ]);

        $this->assertSame('event_log_errors', $out['diagnostic']);
        $this->assertSame('diag output', $out['stdout']);

        // M1: AI-labeled audit row, actor_id null, ticket attributed.
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.run_script',
            'actor_id' => null,
            'actor_label' => 'ai-triage',
            'asset_id' => $asset->id,
            'ticket_id' => $ticket->id,
            'result_status' => 'ok',
        ]);
    }

    public function test_ai_diagnostic_respects_client_scoping(): void
    {
        // Ticket belongs to client A; the Tactical asset belongs to client B.
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $clientA->id]);

        $assetB = Asset::factory()->create(['client_id' => $clientB->id, 'hostname' => 'OTHER-HOST']);
        TacticalAsset::create([
            'asset_id' => $assetB->id,
            'agent_id' => 'AGENT-B',
            'hostname' => 'OTHER-HOST',
            'status' => 'online',
        ]);

        $this->bindClient([]); // must never be called

        $out = (new TriageToolExecutor($ticket))->execute('tactical_run_diagnostic', [
            'hostname' => 'OTHER-HOST',
            'diagnostic' => 'event_log_errors',
        ]);

        $this->assertArrayHasKey('error', $out);
        $this->assertStringContainsStringIgnoringCase('different client', $out['error']);

        // Client-scope rejection happens BEFORE the bus — no dispatch, no audit row.
        $this->assertSame(0, TacticalActionLog::count());
    }
}
