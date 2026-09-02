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
use Illuminate\Support\Carbon;
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

        // #1133: expiry is caller-chosen and OPTIONAL — advertised, never required.
        $this->assertArrayHasKey('expires_at', $definition['inputSchema']['properties']);
        $this->assertNotContains('expires_at', $definition['inputSchema']['required']);
        $this->assertStringContainsString('never', $definition['inputSchema']['properties']['expires_at']['description']);
        $this->assertStringNotContainsString(
            'expires after '.MeshAllowRule::DEFAULT_LIFETIME_DAYS.' days',
            $definition['description'],
            'the description must not still promise a fixed lifetime',
        );

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
            'date_expiry refused by name' => [['date_expiry' => '2099-01-01'], 'date_expiry (expiry is set with expires_at'],
            // #1133: refuse, never default. Each of these used to become a
            // silent 90 days.
            'unreadable expiry refused' => [['expires_at' => 'whenever'], "expires_at ('whenever') is not a date this system can read"],
            'past expiry refused' => [['expires_at' => '2020-01-01'], "expires_at ('2020-01-01') reads as Wed, Jan 1, 2020 12:00 AM UTC, which is in the past"],
            // A mistyped year is not rejected by PHP, it is REINTERPRETED:
            // '99999-01-01' parses as 2009-01-01. It is refused as past, and
            // the refusal says what it actually read, or the caller cannot see
            // what happened to their year.
            'mistyped year is reinterpreted and refused' => [['expires_at' => '99999-01-01'], "expires_at ('99999-01-01') reads as Thu, Jan 1, 2009 12:00 AM UTC, which is in the past"],
            // Whitespace-only reaches the executor as null: TrimStrings and
            // ConvertEmptyStringsToNull run first. It must still refuse as
            // empty, and must NOT be mistaken for the parameter being absent.
            'empty expiry refused, not treated as absent' => [['expires_at' => '   '], 'expires_at was empty'],
            'explicit null expiry refused, not treated as absent' => [['expires_at' => null], 'expires_at was empty'],
            'non-string expiry refused' => [['expires_at' => 90], 'expires_at must be an ISO-8601 date or datetime'],
            // Reachable only through a relative expression — Carbon accepts
            // these and this one parses to a real year 102026.
            'out-of-range expiry refused' => [['expires_at' => '+100000 years'], 'further away than this system can record'],
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
        $this->assertStringNotContainsStringIgnoringCase('ticket', $comment);
        $this->assertStringNotContainsString("#{$fixture['ticket']->id}", $run->proposed_meta['redacted_params']['comment']);

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

    public function test_an_unresolved_record_does_not_answer_already_allowed(): void
    {
        $this->configureMesh();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWrite();

        // What the scope-fault and id-unrecoverable paths write: the rule was
        // never measured, so it must not suppress a corrected retry.
        MeshAllowRule::create([
            'client_id' => $fixture['client']->id,
            'mesh_customer_id' => self::TENANT,
            'sender' => 'billing@vendor.example',
            'comment' => 'PSA allow ABCDEFGHIJ',
            'mesh_rule_id' => null,
            'expires_at' => now()->addDays(10),
            'state' => MeshAllowRule::STATE_UNRESOLVED,
            'created_by_actor' => 'test',
        ]);

        $result = $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture)));

        $this->assertArrayNotHasKey('idempotent', $result);
        $this->assertSame(1, TechnicianRun::count());
    }

    /**
     * #1133: the staging hash carries the caller's expiry, but the 'executed'
     * audit row is written under the expiry-free base hash. Asking the
     * post-execution dedup question with the lifetime-bearing hash could never
     * be answered yes for any input — the 24-hour window would be gone.
     */
    public function test_a_restage_inside_the_dedup_window_still_matches_the_executed_write(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $run = $this->stagedRun($fixture);

        $write->shouldReceive('createAllowRule')->once()->andReturn(['added_for' => [self::TENANT]]);
        $write->shouldReceive('findRuleByComment')->once()->andReturn(['id' => 'r1']);
        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');

        // Reaped, so liveAllowRule() no longer covers it: the audit dedup is
        // the only thing left standing between a re-stage and a new proposal.
        MeshAllowRule::sole()->update(['state' => MeshAllowRule::STATE_REAPED, 'reaped_at' => now()]);

        $second = $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture)));

        $this->assertTrue($second['idempotent']);
        $this->assertSame($run->id, (int) $second['run_id']);
        $this->assertStringContainsString('already created recently', $second['message']);
        $this->assertSame(1, TechnicianRun::count());
    }

    /**
     * Two awaiting cards for one sender with different lifetimes would both be
     * approvable, and the loser would create nothing while its approver read a
     * lifetime that was never applied.
     */
    public function test_a_second_proposal_for_the_same_sender_with_another_lifetime_is_refused(): void
    {
        $this->configureMesh();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWrite();

        $first = $this->decodedResult($this->callTool(
            $this->token(['mesh_add_allow_rule:staged']),
            'mesh_add_allow_rule',
            $this->stageArgs($fixture, ['expires_at' => 'never']),
        ));

        $response = $this->callTool(
            $this->token(['mesh_add_allow_rule:staged']),
            'mesh_add_allow_rule',
            $this->stageArgs($fixture, ['expires_at' => now()->addDay()->toIso8601String()]),
        );

        $this->assertTrue((bool) $response->json('result.isError'));
        $text = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('already awaiting approval on this ticket with a different lifetime', $text);
        $this->assertStringContainsString('PERMANENT', $text);
        $this->assertStringContainsString('#'.$first['run_id'], $text);
        $this->assertSame(1, TechnicianRun::count());
    }

    /**
     * A deny leaves generation 0 spent, so the next identical proposal lands on
     * generation 1 — and an MCP retry of that same call must still be answered
     * "already staged", never refused with a lifetime difference that does not
     * exist. The lifetime here is byte-identical on every call.
     */
    public function test_an_identical_restage_on_a_later_generation_is_idempotent_not_a_lifetime_refusal(): void
    {
        $this->configureMesh();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWrite();

        $first = $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture)));
        TechnicianRun::whereKey($first['run_id'])->update(['state' => TechnicianRunState::Denied->value]);

        // Past the staging cooldown, so the re-stage is answered by the dedup
        // branches under test rather than by the burst damper.
        $this->travel(6)->minutes();

        $second = $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture)));
        $this->assertNotSame($first['run_id'], $second['run_id'], 'a denied run is never revived; the proposal takes the next generation');

        $retry = $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture)));

        $this->assertTrue($retry['idempotent']);
        $this->assertSame($second['run_id'], $retry['run_id']);
        $this->assertSame('Already staged; awaiting approval.', $retry['message']);
        $this->assertSame(2, TechnicianRun::count());
    }

    /**
     * The post-execution dedup key excludes the expiry deliberately, so this
     * answer is always given against somebody else's lifetime. It must name
     * that lifetime and the record's state, or a technician staging 'never' is
     * told "already created" about a rule that is dated — and, here, already
     * reaped, so no allow is in force at all.
     */
    public function test_the_dedup_answer_names_the_lifetime_and_state_actually_in_force(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $run = $this->stagedRun($fixture);

        $write->shouldReceive('createAllowRule')->once()->andReturn(['added_for' => [self::TENANT]]);
        $write->shouldReceive('findRuleByComment')->once()->andReturn(['id' => 'r1']);
        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');

        $record = MeshAllowRule::sole();
        $record->update(['state' => MeshAllowRule::STATE_REAPED, 'reaped_at' => now()]);

        $second = $this->decodedResult($this->callTool(
            $this->token(['mesh_add_allow_rule:staged']),
            'mesh_add_allow_rule',
            $this->stageArgs($fixture, ['expires_at' => 'never']),
        ));

        $this->assertTrue($second['idempotent']);
        $this->assertStringContainsString('already created recently', $second['message']);
        $this->assertStringContainsString('#'.$record->id, $second['message']);
        $this->assertStringContainsString('set to expire', $second['message']);
        $this->assertStringContainsString("state 'reaped'", $second['message']);
        $this->assertStringContainsString('no allow is in force for this sender', $second['message']);
        $this->assertStringContainsString('NOT applied', $second['message']);
        $this->assertNotSame('never', $second['expires_at'], 'the answer reports the lifetime in force, never the one just asked for');
        $this->assertSame(1, TechnicianRun::count());
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

    public function test_the_recorded_tenant_is_the_one_the_server_attested_not_the_one_we_asked_for(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $run = $this->stagedRun($fixture);

        // `added_for` is the only scope evidence there is, and it names a
        // tenant that is not ours: the rule is on THAT tenant, so that is the
        // only tenant the reaper's list read can ever find it on.
        $write->shouldReceive('createAllowRule')->once()
            ->andReturn(['detail' => 'Allow/Block Rules added', 'added_for' => ['tenant-elsewhere']]);
        $write->shouldNotReceive('findRuleByComment');

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');

        $record = MeshAllowRule::sole();
        $this->assertSame('tenant-elsewhere', $record->mesh_customer_id);
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $record->state);
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

    public function test_a_lost_create_response_is_reconciled_by_re_read_and_the_landed_rule_is_recorded(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $run = $this->stagedRun($fixture);
        $comment = $this->committedComment($run);

        // Mesh commits before it answers, so a lost response is not a failed
        // write. The proposal's comment is the match key that makes the
        // outcome measurable instead of assumed.
        $write->shouldReceive('createAllowRule')->once()->andThrow(new MeshClientException('Mesh API error: timeout', 0));
        $write->shouldReceive('findRuleByComment')->once()
            ->with(self::TENANT, 'billing@vendor.example', $comment)
            ->andReturn(['id' => 'rule-landed', 'created_by' => 'owner@soundit.example']);

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');

        // The read-back recovered the id, but a read-back is NOT scope
        // evidence (the server normalises every stored row), and there was no
        // create response to prove scope from — so the row is unresolved, and
        // a corrected re-stage for this sender is not suppressed by it.
        $record = MeshAllowRule::sole();
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $record->state);
        $this->assertSame('rule-landed', $record->mesh_rule_id);
        $this->assertSame($comment, $record->comment);
        $this->assertSame($run->id, (int) $record->technician_run_id);
        $this->assertNotNull($record->last_error);

        // The proposal is spent: re-approving it must not write a second rule,
        // and an unmeasured write is never audited as a clean execution.
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame(0, TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'executed')->count());
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'executed_with_fault')->count());
    }

    public function test_a_create_whose_outcome_cannot_be_measured_is_still_recorded_for_the_reaper(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $run = $this->stagedRun($fixture);

        $write->shouldReceive('createAllowRule')->once()->andThrow(new MeshClientException('Mesh API error: 502', 502));
        $write->shouldReceive('findRuleByComment')->once()->andThrow(new MeshClientException('Mesh API error: timeout', 0));

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');
        $this->assertStringContainsString('UNMEASURED', (string) session('error'));

        $record = MeshAllowRule::sole();
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $record->state);
        $this->assertNull($record->mesh_rule_id);
        $this->assertNotNull($record->last_error);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
    }

    public function test_a_determinate_rejection_records_no_phantom_rule(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $run = $this->stagedRun($fixture);

        // A rotated API key: Mesh answered and did not act, so nothing was
        // committed and there is nothing to reconcile. A row here would be a
        // phantom the reaper can never retire, and a burned approval.
        $write->shouldReceive('createAllowRule')->once()
            ->andThrow(new MeshClientException('Mesh API error: 401 Unauthorized', 401));
        $write->shouldNotReceive('findRuleByComment');

        $this->actingAs($actor)->post(route('cockpit.approve', $run));

        $this->assertSame(0, MeshAllowRule::count());
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state, 'a determinate rejection leaves the proposal approvable after correction');
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'rejected')->count());
        $this->assertSame(0, TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'executed_with_fault')->count());
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

    public function test_a_later_proposal_gets_its_own_run_and_never_rewrites_a_spent_one(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $run = $this->stagedRun($fixture);
        $comment = $this->committedComment($run);
        $proposed = $run->proposed_content;

        $write->shouldReceive('createAllowRule')->once()->andReturn(['added_for' => [self::TENANT]]);
        $write->shouldReceive('findRuleByComment')->once()->andReturn(['id' => 'r1']);
        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');

        // The rule ran its life — reaped, dedup and cooldown windows past.
        MeshAllowRule::sole()->update(['state' => MeshAllowRule::STATE_REAPED, 'reaped_at' => now()]);
        TechnicianActionLog::query()->update(['created_at' => now()->subDays(2)]);

        $second = $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture)));

        $this->assertTrue($second['success']);
        $this->assertNotSame($run->id, $second['run_id']);
        $this->assertSame(2, TechnicianRun::count());

        // The spent run is the record of a rule that existed upstream, and
        // mesh_allow_rules still points at it: it keeps its own content.
        $done = $run->fresh();
        $this->assertSame(TechnicianRunState::Done, $done->state);
        $this->assertSame($proposed, $done->proposed_content);
        $this->assertSame($comment, $this->committedComment($done));
        $this->assertSame($run->id, (int) MeshAllowRule::sole()->technician_run_id);
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

    // ---- #1133: caller-chosen expiry, including permanent ---------------------

    public function test_an_omitted_expiry_still_means_the_default_lifetime(): void
    {
        $this->configureMesh();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWrite();

        $result = $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture)));
        $run = TechnicianRun::findOrFail($result['run_id']);

        $expected = now()->addDays(MeshAllowRule::DEFAULT_LIFETIME_DAYS);
        $this->assertEqualsWithDelta($expected->timestamp, Carbon::parse($result['expires_at'])->timestamp, 5);
        $this->assertStringContainsString('until the PSA removes the rule on '.$expected->toDayDateTimeString(), $run->proposed_content);
        $this->assertStringNotContainsString('PERMANENT', $run->proposed_content);
    }

    public function test_an_explicit_expiry_is_carried_from_the_proposal_to_the_rule_and_upstream(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $chosen = now()->addDays(3)->startOfSecond();
        $result = $this->decodedResult($this->callTool(
            $this->token(['mesh_add_allow_rule:staged']),
            'mesh_add_allow_rule',
            $this->stageArgs($fixture, ['expires_at' => $chosen->toIso8601String()]),
        ));
        $run = TechnicianRun::findOrFail($result['run_id']);
        $comment = $this->committedComment($run);

        // The approver reads the date THEY were given, not a 90-day default.
        $this->assertSame($chosen->toIso8601String(), $result['expires_at']);
        $this->assertStringContainsString('until the PSA removes the rule on '.$chosen->toDayDateTimeString(), $run->proposed_content);
        $this->assertSame($chosen->toIso8601String(), $run->proposed_meta['redacted_params']['expires_at']);

        // ...and the same instant is what Mesh is told to display.
        $write->shouldReceive('createAllowRule')->once()
            ->with(self::TENANT, 'billing@vendor.example', $comment, $chosen->toIso8601String())
            ->andReturn(['detail' => 'ok', 'added_for' => [self::TENANT]]);
        $write->shouldReceive('findRuleByComment')->once()->andReturn(['id' => 'rule-dated', 'created_by' => 'owner@soundit.example']);

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');

        $record = MeshAllowRule::sole();
        $this->assertSame($chosen->timestamp, $record->expires_at->timestamp);
        $this->assertFalse($record->isPermanent());
    }

    public function test_never_creates_a_permanent_rule_that_says_so_everywhere_and_sends_no_date_upstream(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $result = $this->decodedResult($this->callTool(
            $this->token(['mesh_add_allow_rule:staged']),
            'mesh_add_allow_rule',
            $this->stageArgs($fixture, ['expires_at' => 'Never']),
        ));
        $run = TechnicianRun::findOrFail($result['run_id']);
        $comment = $this->committedComment($run);

        // Criterion 5: the approver is told PERMANENT in words, not shown a
        // blank where a date would be.
        $this->assertSame('never', $result['expires_at']);
        $this->assertStringContainsString('PERMANENTLY', $run->proposed_content);
        $this->assertStringContainsString('NO EXPIRY', $run->proposed_content);
        $this->assertStringContainsString('NEVER remove it', $run->proposed_content);
        $this->assertSame('never', $run->proposed_meta['redacted_params']['expires_at']);
        $this->assertStringContainsString('PERMANENT', $run->proposed_meta['redacted_params']['expiry_note']);

        // Criterion 6: null omits `date_expiry` from the Mesh body entirely.
        $write->shouldReceive('createAllowRule')->once()
            ->with(self::TENANT, 'billing@vendor.example', $comment, null)
            ->andReturn(['detail' => 'ok', 'added_for' => [self::TENANT]]);
        $write->shouldReceive('findRuleByComment')->once()->andReturn(['id' => 'rule-forever', 'created_by' => 'owner@soundit.example']);

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');

        $record = MeshAllowRule::sole();
        $this->assertNull($record->expires_at, 'permanent is a NULL expiry, never a sentinel date');
        $this->assertTrue($record->isPermanent());
        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $record->state);

        $log = TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'executed')->sole();
        $this->assertStringContainsString('PERMANENT', $log->summary);
    }

    public function test_a_permanent_record_blocks_a_duplicate_proposal_and_names_it_permanent(): void
    {
        $this->configureMesh();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWrite();

        // `expires_at > now()` is NULL for this row, not true — without the
        // null arm in liveAllowRule() the strongest duplicate brake stops
        // seeing exactly the rules that never go away.
        $existing = MeshAllowRule::create([
            'client_id' => $fixture['client']->id,
            'mesh_customer_id' => self::TENANT,
            'sender' => 'billing@vendor.example',
            'comment' => 'PSA allow AAAAAAAAAA',
            'mesh_rule_id' => 'rule-forever',
            'expires_at' => null,
            'state' => MeshAllowRule::STATE_ACTIVE,
            'created_by_actor' => 'test',
        ]);

        $result = $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture)));

        $this->assertTrue($result['idempotent']);
        $this->assertSame('never', $result['expires_at']);
        $this->assertStringContainsString('already allowed for this client PERMANENTLY', $result['message']);
        $this->assertStringContainsString('#'.$existing->id, $result['message']);
        $this->assertStringNotContainsString('an unrecorded date', $result['message']);
        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_an_expiry_that_passed_while_awaiting_approval_is_refused_before_any_upstream_call(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $run = null;
        $this->travelTo(now()->subDays(10), function () use ($fixture, &$run) {
            $result = $this->decodedResult($this->callTool(
                $this->token(['mesh_add_allow_rule:staged']),
                'mesh_add_allow_rule',
                $this->stageArgs($fixture, ['expires_at' => now()->addDay()->toIso8601String()]),
            ));
            $run = TechnicianRun::findOrFail($result['run_id']);
        });

        // A rule born already expired is a hole that stays open until the
        // daily reaper happens to run. Nothing is created.
        $write->shouldNotReceive('createAllowRule');

        $this->actingAs($actor)->post(route('cockpit.approve', $run));

        $this->assertSame(0, MeshAllowRule::count());
        $log = TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'rejected')->sole();
        $this->assertStringContainsString('is in the past', $log->summary);
        $this->assertStringContainsString('No upstream call was made', $log->summary);
    }

    public function test_an_approval_that_wrote_nothing_is_never_reported_as_a_clean_execution(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $run = $this->stagedRun($fixture);

        // Whatever this card says, the rule that exists is PERMANENT and the
        // PSA will never remove it. The approver must be told that, on the
        // error channel — not shown a green 'executed'.
        MeshAllowRule::create([
            'client_id' => $fixture['client']->id,
            'mesh_customer_id' => self::TENANT,
            'sender' => 'billing@vendor.example',
            'comment' => 'PSA allow AAAAAAAAAA',
            'mesh_rule_id' => 'rule-forever',
            'expires_at' => null,
            'state' => MeshAllowRule::STATE_ACTIVE,
            'created_by_actor' => 'test',
        ]);

        $write->shouldNotReceive('createAllowRule');

        $response = $this->actingAs($actor)->post(route('cockpit.approve', $run));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('PERMANENTLY', (string) session('error'));
        $this->assertStringContainsString('was NOT applied', (string) session('error'));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame(1, MeshAllowRule::count());
        $this->assertSame(0, TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'executed')->count());
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

    public function test_reaper_never_selects_a_permanent_row(): void
    {
        $this->configureMesh();

        // Permanent, and long past the date a 90-day rule created at the same
        // moment would have died on: age is not what makes a row reapable.
        $permanent = $this->record(['expires_at' => null, 'mesh_rule_id' => 'rule-forever']);
        $permanent->forceFill(['created_at' => now()->subYears(2)])->save();

        // A second, ordinary expired row proves the reaper is running at all —
        // otherwise "examined: 0" would pass for the wrong reason.
        $expired = $this->record(['sender' => 'other@vendor.example', 'mesh_rule_id' => 'rule-expired']);

        $write = $this->mockWrite();
        $write->shouldReceive('deleteRule')->once()->with('rule-expired');
        $write->shouldReceive('ruleAbsent')->once()->with('rule-expired')->andReturn(true);

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $this->assertSame(['examined' => 1, 'reaped' => 1, 'unresolved' => 0, 'failed' => 0], $counts);
        $this->assertSame(MeshAllowRule::STATE_REAPED, $expired->fresh()->state);
        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $permanent->fresh()->state);
        $this->assertNull($permanent->fresh()->reaped_at);
        $this->assertNotContains($permanent->id, MeshAllowRule::reapable()->pluck('id')->all());
    }

    public function test_a_permanent_row_is_not_reapable_in_any_workable_state(): void
    {
        $this->configureMesh();

        foreach ([MeshAllowRule::STATE_ACTIVE, MeshAllowRule::STATE_UNRESOLVED, MeshAllowRule::STATE_REAP_FAILED] as $i => $state) {
            $this->record(['expires_at' => null, 'state' => $state, 'sender' => "s{$i}@vendor.example"]);
        }

        $this->assertSame(0, MeshAllowRule::reapable()->count());

        $write = $this->mockWrite();
        $write->shouldNotReceive('deleteRule');
        $this->assertSame(0, app(MeshAllowRuleReaper::class)->reap()['examined']);
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
