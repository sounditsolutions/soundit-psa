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

    private function stagedRun(array $fixture, array $overrides = []): TechnicianRun
    {
        $this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture, $overrides))->assertOk();
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
        // #5154: the pre-#5131 text said 'omit it for the 90-day default' and
        // 'the 90-day default if it is omitted'; the literal this used to pin
        // ('expires after 90 days') never appeared in it, so the old assertion
        // caught nothing. This one matches every spelling that text used.
        $defaultLifetime = '/\b\d+\s*-?\s*days?\b|\bdefault\b[^.]*\b(lifetime|expiry)\b|\b(lifetime|expiry)\b[^.]*\bdefault\b|for the default/i';
        // #5415: positive control, in this test, on the three pre-#5131
        // spellings (StaffMeshAdminToolExecutor at ccb5bc38^, with
        // DEFAULT_LIFETIME_DAYS = 90). A regex edited so it no longer matches
        // them fails here instead of passing vacuously below.
        foreach ([
            'or omit it for the 90-day default. ',
            'or the 90-day default if it is omitted, or NEVER if it is "never". ',
            'Omit it for the default 90-day lifetime. ',
        ] as $oldSpelling) {
            $this->assertMatchesRegularExpression($defaultLifetime, $oldSpelling, "the regex must catch the old spelling '{$oldSpelling}'");
        }
        // #5415 / #5489: a staged-only token is served the stage alias's
        // description under the public name (McpToolModes::unifyDefinition()),
        // so $definition already IS that text. The direct definition, which a
        // non-staged-mode grant is served, is read separately here; before
        // #5489 nothing ran it through this regex.
        $definitions = collect(\App\Services\Mcp\StaffMeshAdminToolExecutor::definitions())->keyBy('name');
        $stagedAlias = $definitions['mesh_stage_add_allow_rule'];
        $direct = $definitions['mesh_add_allow_rule'];
        // #5646: the substitution the comment above relies on, asserted
        // rather than assumed. If unifyDefinition() stopped serving the
        // alias's description under the public name, the loop below would
        // stop reading the stage alias's text at all; read it directly too.
        $this->assertStringStartsWith(trim($stagedAlias['description']), $definition['description'], 'a staged-only token is served the stage alias description (plus the staged-mode notice)');
        foreach ([
            'description' => $definition['description'],
            'staged alias description' => $stagedAlias['description'],
            'expires_at' => $definition['inputSchema']['properties']['expires_at']['description'],
            'direct description' => $direct['description'],
            'direct expires_at' => $direct['input_schema']['properties']['expires_at']['description'],
            // #5497 item 7: the alias's own expires_at schema text too.
            'staged alias expires_at' => $stagedAlias['input_schema']['properties']['expires_at']['description'],
        ] as $label => $text) {
            $this->assertDoesNotMatchRegularExpression(
                $defaultLifetime,
                $text,
                "the {$label} text must not advertise a default lifetime",
            );
        }

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

        // Bypass grant parsing to exercise the retained executor defence directly.
        $result = app(\App\Services\Mcp\StaffMeshAdminToolExecutor::class)->execute(
            'mesh_add_allow_rule', $this->stageArgs($fixture), null, 'synthetic-test'
        );
        $this->assertStringContainsString('staged-only', $result['error']);

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
            // #1133: refuse, never default. A value that was SENT but cannot
            // be used is not the absent (permanent) case.
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
            // The empty refusal names the real default, and no 90-day one.
            'empty expiry refusal names permanent as the omitted default' => [['expires_at' => ''], 'Omit the parameter entirely for a permanent rule'],
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
     * Nh0dzF2T: the duplicate brake's text for a DATED unsettled row must match
     * what the reaper now does. A scope-proved dated row is settled by the
     * hourly identify pass (MeshAllowRuleReaper::settleUnexpired) as soon as
     * its rule is found, so "cannot settle until its expiry passes" would send
     * the approver away to wait months for a block that clears within the
     * hour. A dated row whose scope was never proved still waits for expiry.
     */
    public function test_the_dated_unsettled_brake_says_the_next_reaper_run_settles_a_scope_proved_row(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldNotReceive('createAllowRule');

        $expiry = now()->addDays(30)->startOfSecond();
        $row = MeshAllowRule::create([
            'client_id' => $fixture['client']->id,
            'mesh_customer_id' => self::TENANT,
            'sender' => 'billing@vendor.example',
            'comment' => 'PSA allow ABCDEFGHIJ',
            'mesh_rule_id' => null,
            'expires_at' => $expiry,
            'state' => MeshAllowRule::STATE_UNRESOLVED,
            'scope_proved' => true,
            'created_by_actor' => 'test',
        ]);

        $run = $this->stagedRun($fixture);
        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');
        $proved = (string) session('error');

        $this->assertStringContainsString('PSA record #'.$row->id, $proved);
        $this->assertStringContainsString('the hourly expiry job only has to IDENTIFY it, and this block clears on the first run before its expiry ('.$expiry->toIso8601String().') that finds exactly one rule in Mesh carrying its sender and comment', $proved);
        $this->assertStringNotContainsString('until its expiry', $proved);
        $this->assertStringNotContainsString('PERMANENT', $proved);

        // The same dated row WITHOUT scope proof is never settled by an id, so
        // for it the expiry really is the next thing that can happen.
        $row->update(['scope_proved' => false]);
        TechnicianRun::query()->delete();
        TechnicianActionLog::query()->delete();
        $run = $this->stagedRun($fixture);
        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');
        $unproved = (string) session('error');

        $this->assertStringContainsString('cannot settle that record until its expiry ('.$expiry->toIso8601String().') passes', $unproved);
        $this->assertStringNotContainsString('only has to IDENTIFY', $unproved);
        $this->assertSame(0, MeshAllowRule::where('state', MeshAllowRule::STATE_ACTIVE)->count());
    }

    /**
     * Nh0dzF2T: "only has to IDENTIFY it" is true only of an UNRESOLVED row
     * that is permanent or unexpired. A reap_failed row records a removal that
     * did not prove absence and the identify pass never settles it; a dated
     * row past its expiry is the reap pass's, which removes it rather than
     * settling it. Neither may be told that identification lifts the block,
     * scope-proved or not.
     */
    public function test_the_brake_never_promises_identification_for_a_reap_failed_or_expired_row(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldNotReceive('createAllowRule');

        $row = MeshAllowRule::create([
            'client_id' => $fixture['client']->id,
            'mesh_customer_id' => self::TENANT,
            'sender' => 'billing@vendor.example',
            'comment' => 'PSA allow ABCDEFGHIJ',
            'mesh_rule_id' => 'rule-synthetic-1',
            'expires_at' => now()->subDay(),
            'state' => MeshAllowRule::STATE_REAP_FAILED,
            'scope_proved' => true,
            'created_by_actor' => 'test',
        ]);

        $removal = 'this block stays until the PSA proves a removal against this record';
        $past = now()->subDay()->startOfSecond();
        $cases = [
            'expired reap_failed' => [$past, MeshAllowRule::STATE_REAP_FAILED, $removal],
            'unexpired reap_failed' => [now()->addDays(30)->startOfSecond(), MeshAllowRule::STATE_REAP_FAILED, $removal],
            // Never reaped and, once its rule is gone, not removable by any
            // re-staged removal: it must not be told to wait for one.
            'permanent reap_failed' => [null, MeshAllowRule::STATE_REAP_FAILED, 'that record is PERMANENT (no expiry), so the expiry job never retries the removal and nothing in the PSA will clear this block on its own'],
            'expired unresolved' => [$past, MeshAllowRule::STATE_UNRESOLVED, 'Its expiry ('.$past->toIso8601String().') has passed, so the hourly expiry job does not settle it'],
        ];

        foreach ($cases as $label => [$expiry, $state, $expected]) {
            $row->forceFill(['expires_at' => $expiry, 'state' => $state])->save();
            TechnicianRun::query()->delete();
            TechnicianActionLog::query()->delete();
            $run = $this->stagedRun($fixture);
            $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');
            $message = (string) session('error');

            $this->assertStringContainsString('PSA record #'.$row->id, $message, $label);
            $this->assertStringContainsString($expected, $message, $label);
            $this->assertStringNotContainsString('IDENTIFY', $message, $label);

            if ($expiry === null) {
                $this->assertStringNotContainsString($removal, $message, $label);
                $this->assertStringContainsString('clear the PSA record by hand', $message, $label);
            } else {
                $this->assertStringNotContainsString('never retries the removal', $message, $label);
            }
        }
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
        $record = MeshAllowRule::sole();
        $record->update(['state' => MeshAllowRule::STATE_REAPED, 'reaped_at' => now()]);

        $second = $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture)));

        // The base-hash match is what is under test, and the refusal below is
        // only reachable through it: without it this call falls past the dedup
        // branch entirely and stages a second proposal.
        $this->assertStringContainsString('#'.$record->id, $second['error']);
        $this->assertStringContainsString('proved it absent upstream', $second['error']);
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

        $second = $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture)));
        $this->assertNotSame($first['run_id'], $second['run_id'], 'a denied run is never revived; the proposal takes the next generation');

        $retry = $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture)));

        $this->assertTrue($retry['idempotent']);
        $this->assertSame($second['run_id'], $retry['run_id']);
        // The executor returns exactly this string; McpStaffController prepends
        // the staged-downgrade notice for a staged-only token, as it does for
        // every other message in this file.
        $this->assertStringContainsString('Already staged; awaiting approval.', $retry['message']);
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
        // Dated on purpose: the second call below asks for 'never', and the
        // answer must name the DATED lifetime that is actually in force.
        $run = $this->stagedRun($fixture, ['expires_at' => now()->addDays(30)->toIso8601String()]);

        $write->shouldReceive('createAllowRule')->once()->andReturn(['added_for' => [self::TENANT]]);
        $write->shouldReceive('findRuleByComment')->once()->andReturn(['id' => 'r1']);
        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');

        $record = MeshAllowRule::sole();
        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $record->state);

        $second = $this->decodedResult($this->callTool(
            $this->token(['mesh_add_allow_rule:staged']),
            'mesh_add_allow_rule',
            $this->stageArgs($fixture, ['expires_at' => 'never']),
        ));

        $this->assertTrue($second['idempotent']);
        $this->assertStringContainsString('already created recently', $second['message']);
        $this->assertStringContainsString('#'.$record->id, $second['message']);
        $this->assertStringContainsString('set to expire', $second['message']);
        $this->assertStringContainsString("state 'active'", $second['message']);
        $this->assertStringContainsString('NOT applied', $second['message']);
        $this->assertNotSame('never', $second['expires_at'], 'the answer reports the lifetime in force, never the one just asked for');
        $this->assertSame(1, TechnicianRun::count());
    }

    /**
     * The reaper writes STATE_REAPED only after a detail GET returned 404, so
     * the PSA has PROVED its recorded rule absent (that one id; b5c #5486).
     * STATE_REMOVED (an approved mesh_remove_allow_rule whose ruleAbsent()
     * read proved the same) takes the same refusal (#5410, #5492), so REAPED
     * is one of two proved-absent states here, not the only one.
     * Answering it success:true/idempotent:true put that certainty on the
     * machine-readable channel as an effect that does not exist, while only the
     * prose said otherwise — and the strictly LESS certain case (no PSA row at
     * all) was already refused. The certain case cannot be the greener one.
     */
    public function test_a_proved_absent_rule_is_refused_not_answered_as_an_idempotent_success(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        // Dated on purpose (#5161): STATE_REAPED is only reachable for a dated
        // row (scopeReapable() requires a non-null expires_at), so the reaped
        // record below must be one the reaper could actually have produced.
        // Two hours out (#5413): short enough that the reap below happens
        // inside the 24-hour post-execution window with nothing hand-written.
        $run = $this->stagedRun($fixture, ['expires_at' => now()->addHours(2)->toIso8601String()]);

        $write->shouldReceive('createAllowRule')->once()->andReturn(['added_for' => [self::TENANT]]);
        $write->shouldReceive('findRuleByComment')->once()->andReturn(['id' => 'r1']);
        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');

        $record = MeshAllowRule::sole();
        $this->assertFalse($record->isPermanent(), 'the fixture must be a dated row, the only kind the reaper reaps');

        // Reaped the way the reaper reaps: past its expiry, DELETE, then a
        // proved 404 on the detail read. Not a hand-written state.
        $write->shouldReceive('deleteRule')->once()->with('r1');
        $write->shouldReceive('ruleAbsent')->once()->with('r1')->andReturn(true);
        $this->travelTo($record->expires_at->copy()->addHour());
        $this->assertSame(1, app(MeshAllowRuleReaper::class)->reap()['reaped']);
        $record->refresh();
        $this->assertSame(MeshAllowRule::STATE_REAPED, $record->state);

        // The 24-hour post-execution dedup window is measured from the
        // 'executed' audit row. It was written at approval, three hours ago,
        // and is left as written (#5413): the re-stage is inside the window
        // because the rule lived two hours, not because a row was redated.
        $executed = TechnicianActionLog::query()
            ->where('action_type', 'mesh_add_allow_rule')
            ->where('result_status', 'executed')
            ->sole();
        $this->assertTrue($executed->created_at->between(now()->subHours(24), now()->subHours(2)), 'the executed row is the one approval wrote, inside the window');

        $second = $this->decodedResult($this->callTool(
            $this->token(['mesh_add_allow_rule:staged']),
            'mesh_add_allow_rule',
            $this->stageArgs($fixture, ['expires_at' => 'never']),
        ));

        // The machine-readable channel, which is the whole finding: no success,
        // no idempotent, and an error naming what is actually true.
        $this->assertArrayNotHasKey('success', $second);
        $this->assertArrayNotHasKey('idempotent', $second);
        $this->assertStringContainsString('#'.$record->id, $second['error']);
        // #5488: the REAPED arm's own proof, whole, so a swap or merge with
        // the REMOVED arm fails here; and it never names the remove verb.
        // #5486: the 404 proved ONE recorded id absent, so the text says
        // exactly that and never "NO allow is in force".
        $this->assertStringContainsString(
            'as PSA record #'.$record->id.", but the expiry job has since proved it absent upstream after its expiry (state 'reaped'). "
                .'That proves only that the rule this record tracked is gone; the PSA has not checked whether any other rule for this sender is in force on the tenant. Nothing was staged now.',
            $second['error'],
        );
        $this->assertStringNotContainsString('mesh_remove_allow_rule', $second['error']);
        $this->assertStringNotContainsString('NO allow is in force', $second['error']);

        // Nothing staged, and the refusal is on the audit trail as a refusal.
        $this->assertSame(1, TechnicianRun::count());
        $this->assertSame(1, TechnicianActionLog::query()
            ->where('action_type', 'mesh_stage_add_allow_rule')
            ->where('result_status', 'blocked')
            ->count());
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
            ->with(self::TENANT, 'billing@vendor.example', $comment, null)
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
        // Staged with no expires_at, so the rule is permanent (owner's
        // 2026-10-05 ruling): a NULL expiry, never a default date.
        $this->assertNull($record->expires_at);
        $this->assertTrue($record->isPermanent());

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

    /**
     * C-56 (card FLzMLDxF): a 400 is still a determinate refusal (no row, the
     * proposal stays approvable), but it is reported by its status: the
     * refusal text is the vendor's own response body and is not quoted.
     */
    public function test_a_400_from_mesh_is_reported_by_its_status_as_a_refusal(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $run = $this->stagedRun($fixture);

        $write->shouldReceive('createAllowRule')->once()
            ->andThrow(new MeshWriteRejectedException('Invalid sender — sender: reserved domain'));

        $this->actingAs($actor)->post(route('cockpit.approve', $run));

        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state, 'a refused create leaves the proposal approvable after correction');
        $this->assertSame(0, MeshAllowRule::count());
        $log = TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'rejected')->sole();
        $this->assertStringContainsString('Mesh refused the allow rule: Mesh answered the create with HTTP 400', $log->summary);
        $this->assertStringNotContainsString('reserved domain', $log->summary);
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

        // The rule ran its life — reaped, dedup window past.
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

    public function test_a_proposal_is_approvable_seconds_after_it_was_staged(): void
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

    /**
     * There is no per-client staging cooldown. A proposal is a held card a
     * human still has to approve, so a damper between two DISTINCT proposals
     * only delayed that human's decision — the shape that had a second allow
     * for the same client, on a second ticket, refused for a quarter of an
     * hour. Identical content is still answered by the dedup brakes, and that
     * is asserted alongside so the two cannot be confused.
     */
    public function test_distinct_senders_for_one_client_stage_back_to_back_without_a_cooldown(): void
    {
        $this->configureMesh();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWrite();
        $secondTicket = Ticket::factory()->for($fixture['client'])->create(['subject' => 'Second vendor quarantined']);

        $first = $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture)));
        $this->assertTrue($first['success']);

        $second = $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture, [
            'ticket_id' => $secondTicket->id,
            'sender' => 'invoices@othervendor.example',
            'confirm_domain' => 'othervendor.example',
            'reason' => 'A second vendor for the same client, staged seconds after the first.',
        ])));

        $this->assertArrayNotHasKey('error', $second, 'a distinct proposal for the same client must not wait on the first one');
        $this->assertTrue($second['success']);
        $this->assertArrayNotHasKey('idempotent', $second);
        $this->assertNotSame($first['run_id'], $second['run_id']);
        $this->assertSame(2, TechnicianRun::where('state', TechnicianRunState::AwaitingApproval->value)->count());
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'blocked')->count());

        // The dedup guard is untouched: the same content again is the live
        // proposal handed back, not a third card.
        $retry = $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture)));
        $this->assertTrue($retry['idempotent']);
        $this->assertSame($first['run_id'], $retry['run_id']);
        $this->assertSame(2, TechnicianRun::count());
    }

    /**
     * Same ruling at approve time: two distinct proposals for one client are
     * approved back-to-back, each reaching upstream, with no window between
     * them. The post-execution duplicate brake (alreadyExecuted) is a
     * different question and keeps its own tests.
     */
    public function test_distinct_proposals_for_one_client_are_approved_back_to_back_without_a_cooldown(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $secondTicket = Ticket::factory()->for($fixture['client'])->create(['subject' => 'Second vendor quarantined']);

        $first = $this->stagedRun($fixture);
        $this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture, [
            'ticket_id' => $secondTicket->id,
            'sender' => 'invoices@othervendor.example',
            'confirm_domain' => 'othervendor.example',
            'reason' => 'A second vendor for the same client, staged seconds after the first.',
        ]))->assertOk();
        $second = TechnicianRun::where('ticket_id', $secondTicket->id)->sole();

        $write->shouldReceive('createAllowRule')->twice()->andReturn(['added_for' => [self::TENANT]]);
        $write->shouldReceive('findRuleByComment')->twice()->andReturn(['id' => 'r1'], ['id' => 'r2']);

        $this->actingAs($actor)->post(route('cockpit.approve', $first))->assertSessionHas('success');
        $this->actingAs($actor)->post(route('cockpit.approve', $second))->assertSessionHas('success');

        $this->assertSame(TechnicianRunState::Done, $first->fresh()->state);
        $this->assertSame(TechnicianRunState::Done, $second->fresh()->state);
        $this->assertSame(2, MeshAllowRule::count());
        $this->assertSame(2, TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'executed')->count());
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'blocked')->count());
    }

    /**
     * Free approval is for DISTINCT writes. A fault on the same write is not
     * one: record_unwritable leaves the rule live upstream with no PSA row, so
     * liveAllowRule() and unsettledAllowRule() both see nothing and
     * alreadyExecuted() matches 'executed' alone. The fault's own audit row is
     * the only brake left, and a second card for the same sender must hit it
     * rather than open a second, untracked hole in this client's filtering.
     *
     * The executor has no seam to fail the mesh_allow_rules insert through, so
     * the STATE that path leaves — an 'executed_with_fault' row for this write
     * and no PSA record — is constructed here from a fault that does write one.
     */
    public function test_a_second_card_is_refused_after_a_fault_left_a_rule_live_and_untracked(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $secondTicket = Ticket::factory()->for($fixture['client'])->create(['subject' => 'Same vendor, raised twice']);

        $first = $this->stagedRun($fixture);
        // Same sender, second ticket: a genuinely second card, not the live
        // proposal handed back.
        $this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture, [
            'ticket_id' => $secondTicket->id,
        ]))->assertOk();
        $duplicate = TechnicianRun::where('ticket_id', $secondTicket->id)->sole();

        // The create lands upstream and the post-write bookkeeping faults.
        $write->shouldReceive('createAllowRule')->once()->andReturn(['added_for' => [self::TENANT]]);
        $write->shouldReceive('findRuleByComment')->once()->andReturn(null);
        $this->actingAs($actor)->post(route('cockpit.approve', $first))->assertSessionHas('error');
        $this->assertSame(0, TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'executed')->count());
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'executed_with_fault')->count());

        // ...and the PSA row never reached disk, as record_unwritable leaves
        // it. Nothing in mesh_allow_rules can speak for that rule now.
        MeshAllowRule::query()->delete();

        $write->shouldNotReceive('createAllowRule');

        $this->actingAs($actor)->post(route('cockpit.approve', $duplicate))->assertSessionHas('error');
        $this->assertStringContainsString('FAULTED', (string) session('error'));
        $this->assertSame(0, MeshAllowRule::count(), 'a second, untracked rule must not be created');
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'blocked')->count());
    }

    /**
     * The mirror of the test above, and the reason that brake is keyed on the
     * run rather than on 'a fault happened'. A fault that DID write a PSA row
     * is the row brakes' business, and it must not outlive the row: card A
     * faults on scope, the technician does exactly what the fault text asks and
     * the record is closed to 'removed', and card B — the corrected retry both
     * fault branches state must remain possible — is approved inside the same
     * 24-hour window and reaches Mesh. The audit row is immutable, so nothing
     * else could ever clear it.
     */
    public function test_a_corrected_retry_is_approved_after_a_scope_fault_record_was_closed(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $secondTicket = Ticket::factory()->for($fixture['client'])->create(['subject' => 'Same vendor, corrected']);

        $first = $this->stagedRun($fixture);
        // Same sender, second ticket: a genuinely second card, not the live
        // proposal handed back.
        $this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture, [
            'ticket_id' => $secondTicket->id,
        ]))->assertOk();
        $corrected = TechnicianRun::where('ticket_id', $secondTicket->id)->sole();

        // First approval: Mesh answers 201 with an `added_for` that is NOT this
        // tenant, which is the scope_unconfirmed fault — PSA record written
        // unresolved, audited 'executed_with_fault'. Second approval: a clean
        // create.
        $write->shouldReceive('createAllowRule')->twice()->andReturn(
            ['added_for' => ['99999999-8888-7777-6666-555555555555']],
            ['added_for' => [self::TENANT]],
        );
        // Only the clean create gets as far as the id re-read; the scope fault
        // returns before it.
        $write->shouldReceive('findRuleByComment')->once()->andReturn(['id' => 'r-corrected']);

        $this->actingAs($actor)->post(route('cockpit.approve', $first))->assertSessionHas('error');
        $faulted = MeshAllowRule::sole();
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $faulted->state);
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'executed_with_fault')->count());

        // The technician checks the portal, removes the wrongly-scoped rule and
        // the removal verb proves absence: the record is closed. It is now in
        // neither liveAllowRule()'s nor unsettledAllowRule()'s list, so the
        // fault's audit row is the only thing that could refuse the retry.
        $faulted->update(['state' => MeshAllowRule::STATE_REMOVED]);

        $this->actingAs($actor)->post(route('cockpit.approve', $corrected))->assertSessionHas('success');

        $this->assertSame(TechnicianRunState::Done, $corrected->fresh()->state);
        $this->assertSame(2, MeshAllowRule::count());
        $this->assertSame('r-corrected', MeshAllowRule::where('state', MeshAllowRule::STATE_ACTIVE)->sole()->mesh_rule_id);
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'executed')->count());
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'blocked')->count());
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

    /**
     * Owner's ruling 2026-10-05: a temporary rule is "an option, not a
     * default". An ABSENT expires_at is a permanent rule: NULL expiry, no date
     * sent upstream, PERMANENT in words on the approval card (stored and
     * rendered), and never touched by the reaper however long it lives.
     */
    public function test_an_omitted_expiry_creates_a_permanent_rule_the_card_names_and_the_reaper_never_removes(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $args = $this->stageArgs($fixture);
        $this->assertArrayNotHasKey('expires_at', $args, 'the case under test is the ABSENT key');
        $result = $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $args));
        $run = TechnicianRun::findOrFail($result['run_id']);
        $comment = $this->committedComment($run);

        $this->assertSame('never', $result['expires_at']);
        $this->assertStringContainsString('PERMANENTLY', $run->proposed_content);
        $this->assertStringContainsString('NO EXPIRY', $run->proposed_content);
        $this->assertStringContainsString('nothing removes it automatically', $run->proposed_content);
        $this->assertStringContainsString('mesh_remove_allow_rule', $run->proposed_content);
        $this->assertStringNotContainsString('until the PSA removes the rule on', $run->proposed_content);
        $this->assertSame('never', $run->proposed_meta['redacted_params']['expires_at']);
        $this->assertStringContainsString('PERMANENT', $run->proposed_meta['redacted_params']['expiry_note']);

        // The card a human actually reads says it, not only the stored text.
        $this->actingAs($actor)->get(route('cockpit.index'))->assertOk()
            ->assertSee('This weakens filtering for that sender PERMANENTLY: the rule has NO EXPIRY, so nothing removes it automatically; it stays until someone removes it (mesh_remove_allow_rule) or gives it a date (mesh_edit_allow_rule).', false);

        $write->shouldReceive('createAllowRule')->once()
            ->with(self::TENANT, 'billing@vendor.example', $comment, null)
            ->andReturn(['detail' => 'ok', 'added_for' => [self::TENANT]]);
        $write->shouldReceive('findRuleByComment')->once()->andReturn(['id' => 'rule-default', 'created_by' => 'owner@soundit.example']);

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');

        $record = MeshAllowRule::sole();
        $this->assertNull($record->expires_at, 'an omitted expiry is a NULL expiry, never a default date');
        $this->assertTrue($record->isPermanent());
        $this->assertStringContainsString('PERMANENT', TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'executed')->sole()->summary);

        // Two years on, the reaper still selects nothing and deletes nothing.
        $write->shouldNotReceive('deleteRule');
        $this->travel(2 * 365)->days();
        $counts = app(MeshAllowRuleReaper::class)->reap();
        $this->assertSame(0, $counts['reaped']);
        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $record->fresh()->state);
        $this->assertNull($record->fresh()->reaped_at);
    }

    /** The opt-in temporary rule: its date is the expiry, and the reaper removes it once that date passes. */
    public function test_a_dated_expiry_makes_a_temporary_rule_that_is_reaped_after_it(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();

        $chosen = now()->addDays(7)->startOfSecond();
        $run = $this->stagedRun($fixture, ['expires_at' => $chosen->toIso8601String()]);
        $this->assertStringNotContainsString('PERMANENT', $run->proposed_content);
        $this->assertStringContainsString('until the PSA removes the rule on '.$chosen->toDayDateTimeString(), $run->proposed_content);

        $write->shouldReceive('createAllowRule')->once()->andReturn(['detail' => 'ok', 'added_for' => [self::TENANT]]);
        $write->shouldReceive('findRuleByComment')->once()->andReturn(['id' => 'rule-temp', 'created_by' => 'owner@soundit.example']);
        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');

        $record = MeshAllowRule::sole();
        $this->assertSame($chosen->timestamp, $record->expires_at->timestamp);

        // Before the date: not reaped.
        $write->shouldNotReceive('deleteRule');
        $this->assertSame(0, app(MeshAllowRuleReaper::class)->reap()['reaped']);
        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $record->fresh()->state);

        // After it: reaped.
        $write = $this->mockWrite();
        $write->shouldReceive('deleteRule')->once()->with('rule-temp');
        $write->shouldReceive('ruleAbsent')->once()->with('rule-temp')->andReturn(true);
        $this->travelTo($chosen->copy()->addHour());
        $this->assertSame(1, app(MeshAllowRuleReaper::class)->reap()['reaped']);
        $this->assertSame(MeshAllowRule::STATE_REAPED, $record->fresh()->state);
    }

    /**
     * Omitted and 'never' resolve to the same permanent lifetime, so they are
     * one proposal: a retry that spells it the other way is "already staged",
     * never refused as "a different lifetime".
     */
    public function test_an_omitted_expiry_and_never_are_the_same_permanent_proposal(): void
    {
        $this->configureMesh();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $this->mockWrite();

        $first = $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture)));
        $retry = $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture, ['expires_at' => 'never'])));

        $this->assertTrue($retry['idempotent'] ?? false, 'expected an idempotent answer, got: '.json_encode($retry));
        $this->assertSame($first['run_id'], $retry['run_id']);
        $this->assertSame(1, TechnicianRun::count());

        // #5494: the other three channels the EXPIRY_NEVER docblock names,
        // read for the OMITTED key: the staging result, the redacted card
        // params and the encrypted payload all carry 'never'.
        $this->assertSame('never', $first['expires_at'] ?? null, json_encode($first));
        $run = TechnicianRun::findOrFail($first['run_id']);
        $this->assertSame('never', $run->proposed_meta['redacted_params']['expires_at'] ?? null);
        $payload = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($run->proposed_meta['encrypted_payload']), true);
        $this->assertSame('never', $payload['arguments']['expires_at'] ?? null);
    }

    /**
     * The advertised text matches the behaviour (G-14): permanent by default,
     * no 90-day lifetime anywhere. Both definitions are checked: a staged-only
     * token is served the stage alias's description under the public name, so
     * the published one alone would leave the direct definition unread.
     */
    public function test_the_advertised_text_says_permanent_by_default_and_names_no_90_day_lifetime(): void
    {
        $this->configureMesh();

        $published = collect($this->listTools($this->token(['mesh_add_allow_rule:staged'])))->keyBy('name')['mesh_add_allow_rule'];
        $definitions = collect(\App\Services\Mcp\StaffMeshAdminToolExecutor::definitions())->keyBy('name');
        $direct = $definitions['mesh_add_allow_rule'];
        $staged = $definitions['mesh_stage_add_allow_rule'];

        $texts = [
            'published description' => $published['description'],
            'published expires_at' => $published['inputSchema']['properties']['expires_at']['description'],
            'direct description' => $direct['description'],
            'staged description' => $staged['description'],
        ];
        foreach ($texts as $label => $text) {
            $this->assertStringNotContainsString('90', $text, $label);
            $this->assertStringNotContainsStringIgnoringCase('default lifetime', $text, $label);
            $this->assertStringContainsString('PERMANENT', $text, $label);
        }
        $this->assertStringContainsString('The rule is PERMANENT unless you give `expires_at`', $direct['description']);
        $this->assertStringContainsString('PERMANENTLY unless `expires_at` gives a date', $published['description']);
        $this->assertStringContainsString('Omit it, or give the word "never", for a PERMANENT rule', $texts['published expires_at']);
        $this->assertStringContainsString('TEMPORARY rule that the PSA removes after that moment', $texts['published expires_at']);
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

        // The approver reads the date THEY were given, not a default.
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
        $this->assertStringContainsString('nothing removes it automatically', $run->proposed_content);
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

        $response = $this->actingAs($actor)->post(route('cockpit.approve', $run));

        // And the approver reads WHY. A message-less gate_declined renders the
        // cockpit's generic fallback — #3901 replaced "the Technician declined
        // (it may be paused). Try again.", whose retry instruction sent the
        // approver clicking the same button, with a sentence that claims no
        // mechanism; either way it carries no reason, so this arm forwards one.
        $response->assertSessionHas('error');
        $this->assertStringContainsString('is in the past', (string) session('error'));
        $this->assertStringContainsString('stage a new proposal with a valid expiry', (string) session('error'));

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

        // Whatever this card says, the rule that exists is PERMANENT and
        // nothing removes it automatically. The approver must be told that, on the
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

        // Permanent, and long past the date a dated rule created at the same
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
        // The settle pass reads even for a row that already carries an id; no
        // rule matches here, so nothing is identified.
        $write->shouldReceive('findRulesByComment')->andReturn([]);
        $write->shouldNotReceive('deleteRule');

        $counts = app(MeshAllowRuleReaper::class)->reap();
        $this->assertSame(0, $counts['examined']);
        // The two unsettled rows are read and left unsettled (unresolved, and
        // reap_failed) — neither can be settled, so they stay counted and the
        // command stays loud. Only the row that was already active is silent.
        $this->assertSame(2, $counts['unresolved']);
        $this->artisan('mesh:reap-allow-rules')->assertFailed();
    }

    public function test_a_permanent_row_stuck_unresolved_is_identified_but_never_promoted_to_active(): void
    {
        $this->configureMesh();
        $record = $this->record(['expires_at' => null, 'mesh_rule_id' => null, 'state' => MeshAllowRule::STATE_UNRESOLVED]);
        $write = $this->mockWrite();
        // Called twice: every run reads, even once the row carries an id.
        $write->shouldReceive('findRulesByComment')->twice()->with(self::TENANT, 'billing@vendor.example', 'PSA allow ABCDEFGHIJ')->andReturn([['id' => 'late-id']]);
        // The caller asked for permanent. Identifying it is the whole point;
        // deleting it would be the PSA revoking a decision it was told to keep.
        $write->shouldNotReceive('deleteRule');

        $counts = app(MeshAllowRuleReaper::class)->reap();

        // Nothing was reaped and nothing was examined for reaping — settling is
        // not a reap, and the command's expired-rule counts must not say it is.
        $this->assertSame(0, $counts['examined']);
        $this->assertSame(0, $counts['reaped']);
        $this->assertSame(0, $counts['failed']);
        $this->assertSame('late-id', $record->fresh()->mesh_rule_id);
        $this->assertNull($record->fresh()->reaped_at);

        // The id is recovered; scope is NOT. ACTIVE is the record
        // liveAllowRule() answers "already allowed for this client" from, and
        // this row was never scope-proved — promoting it would refuse the
        // corrected retry for this sender forever.
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $record->fresh()->state);
        $this->assertStringContainsString('not scope evidence', (string) $record->fresh()->last_error);
        $this->assertSame(1, $counts['unresolved']);

        // A permanent rule whose scope was never proved must not go quiet.
        $this->artisan('mesh:reap-allow-rules')->assertFailed();
    }

    /**
     * The scope-fault and never-answered-create paths write the reason a row
     * never settled into last_error, and it is the only record that scope was
     * never proved. The daily identify pass must not overwrite it.
     */
    public function test_settling_a_permanent_row_never_overwrites_the_recorded_fault(): void
    {
        $this->configureMesh();
        $record = $this->record(['expires_at' => null, 'mesh_rule_id' => null, 'state' => MeshAllowRule::STATE_UNRESOLVED]);
        $record->forceFill(['last_error' => 'Mesh reported this rule was added for another tenant; check the Mesh portal and remove it by hand if the scope is wrong.'])->save();

        $write = $this->mockWrite();
        $write->shouldReceive('findRulesByComment')->once()->andReturn([['id' => 'late-id']]);
        $write->shouldNotReceive('deleteRule');

        app(MeshAllowRuleReaper::class)->reap();

        $this->assertSame('late-id', $record->fresh()->mesh_rule_id);
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $record->fresh()->state);
        $this->assertStringContainsString('another tenant', (string) $record->fresh()->last_error);
    }

    public function test_a_permanent_row_that_cannot_be_identified_stays_unresolved_and_loud(): void
    {
        $this->configureMesh();
        $record = $this->record(['expires_at' => null, 'mesh_rule_id' => null, 'state' => MeshAllowRule::STATE_UNRESOLVED]);
        $write = $this->mockWrite();
        $write->shouldReceive('findRulesByComment')->twice()->andReturn([]);
        $write->shouldNotReceive('deleteRule');

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $this->assertSame(1, $counts['unresolved']);
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $record->fresh()->state);
        $this->assertStringContainsString('PERMANENT', (string) $record->fresh()->last_error);

        // A rule that may be live and cannot be named must not go quiet.
        $this->artisan('mesh:reap-allow-rules')->assertFailed();
    }

    /**
     * A permanent row is unsettled for one of two different reasons, and only
     * one of them is unfixable. A create whose 201 PROVED scope and whose id
     * merely could not be re-read is missing nothing else, so recovering the id
     * settles it — and the duplicate brake stops refusing that sender. Keeping
     * such a row unresolved wedges the sender forever, with no PSA verb able to
     * clear it.
     */
    public function test_a_scope_proved_permanent_row_settles_once_its_id_is_recovered(): void
    {
        $this->configureMesh();
        $this->configureAiActor();
        $fixture = $this->fixture();
        $record = $this->record([
            'client_id' => $fixture['client']->id,
            'expires_at' => null,
            'mesh_rule_id' => null,
            'state' => MeshAllowRule::STATE_UNRESOLVED,
            'scope_proved' => true,
        ]);
        $write = $this->mockWrite();
        $write->shouldReceive('findRulesByComment')->once()->andReturn([['id' => 'late-id']]);
        // Permanent means permanent: settling is identification, never removal.
        $write->shouldNotReceive('deleteRule');

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $this->assertSame('late-id', $record->fresh()->mesh_rule_id);
        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $record->fresh()->state);
        $this->assertNull($record->fresh()->last_error);
        $this->assertNull($record->fresh()->reaped_at);

        // Nothing is left outstanding for a human, so the daily command must
        // not keep reporting a fault that has been resolved.
        $this->assertSame(0, $counts['unresolved']);
        $this->artisan('mesh:reap-allow-rules')->assertSuccessful();

        // ...and the sender is no longer wedged: the next proposal is answered
        // by the live-record brake as a duplicate, not refused forever.
        $result = $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', $this->stageArgs($fixture)));
        $this->assertTrue($result['idempotent']);
        $this->assertStringContainsString('already allowed for this client PERMANENTLY', $result['message']);
        $this->assertSame(0, TechnicianRun::count());
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
