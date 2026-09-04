<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Mcp\StaffTacticalAdminToolExecutor;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use App\Services\Technician\TechnicianApprovalService;
use App\Support\ControlDConfig;
use App\Support\McpConfig;
use App\Support\McpToolModes;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #1276 — tactical_set_client_custom_field.
 *
 * A CLIENT-scoped Tactical custom field is read by automation for every agent
 * under that client, and for a deployment field (controld_org_id, the first key)
 * the write IS the deploy trigger. Everything this suite asserts follows from
 * that: the verb is staged-only by construction, the upstream client must resolve
 * to EXACTLY ONE name match or the call is refused, an unconfigured field id is a
 * refusal rather than a default, and approval re-resolves both against live state.
 */
class TacticalSetClientCustomFieldTest extends TestCase
{
    use RefreshDatabase;

    private const FIELD_ID = 44;

    private function configureTactical(): void
    {
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'secret');
        Setting::setValue(ControlDConfig::TACTICAL_CLIENT_ORG_FIELD_SETTING, (string) self::FIELD_ID);
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
    private function fixture(?string $siteKey = 'Acme|Main'): array
    {
        $client = Client::factory()->create(['name' => 'Acme', 'tactical_site_id' => $siteKey]);
        $contact = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Client',
            'last_name' => 'Contact',
            'email' => 'client@example.test',
            'is_active' => true,
        ]);
        $ticket = Ticket::factory()->for($client)->create([
            'contact_id' => $contact->id,
            'subject' => 'Roll out Control D',
        ]);

        return compact('client', 'ticket');
    }

    /** @return array<int, array<string, mixed>> */
    private function upstreamClients(array $names = ['Acme']): array
    {
        $id = 7;

        return array_map(static function (string $name) use (&$id): array {
            return ['id' => $id++, 'name' => $name, 'sites' => [['id' => 90, 'name' => 'Main']]];
        }, $names);
    }

    /** @return array<string, mixed> */
    private function stageArgs(array $fixture, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'field_key' => 'controld_org_id',
            'value' => 'org-abc123',
            'reason' => 'Onboarding this client onto Control D filtering.',
        ], $overrides);
    }

    private function mockTactical(): Mockery\MockInterface
    {
        $client = Mockery::mock(TacticalClient::class);
        $this->app->instance(TacticalClient::class, $client);

        return $client;
    }

    private function grantedToken(): string
    {
        return $this->token(['tactical_set_client_custom_field:staged']);
    }

    // ---- surface / grant shape ------------------------------------------------

    public function test_the_verb_is_a_sensitive_grantable_staged_only_tactical_admin_tool(): void
    {
        $this->configureTactical();

        $groups = McpToolRegistry::groups();
        $this->assertContains('tactical_set_client_custom_field', array_column($groups['tactical_admin']['tools'], 'name'));
        $this->assertTrue($groups['tactical_admin']['sensitive']);
        $this->assertContains('tactical_set_client_custom_field', McpToolRegistry::allToolNames());

        // A legacy full-surface token must not inherit a fleet-wide write.
        $this->assertNotContains(
            'tactical_set_client_custom_field',
            array_column($this->listTools($this->legacyToken()), 'name'),
        );
        $this->assertSame(
            McpToolModes::MODE_STAGED,
            McpToolModes::defaultMode('tactical_set_client_custom_field'),
            'the verb must never default to an immediate lane',
        );

        $scoped = collect($this->listTools($this->grantedToken()))->keyBy('name');
        $this->assertArrayHasKey('tactical_set_client_custom_field', $scoped);
        $definition = $scoped['tactical_set_client_custom_field'];
        $this->assertContains('ticket_id', $definition['inputSchema']['required']);
        $this->assertContains('field_key', $definition['inputSchema']['required']);
        $this->assertContains('value', $definition['inputSchema']['required']);
        // A staged-only grant advertises the STAGED description, so that is the text
        // a caller actually reads: it has to carry the fan-out and the
        // re-resolve-at-approval rule, not just the canonical blurb.
        $this->assertStringContainsString('fleet-wide', $definition['description']);
        $this->assertStringContainsString('deploy trigger', $definition['description']);
        $this->assertStringContainsString('LIVE state', $definition['description']);

        $canonical = collect(StaffTacticalAdminToolExecutor::definitions())
            ->firstWhere('name', 'tactical_set_client_custom_field');
        $this->assertStringContainsString('EXACTLY ONE', $canonical['description']);
        // The dedup key excludes the value, so the description has to say what that
        // costs rather than let a caller discover it on a correction write.
        $this->assertStringContainsString('dedup key deliberately excludes the value', $canonical['description']);

        // The staged internal name is a dispatch alias, never an advertised tool.
        $this->assertNotContains('tactical_stage_set_client_custom_field', array_keys($scoped->all()));
        $this->assertSame(
            ['tactical_set_client_custom_field', McpToolModes::MODE_STAGED],
            McpToolModes::parseGrantEntry('tactical_stage_set_client_custom_field'),
        );
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
        $response = $this->callTool(
            $this->token(['tactical_set_client_custom_field']),
            'tactical_set_client_custom_field',
            $this->stageArgs($fixture),
        );

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('staged-only', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianRun::count());
    }

    // ---- fail-closed refusals -------------------------------------------------

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusalProvider(): array
    {
        return [
            'field_key is not on the client allowlist' => [
                ['args' => ['field_key' => 'comet_install_token']],
                'not an allowlisted PSA-owned Tactical CLIENT custom field',
            ],
            // The agent allowlist is a different id space; borrowing a key from it
            // must not resolve here just because that key exists somewhere.
            'an agent-model field key is not reachable through the client verb' => [
                ['args' => ['field_key' => 'servosity_one_url']],
                'not an allowlisted PSA-owned Tactical CLIENT custom field',
            ],
            'the field id is not configured' => [
                ['clear_field_id' => true],
                'is not configured',
            ],
            'the field id setting is not a positive integer' => [
                ['field_id_setting' => '0'],
                'is not configured',
            ],
            'value is blank' => [
                ['args' => ['value' => '   ']],
                'value is required',
            ],
            'the PSA client has no Tactical mapping' => [
                ['site_key' => null],
                'no Tactical site mapping',
            ],
            'a caller-supplied upstream id is refused outright' => [
                ['args' => ['tactical_client_id' => 7]],
                'upstream Tactical identifiers are not accepted',
            ],
            'reason is missing' => [
                ['unset' => ['reason']],
                'reason is required',
            ],
            'ticket_id is missing' => [
                ['unset' => ['ticket_id']],
                'ticket_id is required',
            ],
        ];
    }

    #[DataProvider('refusalProvider')]
    public function test_staging_fails_closed_before_any_upstream_read(array $case, string $expected): void
    {
        $this->configureTactical();
        $this->configureAiActor();

        if ($case['clear_field_id'] ?? false) {
            Setting::setValue(ControlDConfig::TACTICAL_CLIENT_ORG_FIELD_SETTING, '');
        }
        if (isset($case['field_id_setting'])) {
            Setting::setValue(ControlDConfig::TACTICAL_CLIENT_ORG_FIELD_SETTING, $case['field_id_setting']);
        }

        $fixture = $this->fixture(array_key_exists('site_key', $case) ? $case['site_key'] : 'Acme|Main');

        $client = $this->mockTactical();
        $client->shouldNotReceive('setClientCustomField');

        $args = $this->stageArgs($fixture, $case['args'] ?? []);
        foreach ($case['unset'] ?? [] as $key) {
            unset($args[$key]);
        }

        $response = $this->callTool($this->grantedToken(), 'tactical_set_client_custom_field', $args);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'), 'expected a refusal, got: '.(string) $response->json('result.content.0.text'));
        $this->assertStringContainsString($expected, (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianRun::count(), 'a refused write must not leave a staged proposal behind');
    }

    // ---- the exactly-one-match rule -------------------------------------------

    public function test_an_ambiguous_upstream_client_name_is_refused_and_names_both_candidates(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->fixture();

        // Upstream Client.name is unique=True, but PostgreSQL uniqueness is
        // CASE-SENSITIVE, so these two rows coexist happily and one
        // case-insensitive match hits both. First-match would pick by list order.
        // NEITHER reproduces the stored 'Acme' exactly, so the #1291 exact-case
        // preference cannot break the tie and the ambiguity refusal stands.
        $client = $this->mockTactical();
        $client->shouldReceive('getClients')->andReturn($this->upstreamClients(['ACME', 'acme']));
        $client->shouldNotReceive('setClientCustomField');

        $response = $this->callTool($this->grantedToken(), 'tactical_set_client_custom_field', $this->stageArgs($fixture));

        $this->assertTrue((bool) $response->json('result.isError'));
        $text = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('ambiguous', $text);
        $this->assertStringContainsString("'ACME' (#7)", $text);
        $this->assertStringContainsString("'acme' (#8)", $text);
        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_an_exact_case_match_wins_over_a_case_differing_sibling(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->fixture();

        // #1291(b): an MSP legitimately running both 'Acme' and 'ACME' used to be
        // unable to write to EITHER — every call refused as ambiguous, with no
        // override, including the one client the mapping names exactly. The stored
        // name pair is a byte copy of the upstream name, so the exact hit IS the
        // mapping and the case-differing sibling is merely near it.
        $client = $this->mockTactical();
        $client->shouldReceive('getClients')->andReturn($this->upstreamClients(['ACME', 'Acme']));

        $response = $this->callTool($this->grantedToken(), 'tactical_set_client_custom_field', $this->stageArgs($fixture));

        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);
        // Deliberately the SECOND entry upstream, so passing by list order fails.
        $this->assertStringContainsString("'Acme' (#8)", $run->proposed_content);
        $this->assertStringNotContainsString("'ACME' (#7)", $run->proposed_content);
    }

    public function test_a_missing_upstream_client_is_refused_rather_than_created(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->fixture();

        $client = $this->mockTactical();
        $client->shouldReceive('getClients')->andReturn($this->upstreamClients(['Globex']));
        $client->shouldNotReceive('setClientCustomField');

        $response = $this->callTool($this->grantedToken(), 'tactical_set_client_custom_field', $this->stageArgs($fixture));

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString("Tactical client 'Acme' was not found", (string) $response->json('result.content.0.text'));
    }

    public function test_an_unreadable_client_list_is_a_refusal_not_a_blind_write(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->fixture();

        $client = $this->mockTactical();
        $client->shouldReceive('getClients')->andThrow(new TacticalClientException('Tactical API error (HTTP 500)', 0, null, 500, null, false));
        $client->shouldNotReceive('setClientCustomField');

        $response = $this->callTool($this->grantedToken(), 'tactical_set_client_custom_field', $this->stageArgs($fixture));

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('Could not read Tactical clients', (string) $response->json('result.content.0.text'));
    }

    public function test_a_single_case_differing_match_still_resolves(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->fixture();

        // One match is one match: the guard is against AMBIGUITY, not against
        // case-insensitive matching, which is how the stored name pair has always
        // been resolved.
        $client = $this->mockTactical();
        $client->shouldReceive('getClients')->andReturn($this->upstreamClients(['ACME']));

        $response = $this->callTool($this->grantedToken(), 'tactical_set_client_custom_field', $this->stageArgs($fixture));

        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);
        $this->assertStringContainsString("'ACME' (#7)", $run->proposed_content);
    }

    // ---- the Control D master switch (#1290) ----------------------------------

    public function test_the_control_d_master_switch_refuses_the_write_before_any_upstream_read(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->fixture();

        // OFF=OFF. controld_org_id is the Control D DEPLOY TRIGGER: writing it
        // makes Tactical automation roll the agent across the whole fleet. With
        // PSA's own Control D module switched off, those devices would be deployed
        // into a client PSA will then neither sync nor report on.
        Setting::setValue('controld_enabled', '0');

        // getClients() is COUNTED rather than forbidden outright: a bare
        // shouldNotReceive() raises inside Mockery the moment the guard is absent,
        // and that exception cascades into every later test in the class, which
        // makes a red-check unreadable. Counting fails this test and only this one.
        $upstreamReads = 0;
        $client = $this->mockTactical();
        $client->shouldReceive('getClients')->andReturnUsing(function () use (&$upstreamReads): array {
            $upstreamReads++;

            return $this->upstreamClients();
        });
        $client->shouldNotReceive('setClientCustomField');

        $response = $this->callTool($this->grantedToken(), 'tactical_set_client_custom_field', $this->stageArgs($fixture));

        $this->assertTrue((bool) $response->json('result.isError'));
        $text = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('Control D integration is disabled', $text);
        $this->assertStringContainsString('controld_enabled', $text);
        $this->assertSame(0, $upstreamReads, 'the master switch must refuse before any upstream read');
        $this->assertSame(0, TechnicianRun::count(), 'a refused write must not leave a staged proposal behind');
    }

    public function test_the_master_switch_defaults_to_enabled_so_an_unset_setting_is_not_a_refusal(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->fixture();

        // configureTactical() never writes controld_enabled. The switch defaults to
        // '1' (ControlDConfig::isEnabled), so the guard must not turn every
        // instance that has not touched the setting into a refusal.
        $this->assertTrue(ControlDConfig::isEnabled());

        $client = $this->mockTactical();
        $client->shouldReceive('getClients')->andReturn($this->upstreamClients());

        $response = $this->callTool($this->grantedToken(), 'tactical_set_client_custom_field', $this->stageArgs($fixture));

        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
    }

    // ---- the staged happy path ------------------------------------------------

    public function test_staging_creates_an_awaiting_approval_run_and_writes_nothing(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->fixture();

        $client = $this->mockTactical();
        $client->shouldReceive('getClients')->andReturn($this->upstreamClients());
        $client->shouldNotReceive('setClientCustomField');

        $response = $this->callTool($this->grantedToken(), 'tactical_set_client_custom_field', $this->stageArgs($fixture));
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $result = $this->decodedResult($response);
        $this->assertTrue($result['success']);
        $this->assertSame('controld_org_id', $result['field_key']);

        $run = TechnicianRun::findOrFail($result['run_id']);
        $this->assertSame('tactical_stage_set_client_custom_field', $run->action_type);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame($fixture['ticket']->id, $run->ticket_id);
        $this->assertSame('tactical_set_client_custom_field', $run->proposed_meta['direct_tool']);

        // The approver has to be able to judge the write, so the proposal shows the
        // value and says what a client-scoped field costs.
        $this->assertStringContainsString('org-abc123', $run->proposed_content);
        $this->assertStringContainsString('fleet-wide', $run->proposed_content);

        // The value is not in the cockpit's redacted params, and neither the
        // upstream client id nor the field id is carried in the payload arguments:
        // approval re-resolves both.
        $this->assertSame('controld_org_id', $run->proposed_meta['redacted_params']['field_key']);
        $this->assertArrayNotHasKey('value', $run->proposed_meta['redacted_params']);
        $this->assertSame(10, $run->proposed_meta['redacted_params']['value_length']);

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_stage_set_client_custom_field',
            'result_status' => 'awaiting_approval',
            'client_id' => $fixture['client']->id,
        ]);
    }

    public function test_restaging_identical_content_while_awaiting_is_idempotent_with_a_real_run_id(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->fixture();

        $client = $this->mockTactical();
        $client->shouldReceive('getClients')->andReturn($this->upstreamClients());
        $client->shouldNotReceive('setClientCustomField');

        $token = $this->grantedToken();
        $first = $this->decodedResult($this->callTool($token, 'tactical_set_client_custom_field', $this->stageArgs($fixture)));
        $again = $this->decodedResult($this->callTool($token, 'tactical_set_client_custom_field', $this->stageArgs($fixture, [
            'reason' => 'Re-sent the identical staging call.',
        ])));

        $this->assertTrue($again['idempotent'] ?? false);
        $this->assertSame($first['run_id'], $again['run_id'], 'idempotent:true must never be paired with a null or drifting run_id');
        $this->assertSame(1, TechnicianRun::where('action_type', 'tactical_stage_set_client_custom_field')->count());
    }

    public function test_restaging_never_rewrites_a_live_proposal_and_names_the_stuck_run_instead(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $approver = User::factory()->create();
        $fixture = $this->fixture();

        // The proposal pins #7 and shows 'org-abc123'. 'Acme' is re-created upstream
        // as #99 inside the approval window, so approval refuses and RELEASES the run
        // back to AwaitingApproval, where an operator is still reading it. A caller
        // holding only the staging grant then re-stages the same ticket and field
        // with a different value: that must not land on the live run, or the operator
        // would approve a fleet-wide Control D deploy value no human ever read.
        $client = $this->mockTactical();
        $client->shouldReceive('getClients')->once()->andReturn($this->upstreamClients());
        $run = $this->stagedRun($fixture);

        $client->shouldReceive('getClients')->andReturn([
            ['id' => 99, 'name' => 'Acme', 'sites' => [['id' => 90, 'name' => 'Main']]],
        ]);
        $client->shouldNotReceive('setClientCustomField');

        $this->assertSame('gate_declined', app(TechnicianApprovalService::class)->approveStagedTacticalAdminAction($run, $approver->id)->status);

        $response = $this->callTool($this->grantedToken(), 'tactical_set_client_custom_field', $this->stageArgs($fixture, [
            'value' => 'org-evil',
        ]));

        // Refused — and the refusal names the stuck run and the cockpit deny that
        // clears it, rather than reporting an idempotent success on a proposal that
        // can never execute.
        $this->assertTrue((bool) $response->json('result.isError'));
        $text = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString("Run #{$run->id}", $text);
        $this->assertStringContainsString('cockpit', $text);

        // The live proposal is byte-for-byte what the operator read: same value, same
        // pinned target, same state, same run.
        $fresh = $run->fresh();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $fresh->state);
        $this->assertStringContainsString("'Acme' (#7)", (string) $fresh->proposed_content);
        $this->assertStringContainsString('org-abc123', (string) $fresh->proposed_content);
        $this->assertStringNotContainsString('org-evil', (string) $fresh->proposed_content);
        $this->assertSame(1, TechnicianRun::where('action_type', 'tactical_stage_set_client_custom_field')->count());

        // And it is still refused at approval: the re-stage did not hand it a fresh
        // pin to satisfy the witness with.
        $this->assertSame('gate_declined', app(TechnicianApprovalService::class)->approveStagedTacticalAdminAction($fresh, $approver->id)->status);
    }

    public function test_the_value_is_withheld_from_the_mcp_and_technician_audits(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->fixture();

        $client = $this->mockTactical();
        $client->shouldReceive('getClients')->andReturn($this->upstreamClients());

        $this->callTool($this->grantedToken(), 'tactical_set_client_custom_field', $this->stageArgs($fixture, [
            'value' => 'org-secret-value',
        ]));

        // The controller rewrites the call to the staged internal name before it
        // audits, so that is the row this write leaves. Redaction is keyed on the
        // ARGUMENT name with no per-tool gate, so it applies to the alias too.
        $arguments = McpAuditLog::where('tool_name', 'tactical_stage_set_client_custom_field')->latest('id')->value('arguments');
        $this->assertIsArray($arguments);
        $this->assertSame('[custom field value withheld]', $arguments['value']);
        $this->assertSame('controld_org_id', $arguments['field_key']);
        $this->assertStringNotContainsString('org-secret-value', json_encode(TechnicianActionLog::pluck('summary')->all()));
    }

    // ---- approval ------------------------------------------------------------

    private function stagedRun(array $fixture): TechnicianRun
    {
        $response = $this->callTool($this->grantedToken(), 'tactical_set_client_custom_field', $this->stageArgs($fixture));
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);
    }

    public function test_approval_resolves_the_client_again_and_writes_the_field(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $approver = User::factory()->create();
        $fixture = $this->fixture();

        $client = $this->mockTactical();
        // Once at staging, once at approval: the resolution is not trusted across
        // the window.
        $client->shouldReceive('getClients')->twice()->andReturn($this->upstreamClients());
        $client->shouldReceive('setClientCustomField')->once()->with(7, self::FIELD_ID, 'org-abc123')->andReturn([]);

        $run = $this->stagedRun($fixture);

        $result = app(TechnicianApprovalService::class)->approveStagedTacticalAdminAction($run, $approver->id);

        $this->assertSame('executed', $result->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_set_client_custom_field',
            'result_status' => 'executed',
            'client_id' => $fixture['client']->id,
            'approver_user_id' => $approver->id,
        ]);
    }

    public function test_approving_the_same_proposal_twice_makes_only_one_upstream_call(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $approver = User::factory()->create();
        $fixture = $this->fixture();

        $client = $this->mockTactical();
        $client->shouldReceive('getClients')->andReturn($this->upstreamClients());
        $client->shouldReceive('setClientCustomField')->once()->andReturn([]);

        $run = $this->stagedRun($fixture);

        $this->assertSame('executed', app(TechnicianApprovalService::class)->approveStagedTacticalAdminAction($run, $approver->id)->status);
        $this->assertSame('already_handled', app(TechnicianApprovalService::class)->approveStagedTacticalAdminAction($run->fresh(), $approver->id)->status);
    }

    /** @return array<string, array{0: string}> */
    public static function approvalRemeasureProvider(): array
    {
        return [
            // Two Tactical clients with case-differing names were created in the
            // approval window and neither reproduces the stored name exactly: the
            // target is no longer unambiguous and no exact match can break the tie.
            'the upstream client name became ambiguous' => ['ambiguous'],
            // The operator cleared or re-pointed the configured field id.
            'the configured field id was cleared' => ['field_id'],
            // The PSA mapping was removed.
            'the PSA client lost its Tactical mapping' => ['mapping'],
            // The operator switched the Control D module off after staging (#1290).
            // The approver's click must not execute on the state that existed when
            // the proposal was written.
            'the Control D master switch was turned off' => ['controld_disabled'],
            // The mapped client was re-created upstream (or an exactly-named
            // sibling appeared) in the approval window, so re-resolution SUCCEEDS
            // but names a different upstream client than the proposal showed the
            // approver. A clean answer is not agreement: the id pinned in the
            // proposal has to be the id being written to.
            'the resolved client is no longer the one that was staged' => ['retargeted'],
        ];
    }

    #[DataProvider('approvalRemeasureProvider')]
    public function test_approval_refuses_when_live_state_changed_after_staging(string $change): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $approver = User::factory()->create();
        $fixture = $this->fixture();

        $client = $this->mockTactical();
        $client->shouldReceive('getClients')->once()->andReturn($this->upstreamClients());
        $run = $this->stagedRun($fixture);

        match ($change) {
            'ambiguous' => $client->shouldReceive('getClients')->once()->andReturn($this->upstreamClients(['ACME', 'acme'])),
            'field_id' => Setting::setValue(ControlDConfig::TACTICAL_CLIENT_ORG_FIELD_SETTING, ''),
            'mapping' => $fixture['client']->update(['tactical_site_id' => null]),
            'controld_disabled' => Setting::setValue('controld_enabled', '0'),
            'retargeted' => $client->shouldReceive('getClients')->once()->andReturn([
                ['id' => 99, 'name' => 'Acme', 'sites' => [['id' => 90, 'name' => 'Main']]],
            ]),
        };
        $client->shouldNotReceive('setClientCustomField');

        $result = app(TechnicianApprovalService::class)->approveStagedTacticalAdminAction($run, $approver->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame(
            TechnicianRunState::AwaitingApproval,
            $run->fresh()->state,
            'a declined approval must release the claim, not consume the proposal',
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
