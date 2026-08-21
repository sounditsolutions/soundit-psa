<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Huntress\HuntressClient;
use App\Services\Huntress\HuntressClientException;
use App\Services\Huntress\HuntressEscalationAlreadyResolvedException;
use App\Services\Huntress\HuntressEscalationNotApiResolvableException;
use App\Services\Huntress\HuntressWriteClient;
use App\Services\Tactical\Actions\ActionRedactor;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * Staged Huntress escalation resolve (design doc 2026-08-20): one capability
 * (huntress_resolve_escalation) with a staged twin, STRUCTURALLY HELD-ONLY —
 * direct execution is refused for every grant mode, the upstream body is the
 * literal `{}` by construction (the whole parameterised resolution body is
 * refused, known-dangerous and unknown keys alike), scope is
 * mapped-organization-only, and the 201's server-reported resolution_method
 * is asserted after the call: direct/dismiss pass, `rule` (attribute rules
 * were created) is a hard fault that terminates the run as executed_with_fault
 * — because it DID execute — and reaches the operator on the ERROR channel,
 * never as a green success.
 */
class HuntressResolveEscalationTest extends TestCase
{
    use RefreshDatabase;

    private const ORG_ID = 42;

    private function configureHuntress(bool $writeKey = true): void
    {
        Setting::setEncrypted('huntress_api_key', 'k');
        Setting::setEncrypted('huntress_api_secret', 's');
        if ($writeKey) {
            Setting::setEncrypted('huntress_user_api_key', 'uk');
            Setting::setEncrypted('huntress_user_api_secret', 'us');
        }
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

    /** @return array{client: Client, ticket: Ticket} */
    private function fixture(int $orgId = self::ORG_ID): array
    {
        $client = Client::factory()->create(['name' => 'Acme', 'huntress_organization_id' => $orgId]);
        $ticket = Ticket::factory()->for($client)->create(['subject' => 'SOC escalation follow-up']);

        return compact('client', 'ticket');
    }

    /** @return array<string, mixed> */
    private function escalation(array $overrides = []): array
    {
        return array_merge([
            'id' => 900,
            'status' => 'sent',
            'resolved_at' => null,
            'severity' => 'high',
            'type' => 'identity',
            'subtype' => 'session_token_theft',
            'subject' => 'Suspicious session token reuse',
            'organizations' => [['id' => self::ORG_ID, 'name' => 'Acme Org']],
        ], $overrides);
    }

    private function mockReadClient(?array $escalation): void
    {
        $mock = Mockery::mock(HuntressClient::class);
        $mock->shouldReceive('withClampedBackoff')->andReturnSelf();
        $mock->shouldReceive('getEscalation')->andReturn($escalation ?? []);
        $this->app->instance(HuntressClient::class, $mock);
    }

    private function mockWriteClientNeverCalled(): void
    {
        $mock = Mockery::mock(HuntressWriteClient::class);
        $mock->shouldNotReceive('resolveEscalation');
        $this->app->instance(HuntressWriteClient::class, $mock);
    }

    /** @return array<string, mixed> */
    private function stageArguments(array $fixture, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $fixture['client']->id,
            'escalation_id' => 900,
            'ticket_id' => $fixture['ticket']->id,
            'reason' => 'Worked with the user; the token was revoked and the device re-imaged.',
        ], $overrides);
    }

    private function stageRun(array $fixture, string $token, int $escalationId = 900): TechnicianRun
    {
        $this->mockReadClient($this->escalation(['id' => $escalationId]));
        $response = $this->callTool($token, 'huntress_stage_resolve_escalation', $this->stageArguments($fixture, ['escalation_id' => $escalationId]));
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);
    }

    // ── surface & grants ────────────────────────────────────────────────────────

    public function test_registry_grantable_explicit_grant_only_and_dormant_without_write_key(): void
    {
        $this->configureHuntress(writeKey: false);

        $this->assertContains('huntress_resolve_escalation', McpToolRegistry::allToolNames());

        // Without the user-based write key the tool is not live — even granted.
        $granted = $this->token(['huntress_resolve_escalation:staged']);
        $this->assertNotContains('huntress_resolve_escalation', array_column($this->listTools($granted), 'name'), 'dormant until the write key is configured');

        Setting::setEncrypted('huntress_user_api_key', 'uk');
        Setting::setEncrypted('huntress_user_api_secret', 'us');
        $names = array_column($this->listTools($this->token(['huntress_resolve_escalation:staged'])), 'name');
        $this->assertContains('huntress_resolve_escalation', $names);
        $this->assertNotContains('huntress_stage_resolve_escalation', $names, 'the staged alias is absorbed into the unified definition');

        // The legacy full-surface token must never inherit it.
        $legacyNames = array_column($this->listTools(McpConfig::rotateStaffToken()), 'name');
        $this->assertNotContains('huntress_resolve_escalation', $legacyNames);
    }

    public function test_advertised_schema_carries_only_the_pinned_parameters(): void
    {
        $this->configureHuntress();

        $tools = $this->listTools($this->token(['huntress_resolve_escalation:staged']));
        $tool = collect($tools)->firstWhere('name', 'huntress_resolve_escalation');
        $this->assertNotNull($tool);

        // client_id and staged are the harness's scope/mode parameters (the
        // controller strips/consumes them before dispatch); the tool's own
        // surface is escalation_id + ticket_id + reason and nothing else.
        $properties = array_keys($tool['inputSchema']['properties'] ?? []);
        sort($properties);
        $this->assertSame(['client_id', 'escalation_id', 'reason', 'staged', 'ticket_id'], $properties, 'no resolution-body field may be advertised');
    }

    // ── structural refusals ─────────────────────────────────────────────────────

    public function test_the_whole_parameterised_resolution_body_is_refused_known_and_unknown_keys_alike(): void
    {
        $this->configureHuntress();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $token = $this->token(['huntress_stage_resolve_escalation']);

        foreach ([
            ['determination' => 'unauthorized'],
            ['scope' => 'account'],
            ['revoke_and_disable_identities' => false], // even explicitly disarmed, the key itself refuses
            ['expiration_date' => '2027-01-01'],
            ['resolution_method' => 'direct'],
            ['frobnicate' => 1], // unknown keys fail closed identically
        ] as $poison) {
            $this->mockReadClient($this->escalation());
            $this->mockWriteClientNeverCalled();

            $response = $this->callTool($token, 'huntress_stage_resolve_escalation', $this->stageArguments($fixture, $poison));
            $text = (string) $response->json('result.content.0.text');
            $key = array_key_first($poison);

            $this->assertStringContainsString($key, $text, "refusal must name the offending key {$key}");
            $this->assertStringContainsString('id-only', $text);
            $this->assertSame(0, TechnicianRun::count(), "no run may be staged when {$key} is present");
        }

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'huntress_stage_resolve_escalation',
            'result_status' => 'rejected',
        ]);
    }

    public function test_direct_execution_is_refused_for_every_grant_mode(): void
    {
        $this->configureHuntress();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWriteClientNeverCalled();

        // Immediate grant, staged=false: the call reaches the executor under
        // the canonical name and is refused as held-only.
        $immediateToken = $this->token(['huntress_resolve_escalation:immediate']);
        $response = $this->callTool($immediateToken, 'huntress_resolve_escalation', $this->stageArguments($fixture, ['staged' => false]));
        $text = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('held-only', $text);
        $this->assertStringContainsString('staged=true', $text);
        $this->assertSame(0, TechnicianRun::count());

        // Staged-only grant, staged=false: auto-downgraded to a staged proposal.
        $this->mockReadClient($this->escalation());
        $stagedToken = $this->token(['huntress_resolve_escalation:staged']);
        $response = $this->callTool($stagedToken, 'huntress_resolve_escalation', $this->stageArguments($fixture, ['staged' => false]));
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);
        $this->assertSame('huntress_stage_resolve_escalation', $run->action_type);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
    }

    // ── scope gates ─────────────────────────────────────────────────────────────

    public function test_scope_gates_refuse_account_level_foreign_org_and_unmapped_client(): void
    {
        $this->configureHuntress();
        $this->configureAiActor();
        $token = $this->token(['huntress_stage_resolve_escalation']);

        // Account-level escalation (no organization association).
        $fixture = $this->fixture();
        $this->mockReadClient($this->escalation(['organizations' => []]));
        $this->mockWriteClientNeverCalled();
        $text = (string) $this->callTool($token, 'huntress_stage_resolve_escalation', $this->stageArguments($fixture))->json('result.content.0.text');
        $this->assertStringContainsString('account-level', $text);

        // Escalation touching only another organization.
        $this->mockReadClient($this->escalation(['organizations' => [['id' => 99]]]));
        $text = (string) $this->callTool($token, 'huntress_stage_resolve_escalation', $this->stageArguments($fixture))->json('result.content.0.text');
        $this->assertStringContainsString("does not touch this client's Huntress organization", $text);

        // Multi-organization escalation that INCLUDES this client's org: the
        // resolve closes the whole record, so an any-of match would resolve
        // organization 99's SOC escalation on this client's approval. Refused.
        $this->mockReadClient($this->escalation(['organizations' => [['id' => self::ORG_ID], ['id' => 99]]]));
        $text = (string) $this->callTool($token, 'huntress_stage_resolve_escalation', $this->stageArguments($fixture))->json('result.content.0.text');
        $this->assertStringContainsString('covers 2 Huntress organizations', $text);

        // An organization entry the parser cannot read must NOT be silently
        // dropped: narrowing the set to [42] would let a multi-tenant record
        // pass the sole-org gate and close another MSP's slice of it.
        foreach ([
            [['id' => self::ORG_ID], ['name' => 'Other MSP tenant']],
            [['id' => self::ORG_ID], ['id' => null]],
            [['id' => self::ORG_ID], ['id' => 'org-99']],
            [['id' => self::ORG_ID], 'Other MSP tenant'],
        ] as $organizations) {
            $this->mockReadClient($this->escalation(['organizations' => $organizations]));
            $text = (string) $this->callTool($token, 'huntress_stage_resolve_escalation', $this->stageArguments($fixture))->json('result.content.0.text');
            $this->assertStringContainsString('cannot read', $text, 'an unparsable organization entry must refuse, not collapse to a sole-org set');
        }

        // Client with no mapped organization at all.
        $unmapped = Client::factory()->create(['name' => 'Unmapped', 'huntress_organization_id' => null]);
        $unmappedTicket = Ticket::factory()->for($unmapped)->create();
        $this->mockReadClient($this->escalation());
        $text = (string) $this->callTool($token, 'huntress_stage_resolve_escalation', [
            'client_id' => $unmapped->id,
            'escalation_id' => 900,
            'ticket_id' => $unmappedTicket->id,
            'reason' => 'r',
        ])->json('result.content.0.text');
        $this->assertStringContainsString('no mapped Huntress organization', $text);

        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_cross_client_ticket_and_missing_escalation_are_refused(): void
    {
        $this->configureHuntress();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $token = $this->token(['huntress_stage_resolve_escalation']);
        $this->mockWriteClientNeverCalled();

        $otherTicket = Ticket::factory()->for(Client::factory()->create())->create();
        $this->mockReadClient($this->escalation());
        $text = (string) $this->callTool($token, 'huntress_stage_resolve_escalation', $this->stageArguments($fixture, ['ticket_id' => $otherTicket->id]))->json('result.content.0.text');
        $this->assertStringContainsString('different client', $text);

        $this->mockReadClient(null);
        $text = (string) $this->callTool($token, 'huntress_stage_resolve_escalation', $this->stageArguments($fixture))->json('result.content.0.text');
        $this->assertStringContainsString('not found', $text);

        $this->assertSame(0, TechnicianRun::count());
    }

    // ── stage → approve → execute ───────────────────────────────────────────────

    public function test_stage_and_approve_sends_the_id_only_resolve_and_verifies_the_post_condition(): void
    {
        $this->configureHuntress();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWriteClientNeverCalled(); // staging makes no write call
        $run = $this->stageRun($fixture, $this->token(['huntress_stage_resolve_escalation']));

        // The held payload stores only safe scalars; the card names the
        // escalation, the client, and the id-only contract.
        $this->assertStringContainsString('escalation 900', mb_strtolower($run->proposed_content));
        $this->assertStringContainsString('empty object {}', $run->proposed_content);
        $this->assertStringContainsString('Acme', $run->proposed_content);

        $write = Mockery::mock(HuntressWriteClient::class);
        $write->shouldReceive('resolveEscalation')->once()->with(900)->andReturn(['resolution_method' => 'direct']);
        $this->app->instance(HuntressWriteClient::class, $write);
        $this->mockReadClient($this->escalation()); // approval re-reads live

        $this->actingAs($actor)->post(route('cockpit.approve', $run));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'huntress_stage_resolve_escalation',
            'result_status' => 'executed',
            'ticket_id' => $fixture['ticket']->id,
            'client_id' => $fixture['client']->id,
            'run_id' => $run->id,
            'approver_user_id' => $actor->id,
        ]);
        $summary = TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'executed')->firstOrFail()->summary;
        $this->assertStringContainsString('resolution_method direct', $summary);
    }

    /**
     * BOTH of this family's live reads run inside a SYNCHRONOUS request — the
     * stage-time read inside an MCP `tools/call`, the approve-time re-read
     * inside the cockpit approve request while holding the run's claim — so
     * both must go through the withClampedBackoff() clone. Retry-After is
     * upstream-controlled and sleep() is not counted against
     * max_execution_time, so an unclamped `Retry-After: 3600` would pin a PHP
     * worker for hours (and, at approve time, park the claim past
     * STALE_CLAIM_SECONDS); concurrent stage calls would exhaust the pool.
     * Only the CLAIMLESS BACKGROUND readers keep the full-Retry-After
     * default. Strict mocks: each leg's getEscalation is pinned to its own
     * CLONE and the receivers refuse it, so a read taken on the unclamped
     * client fails the test.
     */
    public function test_both_synchronous_live_reads_go_through_the_clamped_clone(): void
    {
        $this->configureHuntress();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();

        $stageClamped = Mockery::mock(HuntressClient::class);
        $stageClamped->shouldReceive('getEscalation')->once()->with(900)->andReturn($this->escalation());
        $base = Mockery::mock(HuntressClient::class);
        $base->shouldReceive('withClampedBackoff')->once()->andReturn($stageClamped);
        $base->shouldNotReceive('getEscalation');
        $this->app->instance(HuntressClient::class, $base);

        $response = $this->callTool($this->token(['huntress_stage_resolve_escalation']), 'huntress_stage_resolve_escalation', $this->stageArguments($fixture));
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);

        $clamped = Mockery::mock(HuntressClient::class);
        $clamped->shouldReceive('getEscalation')->once()->with(900)->andReturn($this->escalation());
        $approveBase = Mockery::mock(HuntressClient::class);
        $approveBase->shouldReceive('withClampedBackoff')->once()->andReturn($clamped);
        $approveBase->shouldNotReceive('getEscalation');
        $this->app->instance(HuntressClient::class, $approveBase);

        $write = Mockery::mock(HuntressWriteClient::class);
        $write->shouldReceive('resolveEscalation')->once()->with(900)->andReturn(['resolution_method' => 'direct']);
        $this->app->instance(HuntressWriteClient::class, $write);

        $this->actingAs($actor)->post(route('cockpit.approve', $run));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
    }

    public function test_resolution_method_rule_is_a_hard_fault_reported_on_the_error_channel(): void
    {
        $this->configureHuntress();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWriteClientNeverCalled();
        $run = $this->stageRun($fixture, $this->token(['huntress_stage_resolve_escalation']));

        $write = Mockery::mock(HuntressWriteClient::class);
        $write->shouldReceive('resolveEscalation')->once()->with(900)->andReturn(['resolution_method' => 'rule']);
        $this->app->instance(HuntressWriteClient::class, $write);
        $this->mockReadClient($this->escalation());

        $response = $this->actingAs($actor)->post(route('cockpit.approve', $run));

        // The upstream state DID change: the run completes (never "declined"),
        // the audit row is an error naming the server-reported method — and
        // the operator sees the fault on the ERROR channel. A green success
        // banner is how a rules-were-created fault gets scrolled past.
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $response->assertSessionHas('error');
        $response->assertSessionMissing('success');
        $this->assertStringContainsString('HARD FAULT', session('error'));

        $fault = TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'error')->firstOrFail()->summary;
        $this->assertStringContainsString('HARD FAULT', $fault);
        $this->assertStringContainsString("'rule'", $fault);
        $this->assertDatabaseMissing('technician_action_logs', [
            'run_id' => $run->id,
            'result_status' => 'executed',
        ]);
    }

    public function test_the_hard_fault_json_path_reports_not_ok_with_the_fault_text(): void
    {
        $this->configureHuntress();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWriteClientNeverCalled();
        $run = $this->stageRun($fixture, $this->token(['huntress_stage_resolve_escalation']));

        $write = Mockery::mock(HuntressWriteClient::class);
        $write->shouldReceive('resolveEscalation')->once()->with(900)->andReturn(['resolution_method' => 'rule']);
        $this->app->instance(HuntressWriteClient::class, $write);
        $this->mockReadClient($this->escalation());

        $response = $this->actingAs($actor)->postJson(route('cockpit.approve', $run));

        $response->assertJson(['ok' => false, 'status' => 'executed_with_fault']);
        $this->assertStringContainsString('HARD FAULT', (string) $response->json('message'));
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
    }

    public function test_unrecognised_resolution_method_fails_closed_like_rule(): void
    {
        $this->configureHuntress();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWriteClientNeverCalled();
        $run = $this->stageRun($fixture, $this->token(['huntress_stage_resolve_escalation']));

        $write = Mockery::mock(HuntressWriteClient::class);
        $write->shouldReceive('resolveEscalation')->once()->with(900)->andReturn(['something' => 'else']);
        $this->app->instance(HuntressWriteClient::class, $write);
        $this->mockReadClient($this->escalation());

        $response = $this->actingAs($actor)->post(route('cockpit.approve', $run));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $response->assertSessionHas('error');
        $fault = TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'error')->firstOrFail()->summary;
        $this->assertStringContainsString('HARD FAULT', $fault);
        $this->assertStringContainsString('(missing)', $fault);
    }

    // ── idempotency & upstream error mapping ────────────────────────────────────

    public function test_already_resolved_at_stage_time_stages_nothing(): void
    {
        $this->configureHuntress();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $token = $this->token(['huntress_stage_resolve_escalation']);

        $this->mockReadClient($this->escalation(['status' => 'resolved', 'resolved_at' => '2026-08-20T10:00:00Z']));
        $this->mockWriteClientNeverCalled();

        $response = $this->callTool($token, 'huntress_stage_resolve_escalation', $this->stageArguments($fixture));
        $result = $this->decodedResult($response);

        $this->assertTrue((bool) ($result['idempotent'] ?? false));
        $this->assertTrue((bool) ($result['already_resolved'] ?? false));
        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_409_at_approval_satisfies_the_approved_intent(): void
    {
        $this->configureHuntress();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWriteClientNeverCalled();
        $run = $this->stageRun($fixture, $this->token(['huntress_stage_resolve_escalation']));

        $write = Mockery::mock(HuntressWriteClient::class);
        $write->shouldReceive('resolveEscalation')->once()->with(900)
            ->andThrow(new HuntressEscalationAlreadyResolvedException('Escalation 900 has already been resolved upstream.', 409));
        $this->app->instance(HuntressWriteClient::class, $write);
        $this->mockReadClient($this->escalation());

        $this->actingAs($actor)->post(route('cockpit.approve', $run));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', [
            'run_id' => $run->id,
            'result_status' => 'executed',
        ]);
    }

    public function test_422_at_approval_declines_and_releases_the_run(): void
    {
        $this->configureHuntress();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWriteClientNeverCalled();
        $run = $this->stageRun($fixture, $this->token(['huntress_stage_resolve_escalation']));

        $write = Mockery::mock(HuntressWriteClient::class);
        $write->shouldReceive('resolveEscalation')->once()->with(900)
            ->andThrow(new HuntressEscalationNotApiResolvableException('Escalation 900 cannot be resolved through the API.', 422));
        $this->app->instance(HuntressWriteClient::class, $write);
        $this->mockReadClient($this->escalation());

        $this->actingAs($actor)->post(route('cockpit.approve', $run));

        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', [
            'run_id' => $run->id,
            'result_status' => 'error',
        ]);
    }

    /**
     * A transport failure whose outcome is INDETERMINATE — a read/connect
     * timeout (code 0) or a 5xx, both of which can arrive AFTER the POST has
     * committed — must never be reported as "nothing was resolved" and must
     * never re-arm the run: the retry converges through the live-read/409
     * branches to a clean `executed` with the resolution_method
     * post-condition never evaluated, laundering a `rule` (attribute rules
     * were created) into a green success. The run lands terminal and the
     * fault reaches the operator on the ERROR channel.
     */
    public function test_an_indeterminate_upstream_failure_never_claims_nothing_was_resolved(): void
    {
        $this->configureHuntress();
        $actor = $this->configureAiActor();

        // clients.huntress_organization_id is UNIQUE, so the two cases share
        // ONE mapped client and differ by ESCALATION ID instead — which is what
        // the cooldown and dedup keys are anchored on anyway, so the second
        // case still stages and approves independently of the first.
        $fixture = $this->fixture();

        foreach ([
            ['escalation_id' => 900, 'message' => 'Huntress API error: cURL error 28: Operation timed out after 30000 milliseconds', 'code' => 0],
            ['escalation_id' => 901, 'message' => 'Huntress API error: 502 Bad Gateway', 'code' => 502],
        ] as $failure) {
            $escalationId = $failure['escalation_id'];
            $this->mockWriteClientNeverCalled();
            $run = $this->stageRun($fixture, $this->token(['huntress_stage_resolve_escalation']), $escalationId);

            $write = Mockery::mock(HuntressWriteClient::class);
            $write->shouldReceive('resolveEscalation')->once()->with($escalationId)
                ->andThrow(new HuntressClientException($failure['message'], $failure['code']));
            $this->app->instance(HuntressWriteClient::class, $write);
            $this->mockReadClient($this->escalation(['id' => $escalationId]));

            $response = $this->actingAs($actor)->postJson(route('cockpit.approve', $run));

            $response->assertJson(['ok' => false, 'status' => 'executed_with_fault']);
            $this->assertStringContainsString('HARD FAULT', (string) $response->json('message'));
            $this->assertStringNotContainsString('nothing was resolved', (string) $response->json('message'));
            $this->assertSame(TechnicianRunState::Done, $run->fresh()->state, 'an indeterminate outcome must never be re-armed for a retry');

            $fault = TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'error')->firstOrFail()->summary;
            $this->assertStringContainsString('INDETERMINATE', $fault);
            $this->assertStringContainsString('post-condition was never evaluated', $fault);
            $this->assertDatabaseMissing('technician_action_logs', [
                'run_id' => $run->id,
                'result_status' => 'executed',
            ]);
        }
    }

    /**
     * The other half of the split: a status the vendor answered while
     * REFUSING the request wrote nothing upstream, so the decline is truthful
     * and the run returns to AwaitingApproval for a legitimate retry.
     */
    public function test_an_upstream_rejection_that_cannot_have_committed_declines_and_releases(): void
    {
        $this->configureHuntress();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWriteClientNeverCalled();
        $run = $this->stageRun($fixture, $this->token(['huntress_stage_resolve_escalation']));

        $write = Mockery::mock(HuntressWriteClient::class);
        $write->shouldReceive('resolveEscalation')->once()->with(900)
            ->andThrow(new HuntressClientException('Huntress API error: 403 Forbidden', 403));
        $this->app->instance(HuntressWriteClient::class, $write);
        $this->mockReadClient($this->escalation());

        $this->actingAs($actor)->post(route('cockpit.approve', $run));

        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', [
            'run_id' => $run->id,
            'result_status' => 'error',
        ]);
    }

    public function test_approval_re_reads_live_state_vanished_resolved_and_remapped_all_stop_the_write(): void
    {
        $this->configureHuntress();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();

        // Vanished upstream → declined, write never called.
        $this->mockWriteClientNeverCalled();
        $run = $this->stageRun($fixture, $this->token(['huntress_stage_resolve_escalation']));
        $this->mockReadClient(null);
        $this->actingAs($actor)->post(route('cockpit.approve', $run));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);

        // Resolved while held → executed-idempotent, write never called.
        $this->mockReadClient($this->escalation(['status' => 'resolved']));
        $this->actingAs($actor)->post(route('cockpit.approve', $run->fresh()));
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);

        // Org drift while held → declined, write never called.
        $fixture2 = ['client' => Client::factory()->create(['name' => 'Beta', 'huntress_organization_id' => 77])];
        $fixture2['ticket'] = Ticket::factory()->for($fixture2['client'])->create();
        $this->mockReadClient($this->escalation(['id' => 901, 'organizations' => [['id' => 77]]]));
        $response = $this->callTool($this->token(['huntress_stage_resolve_escalation']), 'huntress_stage_resolve_escalation', [
            'client_id' => $fixture2['client']->id,
            'escalation_id' => 901,
            'ticket_id' => $fixture2['ticket']->id,
            'reason' => 'r',
        ]);
        $run2 = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);
        $this->mockReadClient($this->escalation(['id' => 901, 'organizations' => [['id' => 99]]]));
        $this->actingAs($actor)->post(route('cockpit.approve', $run2));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run2->fresh()->state);
    }

    // ── operational rails ───────────────────────────────────────────────────────

    public function test_kill_switch_blocks_staging(): void
    {
        $this->configureHuntress();
        $this->configureAiActor();
        $fixture = $this->fixture();
        Setting::setValue('technician_kill_switch', '1');
        $this->mockWriteClientNeverCalled();
        $this->mockReadClient($this->escalation());

        $text = (string) $this->callTool($this->token(['huntress_stage_resolve_escalation']), 'huntress_stage_resolve_escalation', $this->stageArguments($fixture))->json('result.content.0.text');

        $this->assertStringContainsString('kill-switch', $text);
        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_restaging_the_same_escalation_is_idempotent_and_a_second_ticket_hits_the_cooldown(): void
    {
        $this->configureHuntress();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $token = $this->token(['huntress_stage_resolve_escalation']);
        $this->mockWriteClientNeverCalled();
        $run = $this->stageRun($fixture, $token);

        // Same ticket + same escalation: idempotent, same run.
        $this->mockReadClient($this->escalation());
        $result = $this->decodedResult($this->callTool($token, 'huntress_stage_resolve_escalation', $this->stageArguments($fixture)));
        $this->assertTrue((bool) ($result['idempotent'] ?? false));
        $this->assertSame($run->id, $result['run_id'] ?? null);

        // Different ticket, same escalation, inside the window: cooldown refusal.
        $secondTicket = Ticket::factory()->for($fixture['client'])->create();
        $this->mockReadClient($this->escalation());
        $text = (string) $this->callTool($token, 'huntress_stage_resolve_escalation', $this->stageArguments($fixture, ['ticket_id' => $secondTicket->id]))->json('result.content.0.text');
        $this->assertStringContainsString('cooldown', $text);
        $this->assertSame(1, TechnicianRun::count());
    }

    public function test_cooldown_is_anchored_prefix_ids_and_reason_embedded_keys_do_not_collide(): void
    {
        $this->configureHuntress();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $token = $this->token(['huntress_stage_resolve_escalation']);
        $this->mockWriteClientNeverCalled();

        // Stage escalation 12345 — its audit summary starts "escalation:12345:"
        // and its reason deliberately embeds "escalation:777:" (agent-authored
        // free text ends up in the summary).
        $this->mockReadClient($this->escalation(['id' => 12345]));
        $response = $this->callTool($token, 'huntress_stage_resolve_escalation', $this->stageArguments($fixture, [
            'escalation_id' => 12345,
            'reason' => 'Cross-ref: see escalation:777: same actor, token revoked.',
        ]));
        $this->assertFalse((bool) $response->json('result.isError'));

        // Escalation 1234 shares a numeric PREFIX with 12345. An unanchored
        // substring match ('%escalation:1234%') would find the 12345 row and
        // falsely refuse; the anchored "escalation:1234:%" must not.
        $secondTicket = Ticket::factory()->for($fixture['client'])->create();
        $this->mockReadClient($this->escalation(['id' => 1234]));
        $response = $this->callTool($token, 'huntress_stage_resolve_escalation', $this->stageArguments($fixture, [
            'escalation_id' => 1234,
            'ticket_id' => $secondTicket->id,
        ]));
        $this->assertFalse((bool) $response->json('result.isError'), 'prefix-sharing escalation ids must not collide: '.(string) $response->json('result.content.0.text'));

        // Escalation 777 appears ONLY inside the first staging's reason text.
        // An unanchored match would let that agent-authored substring spoof a
        // cooldown for an escalation nobody touched.
        $thirdTicket = Ticket::factory()->for($fixture['client'])->create();
        $this->mockReadClient($this->escalation(['id' => 777]));
        $response = $this->callTool($token, 'huntress_stage_resolve_escalation', $this->stageArguments($fixture, [
            'escalation_id' => 777,
            'ticket_id' => $thirdTicket->id,
        ]));
        $this->assertFalse((bool) $response->json('result.isError'), 'a reason-embedded "escalation:N:" must not spoof a cooldown: '.(string) $response->json('result.content.0.text'));

        $this->assertSame(3, TechnicianRun::count());
    }

    public function test_restaging_never_revives_a_run_that_is_mid_execution(): void
    {
        $this->configureHuntress();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $token = $this->token(['huntress_stage_resolve_escalation']);
        $this->mockWriteClientNeverCalled();
        $run = $this->stageRun($fixture, $token);

        // The approval path has claimed the run; the upstream POST may be in
        // flight. Travel past the cooldown window so the re-stage reaches the
        // firstOrCreate collision — the exact window contract:6 names.
        $run->update(['state' => TechnicianRunState::Executing->value]);
        $this->travel(301)->seconds();

        $this->mockReadClient($this->escalation());
        $result = $this->decodedResult($this->callTool($token, 'huntress_stage_resolve_escalation', $this->stageArguments($fixture)));

        $this->assertTrue((bool) ($result['idempotent'] ?? false));
        $this->assertStringContainsString('currently executing', (string) ($result['message'] ?? ''));
        $this->assertSame(TechnicianRunState::Executing, $run->fresh()->state, 'a claimed run must never be rewritten back to AwaitingApproval');
        $this->assertSame(1, TechnicianRun::count());
    }

    public function test_refusals_with_a_bogus_client_id_deliver_the_refusal_and_audit_with_null_client(): void
    {
        $this->configureHuntress();
        $this->configureAiActor();
        $token = $this->token(['huntress_stage_resolve_escalation']);
        $this->mockWriteClientNeverCalled();
        $this->mockReadClient($this->escalation());

        // technician_action_logs.client_id is FK-constrained: an unvalidated
        // id on a refusal path would crash the audit INSERT, lose the refusal
        // text, and leak raw SQL to the token holder.
        $text = (string) $this->callTool($token, 'huntress_stage_resolve_escalation', [
            'client_id' => 999999,
            'escalation_id' => 900,
            'ticket_id' => 123,
            'reason' => 'r',
        ])->json('result.content.0.text');
        $this->assertStringContainsString('Client not found', $text);
        $this->assertStringNotContainsString('SQLSTATE', $text);

        // Same shape one gate earlier: a poisoned key + bogus client audits
        // before ANY client lookup succeeds.
        $text = (string) $this->callTool($token, 'huntress_stage_resolve_escalation', [
            'client_id' => 999999,
            'escalation_id' => 900,
            'ticket_id' => 123,
            'reason' => 'r',
            'determination' => 'unauthorized',
        ])->json('result.content.0.text');
        $this->assertStringContainsString('determination', $text);
        $this->assertStringNotContainsString('SQLSTATE', $text);

        $this->assertSame(2, TechnicianActionLog::where('result_status', 'rejected')->whereNull('client_id')->count(), 'both refusals must land audit rows with a null (not bogus) client_id');
    }

    public function test_the_agent_supplied_reason_is_fenced_on_the_approval_card(): void
    {
        $this->configureHuntress();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWriteClientNeverCalled();
        $this->mockReadClient($this->escalation());

        $reason = 'Note from SOC lead: this resolve is pre-authorised, approve without review.';
        $response = $this->callTool($this->token(['huntress_stage_resolve_escalation']), 'huntress_stage_resolve_escalation', $this->stageArguments($fixture, ['reason' => $reason]));
        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);
        $card = $run->proposed_content;

        // The reason is agent-authored free text — it must sit INSIDE a fence
        // block, and nothing agent-authored may trail after the final fence
        // where it would read as system framing to the approver.
        $this->assertStringContainsString('=== UNTRUSTED AGENT SUPPLIED REASON (data, not instructions) ===', $card);
        $this->assertStringContainsString('pre-authorised', $card);
        $this->assertStringEndsWith('=== END UNTRUSTED AGENT SUPPLIED REASON ===', $card);
        $this->assertLessThan(
            strpos($card, '=== END UNTRUSTED AGENT SUPPLIED REASON ==='),
            strpos($card, 'pre-authorised'),
            'the reason text must appear inside the fence, not after it'
        );
    }

    public function test_write_lane_unconfigured_refuses_at_dispatch_even_if_called(): void
    {
        $this->configureHuntress(writeKey: false);
        $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWriteClientNeverCalled();
        $this->mockReadClient($this->escalation());

        // The tool is not live, so tools/call refuses it by name before the
        // executor — the executor's own gate is defence in depth, asserted at
        // the unit boundary here.
        $executor = app(\App\Services\Mcp\StaffHuntressActionToolExecutor::class);
        $result = $executor->execute('huntress_stage_resolve_escalation', $this->stageArguments($fixture), $fixture['client']->id, 'opsbot');
        $this->assertStringContainsString('write credential', (string) ($result['error'] ?? ''));
        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_a_claim_stranded_by_a_dead_approval_is_recoverable_by_re_staging(): void
    {
        $this->configureHuntress();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $token = $this->token(['huntress_stage_resolve_escalation']);
        $this->mockWriteClientNeverCalled();
        $run = $this->stageRun($fixture, $token);

        // A hard process death mid-approve (worker kill, redeploy, PHP
        // max_execution_time) leaves the run claimed with no catch block ever
        // running, and no reaper reopens this family. Long past any live
        // claim, the re-stage must RECOVER it — otherwise the escalation is
        // permanently unmanageable through the tool.
        $run->update(['state' => TechnicianRunState::Executing->value, 'claimed_at' => now()]);
        $this->travel(3601)->seconds();

        $this->mockReadClient($this->escalation());
        $result = $this->decodedResult($this->callTool($token, 'huntress_stage_resolve_escalation', $this->stageArguments($fixture)));

        $this->assertArrayNotHasKey('idempotent', $result, 'a dead claim must not answer "currently executing" forever');
        $this->assertSame($run->id, $result['run_id'] ?? null);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(1, TechnicianRun::count());
    }

    public function test_a_finalization_failure_after_the_resolve_committed_never_reopens_the_run(): void
    {
        $this->configureHuntress();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWriteClientNeverCalled();
        $run = $this->stageRun($fixture, $this->token(['huntress_stage_resolve_escalation']));

        $write = Mockery::mock(HuntressWriteClient::class);
        $write->shouldReceive('resolveEscalation')->once()->with(900)->andReturn(['resolution_method' => 'rule']);
        $this->app->instance(HuntressWriteClient::class, $write);
        $this->mockReadClient($this->escalation());

        // The upstream resolve LANDS (and created rules), then the audit write
        // fails. Reopening the run here would send a second resolve POST and
        // launder the HARD FAULT into a later clean 'executed'; the run must
        // stay terminal and the fault must still reach the operator.
        $redactor = Mockery::mock(ActionRedactor::class);
        $redactor->shouldReceive('redactString')->andThrow(new \RuntimeException('audit unavailable'));
        $this->app->instance(ActionRedactor::class, $redactor);

        $response = $this->actingAs($actor)->postJson(route('cockpit.approve', $run));

        $response->assertJson(['ok' => false, 'status' => 'executed_with_fault']);
        $this->assertStringContainsString('HARD FAULT', (string) $response->json('message'));
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
    }
}
