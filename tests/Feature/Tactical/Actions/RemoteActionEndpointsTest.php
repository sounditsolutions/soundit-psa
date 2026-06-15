<?php

namespace Tests\Feature\Tactical\Actions;

use App\Models\Asset;
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
 * Chunk 2 (P3 Task 6): the asset-page remote-action endpoints — recover +
 * maintenance (non-destructive, single-click) and cmd + shutdown (destructive,
 * confirm-token + typed-hostname gated). Every reached dispatch writes one audit
 * row; the JSON contract mirrors reboot/run-script (ok->200, not-linked->422,
 * offline->422 {error}, error->500 {error}, no `success` key on failure). The
 * destructive POSTs live in the CSRF-on web group (a POST without a valid token
 * -> 419, no dispatch). cmd's confirm token is payload-bound (a token minted for
 * command A cannot run command B — amendment A1).
 */
class RemoteActionEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function bindClient(array $queue): void
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $http = new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack]);
        $this->app->instance(TacticalClient::class, new TacticalClient($http));
    }

    private function onlineAsset(string $hostname = 'WORKSTATION-01', string $status = 'online', string $agentId = 'AGENT-1'): Asset
    {
        $asset = Asset::factory()->create(['hostname' => $hostname]);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => $agentId,
            'hostname' => $hostname,
            'status' => $status,
        ]);

        return $asset->refresh();
    }

    // ── recover (non-destructive, single-click) ─────────────────────────────

    public function test_recover_dispatches_and_audits_ok(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        // recover mode=mesh is sync; Tactical replies with a scalar message.
        $this->bindClient([new Response(200, [], json_encode('Recovery initiated'))]);

        $resp = $this->actingAs($user)->postJson(route('assets.recover-tactical', $asset), [
            'mode' => 'mesh',
        ]);

        $resp->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.recover',
            'asset_id' => $asset->id,
            'actor_id' => $user->id,
            'result_status' => 'ok',
        ]);
    }

    public function test_recover_not_linked_returns_422(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['hostname' => 'NO-AGENT']);
        $this->bindClient([]);

        $resp = $this->actingAs($user)->postJson(route('assets.recover-tactical', $asset), [
            'mode' => 'mesh',
        ]);

        $resp->assertStatus(422)->assertJsonStructure(['error']);
        $this->assertArrayNotHasKey('success', $resp->json());
    }

    public function test_recover_offline_returns_422_with_audit(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $this->bindClient([new ConnectException('agent offline', new Request('POST', 'agents/AGENT-1/recover/'))]);

        $resp = $this->actingAs($user)->postJson(route('assets.recover-tactical', $asset), [
            'mode' => 'mesh',
        ]);

        $resp->assertStatus(422)->assertJsonStructure(['error']);
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.recover',
            'result_status' => 'offline',
        ]);
    }

    public function test_recover_is_in_the_csrf_protected_web_group(): void
    {
        // The framework structurally skips ValidateCsrfToken under PHPUnit
        // (VerifyCsrfToken::runningUnitTests()), so a live 419 can't be asserted
        // here — instead prove the route carries the `web` group, which is what
        // applies ValidateCsrfToken in production. (Mirrors the reboot route.)
        $this->assertRouteIsCsrfProtected('assets.recover-tactical');
    }

    // ── maintenance (non-destructive, single-click) ─────────────────────────

    public function test_maintenance_enable_dispatches_and_audits_ok(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        // setMaintenance is a PUT; Tactical replies with the (partial) agent body.
        $this->bindClient([new Response(200, [], json_encode(['maintenance_mode' => true]))]);

        $resp = $this->actingAs($user)->postJson(route('assets.maintenance-tactical', $asset), [
            'enabled' => true,
        ]);

        $resp->assertOk()->assertJson(['success' => true, 'enabled' => true]);

        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.set_maintenance',
            'asset_id' => $asset->id,
            'actor_id' => $user->id,
            'result_status' => 'ok',
        ]);
    }

    public function test_maintenance_disable_dispatches_and_audits_ok(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $this->bindClient([new Response(200, [], json_encode(['maintenance_mode' => false]))]);

        $resp = $this->actingAs($user)->postJson(route('assets.maintenance-tactical', $asset), [
            'enabled' => false,
        ]);

        $resp->assertOk()->assertJson(['success' => true, 'enabled' => false]);

        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.set_maintenance',
            'result_status' => 'ok',
        ]);
    }

    public function test_maintenance_not_linked_returns_422(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['hostname' => 'NO-AGENT']);
        $this->bindClient([]);

        $resp = $this->actingAs($user)->postJson(route('assets.maintenance-tactical', $asset), [
            'enabled' => true,
        ]);

        $resp->assertStatus(422)->assertJsonStructure(['error']);
        $this->assertArrayNotHasKey('success', $resp->json());
    }

    public function test_maintenance_offline_returns_422_with_audit(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $this->bindClient([new ConnectException('agent offline', new Request('PUT', 'agents/AGENT-1/'))]);

        $resp = $this->actingAs($user)->postJson(route('assets.maintenance-tactical', $asset), [
            'enabled' => true,
        ]);

        $resp->assertStatus(422)->assertJsonStructure(['error']);
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.set_maintenance',
            'result_status' => 'offline',
        ]);
    }

    public function test_maintenance_is_in_the_csrf_protected_web_group(): void
    {
        $this->assertRouteIsCsrfProtected('assets.maintenance-tactical');
    }

    /**
     * Assert a named route runs through the `web` middleware group, which is
     * what applies ValidateCsrfToken in production. The runtime 419 is
     * unobservable under PHPUnit because VerifyCsrfToken::handle()
     * short-circuits when runningUnitTests(); web-group membership is the real,
     * testable CSRF guarantee (and this app registers no CSRF except-list).
     */
    private function assertRouteIsCsrfProtected(string $routeName): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($routeName);
        $this->assertNotNull($route, "route {$routeName} should be registered");

        $this->assertContains(
            'web',
            $route->gatherMiddleware(),
            "route {$routeName} must be in the CSRF-protected web group"
        );
    }
}
