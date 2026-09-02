<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Mcp\StaffTacticalAdminToolExecutor;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use App\Services\Technician\TechnicianApprovalService;
use App\Support\McpConfig;
use App\Support\McpToolModes;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #1017 — tactical_remove_agent.
 *
 * DELETE agents/{agent_id}/ is an UNINSTALL upstream, not a record delete: Tactical
 * pushes an `uninstall` command over NATS fire-and-forget, drops its own row, and
 * removes the MeshCentral node best-effort. The response body is a plain sentence,
 * so success is not observable from it — only a 404 on the per-agent read proves it.
 * Everything this suite asserts follows from that: the verb is staged-only by
 * construction, every precondition fails closed, approval re-measures against live
 * state, and the PSA device row is dropped ONLY on a verified removal (#842: absent
 * from the payload is UNKNOWN, and the schema has no "confirmed gone" state).
 */
class TacticalRemoveAgentTest extends TestCase
{
    use RefreshDatabase;

    private function configureTactical(): void
    {
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'secret');
    }

    private function configureAiActor(): User
    {
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        return $actor;
    }

    private function token(array $tools): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: 'opsbot');
    }

    private function legacyToken(): string
    {
        return McpConfig::rotateStaffToken();
    }

    private function callTool(string $token, string $name, array $arguments = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function listTools(string $token): array
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ])
            ->json('result.tools') ?? [];
    }

    /** @return array<string, mixed> */
    private function decodedResult(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    /**
     * A retired device: PSA asset already deactivated, Tactical agent still linked.
     *
     * @return array{client: Client, ticket: Ticket, asset: Asset}
     */
    private function fixture(bool $assetActive = false): array
    {
        $client = Client::factory()->create(['name' => 'Acme', 'tactical_site_id' => 'Acme|Main']);
        $contact = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Client',
            'last_name' => 'Contact',
            'email' => 'client@example.test',
            'is_active' => true,
        ]);
        $ticket = Ticket::factory()->for($client)->create(['contact_id' => $contact->id, 'subject' => 'Offboard RETIRED-01']);
        $asset = Asset::factory()->for($client)->create([
            'name' => 'RETIRED-01',
            'hostname' => 'RETIRED-01',
            'is_active' => $assetActive,
        ]);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'agent-abc',
            'hostname' => 'RETIRED-01',
            'status' => 'offline',
            'last_seen_at' => now()->subDays(30),
        ]);

        return compact('client', 'ticket', 'asset');
    }

    /** A Tactical agent payload that satisfies every live precondition. */
    private function quietAgent(array $overrides = []): array
    {
        return array_merge([
            'agent_id' => 'agent-abc',
            'hostname' => 'RETIRED-01',
            'status' => 'offline',
            'last_seen' => now()->subDays(30)->toIso8601String(),
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function stageArgs(array $fixture, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'asset_id' => $fixture['asset']->id,
            'confirm_hostname' => 'RETIRED-01',
            'reason' => 'Machine was decommissioned and shredded; removing the stale RMM agent.',
        ], $overrides);
    }

    private function mockTactical(): Mockery\MockInterface
    {
        $client = Mockery::mock(TacticalClient::class);
        $this->app->instance(TacticalClient::class, $client);

        return $client;
    }

    private function httpFailure(int $status): TacticalClientException
    {
        return new TacticalClientException("Tactical API error (HTTP {$status})", 0, null, $status, null, false);
    }

    private function transportFailure(): TacticalClientException
    {
        return new TacticalClientException('Tactical API error (transport failure)', 0, null, null, null, true);
    }

    // ---- surface / grant shape ------------------------------------------------

    public function test_remove_agent_is_a_sensitive_grantable_tactical_admin_tool(): void
    {
        $this->configureTactical();

        $groups = McpToolRegistry::groups();
        $adminNames = array_column($groups['tactical_admin']['tools'], 'name');

        $this->assertContains('tactical_remove_agent', $adminNames);
        $this->assertTrue($groups['tactical_admin']['sensitive']);
        $this->assertContains('tactical_remove_agent', McpToolRegistry::allToolNames(), 'tactical_remove_agent must be token-grantable');

        $this->assertNotContains(
            'tactical_remove_agent',
            array_column($this->listTools($this->legacyToken()), 'name'),
            'a legacy full-surface token must not gain the removal verb',
        );

        $scoped = collect($this->listTools($this->token(['tactical_remove_agent:staged'])))->keyBy('name');
        $this->assertArrayHasKey('tactical_remove_agent', $scoped, 'the granted verb must be advertised under its canonical name');
        $definition = $scoped['tactical_remove_agent'];
        $this->assertContains('ticket_id', $definition['inputSchema']['required']);
        $this->assertContains('confirm_hostname', $definition['inputSchema']['required']);
        $this->assertStringContainsString('UNINSTALL', $definition['description']);
        $this->assertStringContainsString('cannot be undone', $definition['description']);

        // The staged internal name is a dispatch alias, never an advertised tool.
        $this->assertNotContains('tactical_stage_remove_agent', array_column($this->listTools($this->token(['tactical_remove_agent:staged'])), 'name'));
        $this->assertSame(['tactical_remove_agent', McpToolModes::MODE_STAGED], McpToolModes::parseGrantEntry('tactical_stage_remove_agent'));
        $this->assertSame(['tactical_remove_agent', McpToolModes::MODE_STAGED], McpToolModes::parseGrantEntry('tactical_remove_agent:staged'));
        $this->assertSame(McpToolModes::MODE_STAGED, McpToolModes::defaultMode('tactical_remove_agent'), 'the verb must never default to an immediate lane');
    }

    public function test_the_immediate_lane_does_not_exist_and_never_reaches_upstream(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->fixture();

        // No expectations at all: any upstream call here is a failure.
        $this->mockTactical();

        // A bare grant resolves to :immediate — the one path that could bypass the
        // cockpit. The executor has no immediate implementation to reach.
        $response = $this->callTool($this->token(['tactical_remove_agent']), 'tactical_remove_agent', $this->stageArgs($fixture));
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('staged-only', (string) $response->json('result.content.0.text'));

        $this->assertSame(0, TechnicianRun::count());
    }

    // ---- fail-closed refusals -------------------------------------------------

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: bool}> */
    public static function refusalProvider(): array
    {
        return [
            'asset still active in the PSA' => [['active_asset' => true], 'Deactivate the asset first', false],
            'no linked Tactical agent' => [['unlink' => true], 'nothing to remove', false],
            'typed hostname does not match' => [['args' => ['confirm_hostname' => 'SOME-OTHER-BOX']], 'typed hostname does not match', false],
            'upstream already has no such agent' => [['get' => 404], 'no agent RETIRED-01 to remove', true],
            'upstream unreadable' => [['get' => 500], 'refused rather than sent blind', true],
            'agent is online' => [['agent' => ['status' => 'online']], 'ONLINE', true],
            'last seen unparseable' => [['agent' => ['last_seen' => 'not a date', 'last_seen_at' => null, 'checkin_time' => null]], 'no usable last-seen time', true],
            'last seen inside the quiet window' => [['agent' => ['last_seen' => 'NOW-1H']], 'inside the 24h quiet window', true],
        ];
    }

    #[DataProvider('refusalProvider')]
    public function test_staging_fails_closed(array $case, string $expected, bool $upstreamRead): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->fixture(assetActive: $case['active_asset'] ?? false);

        if ($case['unlink'] ?? false) {
            $fixture['asset']->tacticalAsset->delete();
        }

        $client = $this->mockTactical();
        if ($upstreamRead) {
            if (isset($case['get'])) {
                $client->shouldReceive('getAgent')->with('agent-abc')->andThrow($this->httpFailure($case['get']));
            } else {
                $overrides = $case['agent'] ?? [];
                if (($overrides['last_seen'] ?? null) === 'NOW-1H') {
                    $overrides['last_seen'] = now()->subHour()->toIso8601String();
                }
                $client->shouldReceive('getAgent')->with('agent-abc')->andReturn($this->quietAgent($overrides));
            }
        } else {
            $client->shouldNotReceive('getAgent');
        }
        $client->shouldNotReceive('deleteAgent');

        $response = $this->callTool(
            $this->token(['tactical_remove_agent:staged']),
            'tactical_remove_agent',
            $this->stageArgs($fixture, $case['args'] ?? []),
        );

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'), 'expected a refusal, got: '.(string) $response->json('result.content.0.text'));
        $this->assertStringContainsString($expected, (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianRun::count(), 'a refused removal must not leave a staged proposal behind');
    }

    public function test_a_device_with_no_hostname_cannot_be_confirmed_and_is_refused(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $fixture['asset']->tacticalAsset->update(['hostname' => '']);
        $fixture['asset']->update(['hostname' => null, 'name' => '']);

        $client = $this->mockTactical();
        $client->shouldNotReceive('getAgent');
        $client->shouldNotReceive('deleteAgent');

        $response = $this->callTool(
            $this->token(['tactical_remove_agent:staged']),
            'tactical_remove_agent',
            $this->stageArgs($fixture, ['confirm_hostname' => '']),
        );

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('no hostname', (string) $response->json('result.content.0.text'));
    }

    public function test_ticket_id_is_required_because_there_is_nowhere_else_to_hold_the_proposal(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->fixture();

        $client = $this->mockTactical();
        $client->shouldNotReceive('deleteAgent');

        $args = $this->stageArgs($fixture);
        unset($args['ticket_id']);

        $response = $this->callTool($this->token(['tactical_remove_agent:staged']), 'tactical_remove_agent', $args);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('ticket_id is required', (string) $response->json('result.content.0.text'));
    }

    // ---- the staged happy path ------------------------------------------------

    public function test_staging_creates_an_awaiting_approval_run_and_calls_nothing_destructive(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->fixture();

        $client = $this->mockTactical();
        $client->shouldReceive('getAgent')->with('agent-abc')->andReturn($this->quietAgent());
        $client->shouldNotReceive('deleteAgent');

        $response = $this->callTool($this->token(['tactical_remove_agent:staged']), 'tactical_remove_agent', $this->stageArgs($fixture));
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $result = $this->decodedResult($response);
        $this->assertTrue($result['success']);
        $this->assertSame('RETIRED-01', $result['hostname']);
        $this->assertNotNull($result['run_id']);

        $run = TechnicianRun::findOrFail($result['run_id']);
        $this->assertSame('tactical_stage_remove_agent', $run->action_type);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame($fixture['ticket']->id, $run->ticket_id);
        $this->assertStringContainsString('UNINSTALLS', $run->proposed_content);
        $this->assertStringContainsString('RETIRED-01', $run->proposed_content);
        $this->assertSame('tactical_remove_agent', $run->proposed_meta['direct_tool']);
        $this->assertSame('RETIRED-01', $run->proposed_meta['redacted_params']['hostname']);

        // The upstream agent id is never carried in the proposal: approval
        // re-resolves it from the PSA asset against live state.
        $this->assertArrayNotHasKey('agent_id', $run->proposed_meta['redacted_params']);
    }

    public function test_restaging_identical_content_while_awaiting_is_idempotent_with_a_real_run_id(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->fixture();

        $client = $this->mockTactical();
        $client->shouldReceive('getAgent')->with('agent-abc')->andReturn($this->quietAgent());
        $client->shouldNotReceive('deleteAgent');

        $token = $this->token(['tactical_remove_agent:staged']);
        $first = $this->decodedResult($this->callTool($token, 'tactical_remove_agent', $this->stageArgs($fixture)));
        $again = $this->decodedResult($this->callTool($token, 'tactical_remove_agent', $this->stageArgs($fixture, [
            'reason' => 'Re-sent the identical staging call.',
        ])));

        $this->assertTrue($again['idempotent'] ?? false);
        $this->assertSame($first['run_id'], $again['run_id'], 'idempotent:true must never be paired with a null or drifting run_id');
        $this->assertSame(1, TechnicianRun::where('action_type', 'tactical_stage_remove_agent')->count());
    }

    // ---- approval ------------------------------------------------------------

    /** Stage one proposal and return its run. */
    private function stagedRun(array $fixture, Mockery\MockInterface $client): TechnicianRun
    {
        $response = $this->callTool($this->token(['tactical_remove_agent:staged']), 'tactical_remove_agent', $this->stageArgs($fixture));
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);
    }

    public function test_approval_removes_the_agent_verifies_the_404_and_drops_the_device_row(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $approver = User::factory()->create();
        $fixture = $this->fixture();
        $tacticalAssetId = $fixture['asset']->tacticalAsset->id;

        $client = $this->mockTactical();
        // Staging read, approval re-measure, then the post-condition read.
        $client->shouldReceive('getAgent')->with('agent-abc')->twice()->andReturn($this->quietAgent());
        $client->shouldReceive('deleteAgent')->once()->with('agent-abc')->andReturn('RETIRED-01 will now be uninstalled.');
        $client->shouldReceive('getAgent')->with('agent-abc')->once()->andThrow($this->httpFailure(404));

        $run = $this->stagedRun($fixture, $client);

        $result = app(TechnicianApprovalService::class)->approveStagedTacticalAdminAction($run, $approver->id);
        $this->assertSame('executed', $result->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);

        // The Tactical device row's subject is proved gone, and the schema has no
        // "confirmed gone" state — leaving it would let the stale sweep call it
        // merely 'offline', which is exactly the #842 misreading.
        $this->assertNull(TacticalAsset::find($tacticalAssetId));
        // The PSA asset itself is untouched; it was already deactivated.
        $this->assertNotNull($fixture['asset']->fresh());
        $this->assertFalse((bool) $fixture['asset']->fresh()->is_active);
    }

    public function test_an_unverified_removal_says_so_and_leaves_the_device_row_in_place(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $approver = User::factory()->create();
        $fixture = $this->fixture();
        $tacticalAssetId = $fixture['asset']->tacticalAsset->id;

        $client = $this->mockTactical();
        $client->shouldReceive('getAgent')->with('agent-abc')->andReturn($this->quietAgent());
        $client->shouldReceive('deleteAgent')->once()->with('agent-abc')->andReturn('RETIRED-01 will now be uninstalled.');

        $run = $this->stagedRun($fixture, $client);
        app(TechnicianApprovalService::class)->approveStagedTacticalAdminAction($run, $approver->id);

        // Agent still readable after the delete: the post-condition failed, so the
        // PSA must not represent the device as gone.
        $this->assertNotNull(TacticalAsset::find($tacticalAssetId));
    }

    public function test_the_executor_reports_verification_honestly_and_never_claims_mesh_removal(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $approver = User::factory()->create();
        $fixture = $this->fixture();

        $client = $this->mockTactical();
        $client->shouldReceive('getAgent')->with('agent-abc')->twice()->andReturn($this->quietAgent());
        $client->shouldReceive('deleteAgent')->once()->with('agent-abc')->andReturn('RETIRED-01 will now be uninstalled.');
        $client->shouldReceive('getAgent')->with('agent-abc')->once()->andThrow($this->httpFailure(404));

        $run = $this->stagedRun($fixture, $client);

        $executor = app(StaffTacticalAdminToolExecutor::class);
        $reflection = new \ReflectionMethod($executor, 'executeAgentRemoval');
        $payload = [
            'asset_id' => $fixture['asset']->id,
            'confirm_hostname' => 'RETIRED-01',
            'reason' => 'approved',
        ];
        $result = $reflection->invoke($executor, $payload, (int) $fixture['client']->id, 'approver', $run, $approver->id);

        $this->assertTrue($result['verified_removed']);
        $this->assertFalse($result['mesh_removal_verified'], 'the MeshCentral half is best-effort upstream and is never observable here');
        $this->assertSame('RETIRED-01 will now be uninstalled.', $result['upstream_message']);
    }

    public function test_an_unmeasurable_post_condition_is_reported_unverified_not_as_success(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $approver = User::factory()->create();
        $fixture = $this->fixture();
        $tacticalAssetId = $fixture['asset']->tacticalAsset->id;

        $client = $this->mockTactical();
        $client->shouldReceive('getAgent')->with('agent-abc')->twice()->andReturn($this->quietAgent());
        $client->shouldReceive('deleteAgent')->once()->with('agent-abc')->andReturn('RETIRED-01 will now be uninstalled.');
        // Transport failure on the read-back: unable to assess, which is not a pass.
        $client->shouldReceive('getAgent')->with('agent-abc')->once()->andThrow($this->transportFailure());

        $run = $this->stagedRun($fixture, $client);

        $executor = app(StaffTacticalAdminToolExecutor::class);
        $result = (new \ReflectionMethod($executor, 'executeAgentRemoval'))->invoke(
            $executor,
            ['asset_id' => $fixture['asset']->id, 'confirm_hostname' => 'RETIRED-01', 'reason' => 'approved'],
            (int) $fixture['client']->id,
            'approver',
            $run,
            $approver->id,
        );

        $this->assertFalse($result['verified_removed']);
        $this->assertStringContainsString('UNVERIFIED', $result['message']);
        $this->assertNotNull(TacticalAsset::find($tacticalAssetId));
    }

    // ---- re-measurement at approval time --------------------------------------

    /**
     * The two live preconditions that can change under a staged proposal, each
     * isolated so the assertion cannot be carried by the other guard: Tactical
     * reporting the agent online, and Tactical having seen it inside the quiet
     * window. A real reboot trips both; a test that sets both proves neither.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function approvalRemeasureProvider(): array
    {
        return [
            'Tactical now reports the agent online' => [['status' => 'online']],
            'Tactical saw the agent inside the quiet window' => [['last_seen' => 'NOW-1H']],
        ];
    }

    #[DataProvider('approvalRemeasureProvider')]
    public function test_approval_refuses_when_live_state_changed_after_staging(array $overrides): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $approver = User::factory()->create();
        $fixture = $this->fixture();

        $client = $this->mockTactical();
        $client->shouldReceive('getAgent')->with('agent-abc')->once()->andReturn($this->quietAgent());
        $run = $this->stagedRun($fixture, $client);

        // Between staging and the human clicking approve, the box came back.
        if (($overrides['last_seen'] ?? null) === 'NOW-1H') {
            $overrides['last_seen'] = now()->subHour()->toIso8601String();
        }
        $client->shouldReceive('getAgent')->with('agent-abc')->once()->andReturn($this->quietAgent($overrides));
        $client->shouldNotReceive('deleteAgent');

        $result = app(TechnicianApprovalService::class)->approveStagedTacticalAdminAction($run, $approver->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state, 'a declined approval must release the claim, not consume the proposal');
        $this->assertNotNull($fixture['asset']->fresh()->tacticalAsset);
    }

    public function test_approval_refuses_when_the_asset_was_reactivated_after_staging(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $approver = User::factory()->create();
        $fixture = $this->fixture();

        $client = $this->mockTactical();
        $client->shouldReceive('getAgent')->with('agent-abc')->once()->andReturn($this->quietAgent());
        $run = $this->stagedRun($fixture, $client);

        $fixture['asset']->update(['is_active' => true]);
        $client->shouldNotReceive('deleteAgent');

        $result = app(TechnicianApprovalService::class)->approveStagedTacticalAdminAction($run, $approver->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertNotNull($fixture['asset']->fresh()->tacticalAsset);
    }

    public function test_approving_the_same_proposal_twice_makes_only_one_upstream_call(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $approver = User::factory()->create();
        $fixture = $this->fixture();

        $client = $this->mockTactical();
        $client->shouldReceive('getAgent')->with('agent-abc')->twice()->andReturn($this->quietAgent());
        $client->shouldReceive('deleteAgent')->once()->with('agent-abc')->andReturn('RETIRED-01 will now be uninstalled.');
        $client->shouldReceive('getAgent')->with('agent-abc')->andThrow($this->httpFailure(404));

        $run = $this->stagedRun($fixture, $client);

        $this->assertSame('executed', app(TechnicianApprovalService::class)->approveStagedTacticalAdminAction($run, $approver->id)->status);
        $this->assertSame('already_handled', app(TechnicianApprovalService::class)->approveStagedTacticalAdminAction($run->fresh(), $approver->id)->status);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
