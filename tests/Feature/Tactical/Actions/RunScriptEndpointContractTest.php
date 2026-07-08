<?php

namespace Tests\Feature\Tactical\Actions;

use App\Models\Asset;
use App\Models\TacticalActionLog;
use App\Models\TacticalAsset;
use App\Models\TacticalScript;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 6 / amendment M3: the run-script asset + ticket endpoints now flow
 * through the bus, but the JSON CONTRACT the Script Runner JS parses
 * (resources/views/assets/show.blade.php ~:2110) MUST be preserved:
 *
 *   success    -> 200 {success:true, script_name, stdout, stderr, retcode, execution_time}
 *   not-linked -> 422 {error: ...}        (JS shows the red error box)
 *   offline    -> 422 {error: ...}        (JS shows the red error box)
 *   failure    -> 500 {error: ...}
 *
 * AND every reached dispatch now writes an audit row.
 */
class RunScriptEndpointContractTest extends TestCase
{
    use RefreshDatabase;

    private function bindClient(array $queue): void
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $http = new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack]);
        $this->app->instance(TacticalClient::class, new TacticalClient($http));
    }

    /** @return array<int, Response> */
    private function scriptRunResponses(array $scriptResults): array
    {
        $legacyOutput = (string) ($scriptResults['stdout'] ?? '').(string) ($scriptResults['stderr'] ?? '');

        return [
            new Response(200, [], json_encode([['id' => 10, 'script' => 4242, 'script_results' => ['retcode' => 0]]])),
            new Response(200, [], json_encode($legacyOutput)),
            new Response(200, [], json_encode([['id' => 11, 'script' => 4242, 'script_results' => $scriptResults]])),
        ];
    }

    private function script(): TacticalScript
    {
        return TacticalScript::create([
            'tactical_script_id' => 4242,
            'name' => 'Get Event Log Errors',
            'shell' => 'powershell',
            'default_timeout' => 120,
        ]);
    }

    private function onlineAsset(string $agentId = 'AGENT-1'): Asset
    {
        $asset = Asset::factory()->create(['hostname' => 'WORKSTATION-01']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => $agentId,
            'hostname' => 'WORKSTATION-01',
            'status' => 'online',
        ]);

        return $asset->refresh();
    }

    // ── asset endpoint ─────────────────────────────────────────────────────

    public function test_asset_success_contract_and_audit(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $script = $this->script();
        $this->bindClient($this->scriptRunResponses(['stdout' => 'hello out', 'stderr' => 'a warning', 'retcode' => 0]));

        $resp = $this->actingAs($user)->postJson(route('assets.run-tactical-script', $asset), [
            'script_id' => $script->id,
            'args' => '-Foo bar',
            'timeout' => 90,
        ]);

        $resp->assertOk()
            ->assertJson([
                'success' => true,
                'script_name' => 'Get Event Log Errors',
                'stdout' => 'hello out',
                'stderr' => 'a warning',
                'retcode' => 0,
            ])
            ->assertJsonStructure(['success', 'script_name', 'stdout', 'stderr', 'retcode']);

        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.run_script',
            'asset_id' => $asset->id,
            'actor_id' => $user->id,
            'result_status' => 'ok',
        ]);
    }

    public function test_asset_not_linked_returns_422_error(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create(); // no tacticalAsset
        $script = $this->script();
        $this->bindClient([]);

        $resp = $this->actingAs($user)->postJson(route('assets.run-tactical-script', $asset), [
            'script_id' => $script->id,
            'timeout' => 60,
        ]);

        $resp->assertStatus(422)->assertJsonStructure(['error']);
        $this->assertArrayNotHasKey('success', $resp->json());
    }

    public function test_snapshot_offline_device_still_attempts_via_bus_and_reports_offline(): void
    {
        // M4: a snapshot-offline device is NOT pre-rejected — it attempts via the
        // bus, which reports `offline` (transport failure) -> 422 {error} + audit.
        $user = User::factory()->create();
        $asset = Asset::factory()->create();
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-1',
            'hostname' => $asset->hostname,
            'status' => 'offline',
        ]);
        $script = $this->script();
        $this->bindClient([
            new ConnectException('history unavailable', new Request('GET', 'x')),
            new ConnectException('agent offline', new Request('POST', 'x')),
        ]);

        $resp = $this->actingAs($user)->postJson(route('assets.run-tactical-script', $asset->refresh()), [
            'script_id' => $script->id,
            'timeout' => 60,
        ]);

        $resp->assertStatus(422)->assertJsonStructure(['error']);
        $this->assertDatabaseHas('tactical_action_logs', ['result_status' => 'offline']);
    }

    public function test_snapshot_offline_device_succeeds_if_agent_is_back_online(): void
    {
        // M4 corollary: the snapshot is advisory — if the agent actually responds,
        // the run succeeds even though the snapshot said offline.
        $user = User::factory()->create();
        $asset = Asset::factory()->create();
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-1',
            'hostname' => $asset->hostname,
            'status' => 'offline',
        ]);
        $script = $this->script();
        $this->bindClient($this->scriptRunResponses(['stdout' => 'back online', 'retcode' => 0]));

        $resp = $this->actingAs($user)->postJson(route('assets.run-tactical-script', $asset->refresh()), [
            'script_id' => $script->id,
            'timeout' => 60,
        ]);

        $resp->assertOk()->assertJson(['success' => true, 'stdout' => 'back online']);
    }

    public function test_asset_client_failure_returns_500_error(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $script = $this->script();
        // A transport failure during execute -> the bus returns `offline`; the
        // endpoint maps a non-ok result to an {error} body the JS can render.
        $this->bindClient([
            new ConnectException('history unavailable', new Request('GET', 'x')),
            new ConnectException('boom', new Request('POST', 'x')),
        ]);

        $resp = $this->actingAs($user)->postJson(route('assets.run-tactical-script', $asset), [
            'script_id' => $script->id,
            'timeout' => 60,
        ]);

        $resp->assertJsonStructure(['error']);
        $this->assertArrayNotHasKey('success', $resp->json());
        // Offline is audited.
        $this->assertDatabaseHas('tactical_action_logs', ['result_status' => 'offline']);
    }

    public function test_asset_genuine_http_error_returns_500(): void
    {
        // The contract's `failure -> 500` branch (distinct from offline -> 422):
        // a genuine Tactical HTTP error (e.g. 403) classifies as `error`, and the
        // endpoint maps a non-offline failure to 500 {error}. Previously untested
        // (the "500" test above actually exercised the offline/422 path).
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $script = $this->script();
        $this->bindClient([
            new Response(403, [], 'history forbidden'),
            new Response(403, [], 'forbidden by role'),
        ]);

        $resp = $this->actingAs($user)->postJson(route('assets.run-tactical-script', $asset), [
            'script_id' => $script->id,
            'timeout' => 60,
        ]);

        $resp->assertStatus(500)->assertJsonStructure(['error']);
        $this->assertArrayNotHasKey('success', $resp->json());
        $this->assertDatabaseHas('tactical_action_logs', ['result_status' => 'error']);
    }

    // ── ticket endpoint ──────────────────────────────────────────────────────

    public function test_ticket_success_posts_note_and_audits_with_ticket_id(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $ticket = Ticket::factory()->create();
        $ticket->assets()->attach($asset->id);
        $script = $this->script();
        $this->bindClient($this->scriptRunResponses(['stdout' => 'ticket out', 'retcode' => 0]));

        $resp = $this->actingAs($user)->postJson(route('tickets.run-tactical-script', $ticket), [
            'asset_id' => $asset->id,
            'script_id' => $script->id,
            'timeout' => 60,
        ]);

        $resp->assertOk()->assertJson(['success' => true, 'stdout' => 'ticket out']);

        // m5: the ticket-note side effect stays in the controller.
        $this->assertDatabaseHas('ticket_notes', ['ticket_id' => $ticket->id]);

        // m1: audit row carries the ticket id.
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.run_script',
            'ticket_id' => $ticket->id,
            'result_status' => 'ok',
        ]);
    }

    public function test_ticket_asset_not_linked_to_ticket_is_422(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $ticket = Ticket::factory()->create(); // asset NOT attached
        $script = $this->script();
        $this->bindClient([]);

        $resp = $this->actingAs($user)->postJson(route('tickets.run-tactical-script', $ticket), [
            'asset_id' => $asset->id,
            'script_id' => $script->id,
            'timeout' => 60,
        ]);

        $resp->assertStatus(422)->assertJsonStructure(['error']);
        $this->assertSame(0, TacticalActionLog::count(), 'no dispatch should occur for an unlinked ticket asset');
    }
}
