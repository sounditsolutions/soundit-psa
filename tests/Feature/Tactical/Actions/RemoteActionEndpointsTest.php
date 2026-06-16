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

    // ── cmd (DESTRUCTIVE, confirm-gated, payload-bound) ─────────────────────

    public function test_cmd_happy_path_dispatches_and_audits_ok(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        // D1: the cmd endpoint returns a bare STRING as the primary shape.
        $this->bindClient([new Response(200, [], json_encode('nt authority\\system'))]);

        $resp = $this->actingAs($user)->postJson(route('assets.run-tactical-command', $asset), [
            'hostname' => 'WORKSTATION-01',
            'shell' => 'cmd',
            'cmd' => 'whoami',
            'timeout' => 30,
        ]);

        $resp->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.run_command',
            'asset_id' => $asset->id,
            'actor_id' => $user->id,
            'result_status' => 'ok',
        ]);
    }

    public function test_cmd_redacts_command_secrets_in_the_immutable_audit_params(): void
    {
        // Code-review BLOCKER (all 3 reviewers): the typed command must be redacted in
        // the append-only tactical_action_logs.params['cmd'] — not only in summary()/
        // the ticket note. The generic params path (WikiRedactor) misses the glued
        // `-p<secret>` and bare-token shapes; only redactCommandString catches them.
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $this->bindClient([new Response(200, [], json_encode('done'))]);

        $secret = 'SuperSecret123';
        $bareToken = 'a1b2c3d4e5f607182930a4b5c6d7e8f901234567'; // 40-char, no keyword

        $resp = $this->actingAs($user)->postJson(route('assets.run-tactical-command', $asset), [
            'hostname' => 'WORKSTATION-01',
            'shell' => 'cmd',
            'cmd' => "mysqldump -u root -p{$secret} db && echo {$bareToken}",
            'timeout' => 30,
        ]);

        $resp->assertOk();

        $rawParams = json_encode(\App\Models\TacticalActionLog::sole()->params);
        $this->assertStringNotContainsString($secret, $rawParams, 'glued -p secret leaked into immutable audit params');
        $this->assertStringNotContainsString($bareToken, $rawParams, 'bare token leaked into immutable audit params');
    }

    public function test_cmd_with_surrounding_whitespace_succeeds_proving_issue_equals_verify(): void
    {
        // A1 endpoint-level regression guard (critic MAJOR): the controller must hash
        // AND dispatch the SAME canonical params. If it minted the token over one form
        // and dispatched another (e.g. raw vs outer-trimmed), the bus's verify-side
        // payloadHash would mismatch -> `blocked`. A command with surrounding whitespace
        // (canonicalized by the outer trim) exercises the issue==verify round-trip.
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $this->bindClient([new Response(200, [], json_encode('ok'))]);

        $resp = $this->actingAs($user)->postJson(route('assets.run-tactical-command', $asset), [
            'hostname' => 'WORKSTATION-01',
            'shell' => 'cmd',
            'cmd' => '   whoami   ',
            'timeout' => 30,
        ]);

        $resp->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('tactical_action_logs', ['result_status' => 'ok']);
        $this->assertDatabaseMissing('tactical_action_logs', ['result_status' => 'blocked']);
    }

    public function test_cmd_wrong_hostname_is_422_and_not_dispatched(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset('WORKSTATION-01');
        $this->bindClient([]); // must never be called

        $resp = $this->actingAs($user)->postJson(route('assets.run-tactical-command', $asset), [
            'hostname' => 'WRONG-HOST',
            'shell' => 'cmd',
            'cmd' => 'whoami',
            'timeout' => 30,
        ]);

        $resp->assertStatus(422)->assertJsonStructure(['error']);
        $this->assertSame(0, TacticalActionLog::count(), 'a hostname mismatch must not reach the bus');
    }

    public function test_cmd_not_linked_is_422(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['hostname' => 'NO-AGENT']);
        $this->bindClient([]);

        $resp = $this->actingAs($user)->postJson(route('assets.run-tactical-command', $asset), [
            'hostname' => 'NO-AGENT',
            'shell' => 'cmd',
            'cmd' => 'whoami',
            'timeout' => 30,
        ]);

        $resp->assertStatus(422)->assertJsonStructure(['error']);
        $this->assertSame(0, TacticalActionLog::count());
    }

    public function test_cmd_invalid_shell_is_rejected_and_audited(): void
    {
        // C2 fail-closed: a shell outside the allowlist is rejected by
        // validateParams -> the bus audits `rejected`; no client call.
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $this->bindClient([]); // must never be called

        $resp = $this->actingAs($user)->postJson(route('assets.run-tactical-command', $asset), [
            'hostname' => 'WORKSTATION-01',
            'shell' => 'bash-as-root',
            'cmd' => 'whoami',
            'timeout' => 30,
        ]);

        // A rejected validation maps to the failure branch (non-offline -> 500).
        $resp->assertStatus(500)->assertJsonStructure(['error']);
        $this->assertArrayNotHasKey('success', $resp->json());
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.run_command',
            'result_status' => 'rejected',
        ]);
    }

    public function test_cmd_offline_is_422_with_audit(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $this->bindClient([new ConnectException('agent offline', new Request('POST', 'agents/AGENT-1/cmd/'))]);

        $resp = $this->actingAs($user)->postJson(route('assets.run-tactical-command', $asset), [
            'hostname' => 'WORKSTATION-01',
            'shell' => 'cmd',
            'cmd' => 'whoami',
            'timeout' => 30,
        ]);

        $resp->assertStatus(422)->assertJsonStructure(['error']);
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.run_command',
            'result_status' => 'offline',
        ]);
    }

    public function test_cmd_is_in_the_csrf_protected_web_group(): void
    {
        $this->assertRouteIsCsrfProtected('assets.run-tactical-command');
    }

    /**
     * Amendment A1 (the cmd security spine), exercised through the SAME bus call
     * the controller makes: the controller mints a confirm token bound to the
     * canonical params' payloadHash and dispatches THAT canonical array. So a
     * token minted for command A must NOT authorize a dispatch of command B
     * (the bus re-derives the verify-side hash from the dispatched params), while
     * the exact command the token was minted for proceeds. (The endpoint never
     * accepts a client-supplied token, so the binding is asserted at the bus —
     * the boundary A1 actually protects.)
     */
    public function test_cmd_token_is_payload_bound_command_a_token_rejects_command_b(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();

        $action = new \App\Services\Tactical\Actions\RunCommandAction;
        $paramsA = $action->validateParams(['shell' => 'cmd', 'cmd' => 'whoami', 'timeout' => 30]);
        $paramsB = $action->validateParams(['shell' => 'cmd', 'cmd' => 'shutdown /s /t 0', 'timeout' => 30]);

        // A token minted for command A.
        $tokenA = \App\Services\Tactical\TacticalActionConfirmToken::issue(
            $action->key(),
            'AGENT-1',
            $user->id,
            $action->payloadHash($paramsA),
        );

        // Dispatch command B with command A's token -> blocked, no client call.
        // (Re-resolve the bus after each bind so it uses the freshly-bound client.)
        $this->bindClient([]);
        $blocked = app(\App\Services\Tactical\TacticalActionService::class)
            ->dispatch($action, $asset, $user, $paramsB, $tokenA);
        $this->assertSame('blocked', $blocked->status, 'a token for command A must not run command B');
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.run_command',
            'result_status' => 'blocked',
        ]);

        // The exact command the token was minted for proceeds.
        $this->bindClient([new Response(200, [], json_encode('ok'))]);
        $ok = app(\App\Services\Tactical\TacticalActionService::class)
            ->dispatch($action, $asset, $user, $paramsA, $tokenA);
        $this->assertSame('ok', $ok->status, 'the matching command must proceed');
    }

    public function test_cmd_without_a_valid_token_is_blocked_and_audited_no_client_call(): void
    {
        // The bus-level guarantee the endpoint relies on: a destructive dispatch
        // with no token is blocked and audited, and the client is never called.
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $bus = app(\App\Services\Tactical\TacticalActionService::class);
        $this->bindClient([]); // must never be called

        $action = new \App\Services\Tactical\Actions\RunCommandAction;
        $params = $action->validateParams(['shell' => 'cmd', 'cmd' => 'whoami', 'timeout' => 30]);

        $result = $bus->dispatch($action, $asset, $user, $params, null);

        $this->assertSame('blocked', $result->status);
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.run_command',
            'result_status' => 'blocked',
        ]);
    }

    // ── shutdown (DESTRUCTIVE, confirm-gated, typed-hostname) ────────────────

    public function test_shutdown_happy_path_dispatches_and_audits_ok(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $this->bindClient([new Response(200, [], json_encode('ok'))]);

        $resp = $this->actingAs($user)->postJson(route('assets.shutdown-tactical', $asset), [
            'hostname' => 'WORKSTATION-01',
        ]);

        $resp->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.shutdown',
            'asset_id' => $asset->id,
            'actor_id' => $user->id,
            'result_status' => 'ok',
        ]);
    }

    public function test_shutdown_wrong_hostname_is_422_and_not_dispatched(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset('WORKSTATION-01');
        $this->bindClient([]);

        $resp = $this->actingAs($user)->postJson(route('assets.shutdown-tactical', $asset), [
            'hostname' => 'WRONG-HOST',
        ]);

        $resp->assertStatus(422)->assertJsonStructure(['error']);
        $this->assertSame(0, TacticalActionLog::count(), 'a hostname mismatch must not reach the bus');
    }

    public function test_shutdown_not_linked_is_422(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['hostname' => 'NO-AGENT']);
        $this->bindClient([]);

        $resp = $this->actingAs($user)->postJson(route('assets.shutdown-tactical', $asset), [
            'hostname' => 'NO-AGENT',
        ]);

        $resp->assertStatus(422)->assertJsonStructure(['error']);
    }

    public function test_shutdown_offline_is_422_with_audit(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $this->bindClient([new ConnectException('agent offline', new Request('POST', 'agents/AGENT-1/shutdown/'))]);

        $resp = $this->actingAs($user)->postJson(route('assets.shutdown-tactical', $asset), [
            'hostname' => 'WORKSTATION-01',
        ]);

        $resp->assertStatus(422)->assertJsonStructure(['error']);
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.shutdown',
            'result_status' => 'offline',
        ]);
    }

    public function test_shutdown_optional_ticket_link_is_recorded_on_the_audit_row(): void
    {
        // E2: the destructive confirm may OPTIONALLY link an open ticket; the
        // ticket id lands on the audit row for incident traceability.
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $ticket = \App\Models\Ticket::factory()->create();
        $this->bindClient([new Response(200, [], json_encode('ok'))]);

        $resp = $this->actingAs($user)->postJson(route('assets.shutdown-tactical', $asset), [
            'hostname' => 'WORKSTATION-01',
            'ticket_id' => $ticket->id,
        ]);

        $resp->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.shutdown',
            'ticket_id' => $ticket->id,
            'result_status' => 'ok',
        ]);
    }

    public function test_shutdown_is_in_the_csrf_protected_web_group(): void
    {
        $this->assertRouteIsCsrfProtected('assets.shutdown-tactical');
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
