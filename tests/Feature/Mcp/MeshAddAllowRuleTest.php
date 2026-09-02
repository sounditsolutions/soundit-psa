<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\MeshAllowRule;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Mesh\MeshAllowRuleReaper;
use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshWriteClient;
use App\Services\Mesh\MeshWriteRejectedException;
use App\Support\McpConfig;
use App\Support\McpToolModes;
use App\Support\McpToolRegistry;
use App\Support\StagedActionLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #1018 — mesh_add_allow_rule.
 *
 * An allow rule is a hole in one customer's mail filtering. Everything here
 * follows from the enforcement test of 2026-09-01: scope is proved on the
 * 201's `added_for` (never on a read-back), the 201 carries no rule id so
 * identity is recovered by re-read on sender + PSA-generated comment, and
 * Mesh's `date_expiry` is display-only so the PSA reaper is the only thing
 * that ever ends a rule — and a DELETE is a reap only once a GET returns 404.
 */
class MeshAddAllowRuleTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-2222-3333-4444-555555555555';

    private function configureMesh(): void
    {
        Setting::setEncrypted('mesh_api_key', 'k');
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

    /** @return array{client: Client, ticket: Ticket} */
    private function fixture(?string $tenant = self::TENANT): array
    {
        $client = Client::factory()->create(['name' => 'Acme', 'mesh_customer_id' => $tenant]);
        $ticket = Ticket::factory()->for($client)->create(['subject' => 'Vendor mail quarantined']);

        return compact('client', 'ticket');
    }

    /** @return array<string, mixed> */
    private function stageArgs(array $fixture, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'sender' => 'billing@vendor.example',
            'confirm_domain' => 'vendor.example',
            'reason' => 'Vendor invoices are being quarantined; approved by the client contact.',
        ], $overrides);
    }

    private function mockWrite(): Mockery\MockInterface
    {
        $client = Mockery::mock(MeshWriteClient::class);
        $client->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        $this->app->instance(MeshWriteClient::class, $client);

        return $client;
    }

    private function stagedRun(array $fixture): TechnicianRun
    {
        $this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture))->assertOk();
        $run = TechnicianRun::where('action_type', 'mesh_stage_add_allow_rule')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        return $run;
    }

    /** The comment the proposal committed to — the reaper's match key. */
    private function committedComment(TechnicianRun $run): string
    {
        return (string) $run->proposed_meta['redacted_params']['comment'];
    }

    // ---- surface / grant shape ------------------------------------------------

    public function test_the_verb_is_a_sensitive_grantable_mesh_tool_and_never_defaults_immediate(): void
    {
        $this->configureMesh();

        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('mesh_admin', $groups);
        $this->assertTrue($groups['mesh_admin']['sensitive']);
        $this->assertContains('mesh_add_allow_rule', array_column($groups['mesh_admin']['tools'], 'name'));
        $this->assertContains('mesh_add_allow_rule', McpToolRegistry::allToolNames());
        $this->assertSame('mesh', McpToolRegistry::integrationForToolName('mesh_add_allow_rule'));

        $this->assertNotContains(
            'mesh_add_allow_rule',
            array_column($this->listTools($this->legacyToken()), 'name'),
            'a legacy full-surface token must not gain the allow-rule verb',
        );

        $scoped = collect($this->listTools($this->token(['mesh_add_allow_rule:staged'])))->keyBy('name');
        $this->assertArrayHasKey('mesh_add_allow_rule', $scoped);
        $definition = $scoped['mesh_add_allow_rule'];
        foreach (['sender', 'confirm_domain', 'reason', 'ticket_id'] as $required) {
            $this->assertContains($required, $definition['inputSchema']['required']);
        }
        $this->assertStringContainsString('WEAKENS', $definition['description']);
        $this->assertStringContainsString('STAGED ONLY', $definition['description']);

        $this->assertNotContains('mesh_stage_add_allow_rule', $scoped->keys()->all(), 'the staged alias is dispatch-only, never advertised');
        $this->assertSame(['mesh_add_allow_rule', McpToolModes::MODE_STAGED], McpToolModes::parseGrantEntry('mesh_add_allow_rule:staged'));
        $this->assertSame(McpToolModes::MODE_STAGED, McpToolModes::defaultMode('mesh_add_allow_rule'));
        $this->assertTrue(StagedActionLabels::isVendorSideEffectAction('mesh_stage_add_allow_rule'));
    }

    public function test_the_verb_is_not_published_while_mesh_is_unconfigured(): void
    {
        $this->assertNotContains(
            'mesh_add_allow_rule',
            array_column($this->listTools($this->token(['mesh_add_allow_rule:staged'])), 'name'),
        );

        $this->configureMesh();
        $this->assertContains(
            'mesh_add_allow_rule',
            array_column($this->listTools($this->token(['mesh_add_allow_rule:staged'])), 'name'),
        );
    }

    public function test_the_immediate_lane_does_not_exist_and_never_reaches_upstream(): void
    {
        $this->configureMesh();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWrite();

        $response = $this->callTool($this->token(['mesh_add_allow_rule']), 'mesh_add_allow_rule', $this->stageArgs($fixture));
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('staged-only', (string) $response->json('result.content.0.text'));

        $this->assertSame(0, TechnicianRun::count());
        $this->assertSame(0, MeshAllowRule::count());
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'rejected')->count());
    }

    public function test_client_id_is_required(): void
    {
        $this->configureMesh();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWrite();

        $args = $this->stageArgs($fixture);
        unset($args['client_id']);
        $response = $this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $args);
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('client_id is required', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianRun::count());
    }

    // ---- fail-closed refusals -------------------------------------------------

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusalProvider(): array
    {
        return [
            'edge refused by name' => [['edge' => true], 'edge (connection-level'],
            'customers[] refused by name' => [['customers' => ['x']], 'customers (partner-wide'],
            'ab refused by name' => [['ab' => false], 'ab (this verb only ever creates ALLOW'],
            'organization_level refused by name' => [['organization_level' => true], 'organization_level (scope is fixed'],
            'date_expiry refused by name' => [['date_expiry' => '2099-01-01'], 'date_expiry (the lifetime is PSA-enforced'],
            'comment refused by name' => [['comment' => 'ticket 123'], 'comment (the comment is PSA-generated'],
            'unknown key refused' => [['spf_bypass' => true], 'spf_bypass (not a parameter of this tool)'],
            'reason missing' => [['reason' => ''], 'reason is required'],
            'wildcard sender' => [['sender' => '*@vendor.example'], 'single email address or a single domain'],
            'sender list' => [['sender' => 'a@vendor.example, b@vendor.example'], 'single email address or a single domain'],
            'bare tld' => [['sender' => 'example', 'confirm_domain' => 'example'], 'single email address or a single domain'],
            'confirm_domain mismatch' => [['confirm_domain' => 'vend0r.example'], "confirm_domain must exactly match the sender's domain ('vendor.example')"],
            'confirm_domain is the address not the domain' => [['confirm_domain' => 'billing@vendor.example'], 'confirm_domain must exactly match'],
        ];
    }

    #[DataProvider('refusalProvider')]
    public function test_staging_fails_closed(array $overrides, string $expected): void
    {
        $this->configureMesh();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWrite();

        $response = $this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture, $overrides));
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'), 'expected a refusal');
        $this->assertStringContainsString($expected, (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianRun::count());
        $this->assertSame(0, MeshAllowRule::count());
    }

    public function test_a_client_without_a_mesh_mapping_is_refused(): void
    {
        $this->configureMesh();
        $this->configureAiActor();
        $fixture = $this->fixture(tenant: null);
        $this->mockWrite();

        $response = $this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture));
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('no Mesh customer mapping', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_ticket_must_belong_to_the_client(): void
    {
        $this->configureMesh();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $other = Ticket::factory()->for(Client::factory()->create(['name' => 'Other']))->create();
        $this->mockWrite();

        $response = $this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture, ['ticket_id' => $other->id]));
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('different client', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianRun::count());
    }

    // ---- staging --------------------------------------------------------------

    public function test_staging_creates_an_awaiting_approval_run_and_calls_nothing_upstream(): void
    {
        $this->configureMesh();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWrite();

        $response = $this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture));
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'));
        $result = $this->decodedResult($response);
        $this->assertTrue($result['success']);
        $this->assertSame('billing@vendor.example', $result['sender']);

        $run = TechnicianRun::findOrFail($result['run_id']);
        $this->assertSame('mesh_stage_add_allow_rule', $run->action_type);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame($fixture['client']->id, (int) $run->client_id);

        // Criterion 6: the approver is told whose name the vendor trail will carry.
        $this->assertStringContainsString('Mesh will record the rule as created by', $run->proposed_content);
        $this->assertStringContainsString('not by the approving technician', $run->proposed_content);
        // Single-address scope is stated, and the whole-domain warning is not.
        $this->assertStringContainsString('this one address only', $run->proposed_content);
        // The comment the rule will carry is shown, and it is free of `#`.
        $comment = $this->committedComment($run);
        $this->assertMatchesRegularExpression('/^PSA allow [A-Z0-9]{10}$/', $comment);
        $this->assertStringContainsString("Mesh comment: {$comment}", $run->proposed_content);
        $this->assertStringNotContainsString('#', $comment);
        $this->assertStringNotContainsString((string) $fixture['ticket']->id, $comment);

        $this->assertSame(0, MeshAllowRule::count());
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'mesh_stage_add_allow_rule')->where('result_status', 'awaiting_approval')->count());
    }

    public function test_a_whole_domain_sender_is_named_as_wider_in_the_proposal(): void
    {
        $this->configureMesh();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWrite();

        $response = $this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture, ['sender' => 'Vendor.Example']));
        $this->assertFalse((bool) $response->json('result.isError'));
        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);
        $this->assertStringContainsString('EVERY sender at', $run->proposed_content);
        $this->assertSame('vendor.example', $run->proposed_meta['redacted_params']['sender']);
    }

    public function test_restaging_identical_content_while_awaiting_is_idempotent(): void
    {
        $this->configureMesh();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWrite();

        $first = $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture)));
        $second = $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture, ['reason' => 'Different wording, same write.'])));

        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['run_id'], $second['run_id']);
        $this->assertSame(1, TechnicianRun::count());
    }

    public function test_a_live_psa_record_blocks_a_duplicate_proposal(): void
    {
        $this->configureMesh();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWrite();

        MeshAllowRule::create([
            'client_id' => $fixture['client']->id,
            'mesh_customer_id' => self::TENANT,
            'sender' => 'billing@vendor.example',
            'comment' => 'PSA allow ABCDEFGHIJ',
            'mesh_rule_id' => 'rule-1',
            'expires_at' => now()->addDays(10),
            'state' => MeshAllowRule::STATE_ACTIVE,
            'created_by_actor' => 'test',
        ]);

        $result = $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture)));
        $this->assertTrue($result['idempotent']);
        $this->assertStringContainsString('already allowed for this client', $result['message']);
        $this->assertSame(0, TechnicianRun::count());
    }

    // ---- approval → execution -------------------------------------------------

    public function test_approval_creates_the_rule_proves_scope_recovers_the_id_and_records_it(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $run = $this->stagedRun($fixture);
        $comment = $this->committedComment($run);

        $write->shouldReceive('createAllowRule')->once()
            ->with(self::TENANT, 'billing@vendor.example', $comment, Mockery::type('string'))
            ->andReturn(['detail' => 'Allow/Block Rules added', 'added_for' => [self::TENANT]]);
        $write->shouldReceive('findRuleByComment')->once()
            ->with(self::TENANT, 'billing@vendor.example', $comment)
            ->andReturn(['id' => 'rule-xyz', 'sender' => 'billing@vendor.example', 'comment' => $comment, 'created_by' => 'owner@soundit.example']);

        $response = $this->actingAs($actor)->post(route('cockpit.approve', $run));
        $response->assertSessionHas('success');

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);

        $record = MeshAllowRule::sole();
        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $record->state);
        $this->assertSame('rule-xyz', $record->mesh_rule_id);
        $this->assertSame($comment, $record->comment);
        $this->assertSame($fixture['ticket']->id, (int) $record->ticket_id);
        $this->assertSame($run->id, (int) $record->technician_run_id);
        $this->assertSame($actor->id, (int) $record->approver_user_id);
        $this->assertSame('owner@soundit.example', $record->upstream_created_by);
        $this->assertEqualsWithDelta(now()->addDays(MeshAllowRule::DEFAULT_LIFETIME_DAYS)->timestamp, $record->expires_at->timestamp, 5);

        $log = TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'executed')->sole();
        $this->assertSame($fixture['ticket']->id, (int) $run->ticket_id);
        $this->assertStringContainsString('rule-xyz', $log->summary);
    }

    public function test_a_201_whose_added_for_is_not_exactly_this_tenant_is_a_fault_not_a_success(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $run = $this->stagedRun($fixture);

        // Partner-wide shape: two tenants. The rule EXISTS upstream now.
        $write->shouldReceive('createAllowRule')->once()
            ->andReturn(['detail' => 'Allow/Block Rules added', 'added_for' => [self::TENANT, 'some-other-tenant']]);
        $write->shouldNotReceive('findRuleByComment');

        $response = $this->actingAs($actor)->post(route('cockpit.approve', $run));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('did not confirm the rule was scoped to this client only', (string) session('error'));

        // The run is spent (the write landed) and the record exists to chase the rule.
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $record = MeshAllowRule::sole();
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $record->state);
        $this->assertNull($record->mesh_rule_id);
        $this->assertNotNull($record->last_error);

        $this->assertSame(0, TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'executed')->count());
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'executed_with_fault')->count());
    }

    public function test_an_unrecoverable_rule_id_is_a_fault_and_stays_unresolved(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $run = $this->stagedRun($fixture);

        $write->shouldReceive('createAllowRule')->once()->andReturn(['added_for' => [self::TENANT]]);
        $write->shouldReceive('findRuleByComment')->once()->andReturn(null);

        $response = $this->actingAs($actor)->post(route('cockpit.approve', $run));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('rule id could not be recovered', (string) session('error'));

        $record = MeshAllowRule::sole();
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $record->state);
        $this->assertNull($record->mesh_rule_id);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
    }

    public function test_a_400_from_mesh_is_passed_through_as_the_refusal_reason(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $run = $this->stagedRun($fixture);

        $write->shouldReceive('createAllowRule')->once()
            ->andThrow(new MeshWriteRejectedException('Invalid sender — sender: reserved domain', ['errors' => ['reserved domain']]));

        $this->actingAs($actor)->post(route('cockpit.approve', $run));

        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state, 'a refused create leaves the proposal approvable after correction');
        $this->assertSame(0, MeshAllowRule::count());
        $log = TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'rejected')->sole();
        $this->assertStringContainsString('reserved domain', $log->summary);
    }

    public function test_a_transport_failure_creates_no_record_and_releases_the_claim(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $run = $this->stagedRun($fixture);

        $write->shouldReceive('createAllowRule')->once()->andThrow(new MeshClientException('Mesh API error: timeout', 0));

        $this->actingAs($actor)->post(route('cockpit.approve', $run));

        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(0, MeshAllowRule::count());
    }

    public function test_approval_refuses_when_the_mesh_mapping_was_removed_after_staging(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $run = $this->stagedRun($fixture);

        $fixture['client']->update(['mesh_customer_id' => null]);
        $write->shouldNotReceive('createAllowRule');

        $this->actingAs($actor)->post(route('cockpit.approve', $run));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(0, MeshAllowRule::count());
    }

    public function test_approval_targets_the_tenant_the_client_maps_to_now_not_at_staging(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $run = $this->stagedRun($fixture);

        $moved = '99999999-8888-7777-6666-555555555555';
        $fixture['client']->update(['mesh_customer_id' => $moved]);

        $write->shouldReceive('createAllowRule')->once()->with($moved, Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn(['added_for' => [$moved]]);
        $write->shouldReceive('findRuleByComment')->once()->with($moved, Mockery::any(), Mockery::any())->andReturn(['id' => 'r2']);

        $this->actingAs($actor)->post(route('cockpit.approve', $run));
        $this->assertSame($moved, MeshAllowRule::sole()->mesh_customer_id);
    }

    public function test_approving_the_same_proposal_twice_makes_only_one_upstream_call(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $run = $this->stagedRun($fixture);

        $write->shouldReceive('createAllowRule')->once()->andReturn(['added_for' => [self::TENANT]]);
        $write->shouldReceive('findRuleByComment')->once()->andReturn(['id' => 'r1']);

        $this->actingAs($actor)->post(route('cockpit.approve', $run));
        $this->actingAs($actor)->post(route('cockpit.approve', $run));

        $this->assertSame(1, MeshAllowRule::count());
    }

    public function test_the_stage_time_cooldown_does_not_block_the_proposals_own_approval(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $run = $this->stagedRun($fixture);

        // The staging call just wrote an awaiting_approval row seconds ago.
        $write->shouldReceive('createAllowRule')->once()->andReturn(['added_for' => [self::TENANT]]);
        $write->shouldReceive('findRuleByComment')->once()->andReturn(['id' => 'r1']);

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
    }

    public function test_kill_switch_refuses_approval_before_any_upstream_call(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $run = $this->stagedRun($fixture);

        Setting::setValue('technician_kill_switch', '1');
        $write->shouldNotReceive('createAllowRule');

        $this->actingAs($actor)->post(route('cockpit.approve', $run));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    // ---- the reaper -----------------------------------------------------------

    /** @return array<string, mixed> */
    private function record(array $overrides = []): MeshAllowRule
    {
        $client = Client::where('mesh_customer_id', self::TENANT)->first()
            ?? Client::factory()->create(['name' => 'Acme', 'mesh_customer_id' => self::TENANT]);

        return MeshAllowRule::create(array_merge([
            'client_id' => $client->id,
            'mesh_customer_id' => self::TENANT,
            'sender' => 'billing@vendor.example',
            'comment' => 'PSA allow ABCDEFGHIJ',
            'mesh_rule_id' => 'rule-1',
            'expires_at' => now()->subHour(),
            'state' => MeshAllowRule::STATE_ACTIVE,
            'created_by_actor' => 'test',
        ], $overrides));
    }

    public function test_reaper_deletes_and_marks_reaped_only_after_a_404_re_read(): void
    {
        $this->configureMesh();
        $record = $this->record();
        $write = $this->mockWrite();
        $write->shouldReceive('deleteRule')->once()->with('rule-1');
        $write->shouldReceive('ruleAbsent')->once()->with('rule-1')->andReturn(true);

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $this->assertSame(['examined' => 1, 'reaped' => 1, 'unresolved' => 0, 'failed' => 0], $counts);
        $record->refresh();
        $this->assertSame(MeshAllowRule::STATE_REAPED, $record->state);
        $this->assertNotNull($record->reaped_at);
    }

    /** @return array<string, array{0: bool|null, 1: string}> */
    public static function notReapedProvider(): array
    {
        return [
            'still readable after delete' => [false, 'still readable upstream'],
            'post-condition unmeasurable' => [null, 'could not be measured'],
        ];
    }

    #[DataProvider('notReapedProvider')]
    public function test_a_delete_without_a_proved_404_is_not_a_reap(?bool $absent, string $expected): void
    {
        $this->configureMesh();
        $record = $this->record();
        $write = $this->mockWrite();
        $write->shouldReceive('deleteRule')->once()->with('rule-1');
        $write->shouldReceive('ruleAbsent')->once()->with('rule-1')->andReturn($absent);

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $this->assertSame(1, $counts['failed']);
        $record->refresh();
        $this->assertSame(MeshAllowRule::STATE_REAP_FAILED, $record->state);
        $this->assertNull($record->reaped_at);
        $this->assertStringContainsString($expected, (string) $record->last_error);
    }

    public function test_reaper_retries_reap_failed_rows_and_a_delete_exception_still_measures_absence(): void
    {
        $this->configureMesh();
        $record = $this->record(['state' => MeshAllowRule::STATE_REAP_FAILED, 'last_error' => 'earlier']);
        $write = $this->mockWrite();
        $write->shouldReceive('deleteRule')->once()->andThrow(new MeshClientException('Mesh API error: 502', 502));
        $write->shouldReceive('ruleAbsent')->once()->with('rule-1')->andReturn(true);

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $this->assertSame(1, $counts['reaped']);
        $this->assertSame(MeshAllowRule::STATE_REAPED, $record->fresh()->state);
    }

    public function test_reaper_resolves_an_unresolved_id_by_re_read_before_deleting(): void
    {
        $this->configureMesh();
        $record = $this->record(['mesh_rule_id' => null, 'state' => MeshAllowRule::STATE_UNRESOLVED]);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleByComment')->once()->with(self::TENANT, 'billing@vendor.example', 'PSA allow ABCDEFGHIJ')->andReturn(['id' => 'late-id']);
        $write->shouldReceive('deleteRule')->once()->with('late-id');
        $write->shouldReceive('ruleAbsent')->once()->with('late-id')->andReturn(true);

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $this->assertSame(1, $counts['reaped']);
        $this->assertSame('late-id', $record->fresh()->mesh_rule_id);
    }

    public function test_reaper_leaves_a_still_unresolvable_rule_loud_and_unresolved(): void
    {
        $this->configureMesh();
        $record = $this->record(['mesh_rule_id' => null, 'state' => MeshAllowRule::STATE_UNRESOLVED]);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleByComment')->twice()->andReturn(null);
        $write->shouldNotReceive('deleteRule');

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $this->assertSame(1, $counts['unresolved']);
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $record->fresh()->state);
        $this->assertStringContainsString('may still be live', (string) $record->fresh()->last_error);

        $this->artisan('mesh:reap-allow-rules')->assertFailed();
    }

    public function test_reaper_ignores_unexpired_and_already_reaped_rows(): void
    {
        $this->configureMesh();
        $this->record(['expires_at' => now()->addDay()]);
        $this->record(['state' => MeshAllowRule::STATE_REAPED, 'reaped_at' => now()->subDay()]);
        $write = $this->mockWrite();
        $write->shouldNotReceive('deleteRule');

        $counts = app(MeshAllowRuleReaper::class)->reap();
        $this->assertSame(0, $counts['examined']);

        $this->artisan('mesh:reap-allow-rules')->assertSuccessful();
    }

    public function test_reaper_does_nothing_when_mesh_is_unconfigured(): void
    {
        $record = $this->record();
        $write = $this->mockWrite();
        $write->shouldReceive('isConfigured')->andReturn(false);
        $write->shouldNotReceive('deleteRule');

        $counts = app(MeshAllowRuleReaper::class)->reap();
        $this->assertSame(0, $counts['examined']);
        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $record->fresh()->state);
    }
}
