<?php

namespace Tests\Feature\Tactical\Actions;

use App\Models\Asset;
use App\Models\TacticalActionLog;
use App\Models\TacticalAsset;
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
 * Task 8 (P2): the rebootTacticalAgent endpoint. Destructive, confirm-gated by
 * a typed-hostname match (the human gate) + a bus-layer confirm token, audited,
 * and offline-safe. Lives in the web (CSRF-on) group; the JSON contract mirrors
 * run-script (ok->200, offline->422 {error}, error->500 {error}).
 */
class RebootEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function bindClient(array $queue): void
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $http = new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack]);
        $this->app->instance(TacticalClient::class, new TacticalClient($http));
    }

    private function onlineAsset(string $hostname = 'WORKSTATION-01', string $status = 'online'): Asset
    {
        $asset = Asset::factory()->create(['hostname' => $hostname]);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-1',
            'hostname' => $hostname,
            'status' => $status,
        ]);

        return $asset->refresh();
    }

    public function test_requires_authentication(): void
    {
        $asset = $this->onlineAsset();
        $this->bindClient([]);

        // Unauthenticated -> redirected to login (web group), not dispatched.
        $this->post(route('assets.reboot-tactical', $asset), ['hostname' => 'WORKSTATION-01'])
            ->assertRedirect(route('login'));

        $this->assertSame(0, TacticalActionLog::count());
    }

    public function test_valid_hostname_dispatches_and_audits_ok(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $this->bindClient([new Response(200, [], json_encode([]))]);

        $resp = $this->actingAs($user)->postJson(route('assets.reboot-tactical', $asset), [
            'hostname' => 'WORKSTATION-01',
        ]);

        $resp->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.reboot',
            'asset_id' => $asset->id,
            'actor_id' => $user->id,
            'result_status' => 'ok',
        ]);
    }

    public function test_hostname_match_is_case_insensitive_and_trimmed(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset('WORKSTATION-01');
        $this->bindClient([new Response(200, [], json_encode([]))]);

        $resp = $this->actingAs($user)->postJson(route('assets.reboot-tactical', $asset), [
            'hostname' => '  workstation-01  ',
        ]);

        $resp->assertOk()->assertJson(['success' => true]);
    }

    public function test_hostname_mismatch_is_rejected_and_not_dispatched(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset('WORKSTATION-01');
        $this->bindClient([]); // must never be called

        $resp = $this->actingAs($user)->postJson(route('assets.reboot-tactical', $asset), [
            'hostname' => 'WRONG-HOST',
        ]);

        $resp->assertStatus(422)->assertJsonStructure(['error']);
        $this->assertSame(0, TacticalActionLog::count(), 'a hostname mismatch must not reach the bus');
    }

    public function test_not_linked_asset_is_rejected(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['hostname' => 'NO-AGENT']);
        $this->bindClient([]);

        $resp = $this->actingAs($user)->postJson(route('assets.reboot-tactical', $asset), [
            'hostname' => 'NO-AGENT',
        ]);

        $resp->assertStatus(422)->assertJsonStructure(['error']);
    }

    public function test_offline_agent_returns_clear_error_not_500(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $this->bindClient([new ConnectException('agent offline', new Request('POST', 'agents/AGENT-1/reboot/'))]);

        $resp = $this->actingAs($user)->postJson(route('assets.reboot-tactical', $asset), [
            'hostname' => 'WORKSTATION-01',
        ]);

        // Offline is a normal, surfaced result: 422 {error}, not a 500.
        $resp->assertStatus(422)->assertJsonStructure(['error']);
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.reboot',
            'result_status' => 'offline',
        ]);
    }
}
