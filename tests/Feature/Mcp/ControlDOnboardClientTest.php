<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\ControlDOnboardingIntent;
use App\Models\McpToken;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ControlD\ControlDClient;
use App\Services\ControlD\ControlDOnboardingStaged;
use App\Services\ControlD\ControlDProvisioning;
use App\Services\Mcp\StaffControlDOnboardingToolExecutor;
use App\Support\ControlDConfig;
use App\Support\McpConfig;
use App\Support\McpToolModes;
use App\Support\McpToolRegistry;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * B4: the `controld_onboard_client` staged verb + the client-page button, the caller
 * for the dark B1–B3 services. Every vendor exchange is a Guzzle MockHandler on the
 * injected ControlDOnboardingStaged; Http::preventStrayRequests() guards the rest.
 */
class ControlDOnboardClientTest extends TestCase
{
    use RefreshDatabase;

    private array $history = [];

    /**
     * B3 refuses an ambient transaction (vendor calls must run at transaction level 0),
     * so this class cannot run inside RefreshDatabase's per-test transaction. Same
     * construction as ControlDOnboardingStagedTest: no wrapping transaction, and the
     * in-memory database is re-migrated for the next test.
     */
    public function beginDatabaseTransaction(): void
    {
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    // ── fixtures ────────────────────────────────────────────────────────────────

    private function configure(bool $toggle = true, bool $defaults = true): void
    {
        Setting::setValue('controld_enabled', '1');
        Setting::setEncrypted('controld_api_key', 'synthetic-key');
        Setting::setValue('controld_stats_endpoint', 'synthetic-region');
        foreach (['tactical_client_field_id' => '18', 'default_profile_id' => 'testprofile01', 'code_expiry_days' => '7',
            'code_device_limit_headroom' => '2', 'code_analytics_level' => '0', 'code_intercept_mode' => 'standard'] as $key => $value) {
            Setting::setValue('controld_'.$key, $defaults ? $value : '');
        }
        Setting::setValue(ControlDConfig::ONBOARDING_ENABLED_SETTING, $toggle ? '1' : '0');
    }

    private function aiActor(): User
    {
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        return $actor;
    }

    /** The agent's token: `ai_actor` true, the only kind that may stage this verb (B4.1). */
    private function token(array $tools = ['controld_onboard_client:staged'], string $label = 'opsbot', bool $aiActor = true): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: $label, aiActor: $aiActor);
    }

    /** A staff token that is NOT an ai_actor — a human's bearer. Granted the verb, still refused at staging (B4.1). */
    private function humanToken(): string
    {
        return $this->token(label: 'human-bearer', aiActor: false);
    }

    private function tokenId(string $label = 'opsbot'): int
    {
        return (int) McpToken::where('label', $label)->sole()->id;
    }

    /** @return array<string, mixed> */
    private function payload(TechnicianRun $run): array
    {
        return json_decode(Crypt::decryptString((string) $run->proposed_meta['encrypted_payload']), true);
    }

    private function rewritePayload(TechnicianRun $run, array $payload): void
    {
        $meta = $run->proposed_meta;
        $meta['encrypted_payload'] = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
        $run->forceFill(['proposed_meta' => $meta])->save();
    }

    private function callTool(string $token, string $name, array $arguments = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function listTools(string $token): array
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
        ])->json('result.tools') ?? [];
    }

    /** @return array<string, mixed> */
    private function decoded(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    /** @return array{client: Client, ticket: Ticket} */
    private function fixture(array $client = []): array
    {
        $client = Client::factory()->create(array_merge(['name' => 'Synthetic Organization', 'email' => 'synthetic@example.invalid'], $client));
        $ticket = Ticket::factory()->for($client)->create(['subject' => 'Onboard to Control D', 'status' => \App\Enums\TicketStatus::New->value]);

        return compact('client', 'ticket');
    }

    private function orgRow(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/organization.json')), true)['body']['organization'];
    }

    /** One GET sub_organizations row (producer-schema fixture) for $org, with $global as its parent_profile, or none. */
    private function listed(string $org = 'syntheticOrg01', ?string $global = 'testprofile01'): array
    {
        $row = json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/sub-organization.json')), true)['body']['sub_organizations'][0];
        $row['PK'] = $org;
        if ($global === null) {
            unset($row['parent_profile']);
        } else {
            $row['parent_profile']['PK'] = $global;
        }

        return $row;
    }

    private function ok(array $body): Response
    {
        return new Response(200, [], json_encode(['success' => true, 'body' => $body]));
    }

    /** Bind a ControlDOnboardingStaged whose vendor answers from $responses, recording history. */
    private function vendor(array $responses, string $credential = 'synthetic-key'): void
    {
        $this->history = [];
        $handler = new MockHandler([...$responses, ...array_fill(0, 8, new Response(503))]);
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($this->history));
        $transport = new ControlDClient(['api_key' => $credential, 'handler' => $stack]);
        $this->app->instance(ControlDOnboardingStaged::class, new ControlDOnboardingStaged($transport, new ControlDProvisioning($transport)));
    }

    /** @return array<int, Response> */
    private function codeResponses(string $org = 'testorg001'): array
    {
        $row = json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/provision.json')), true);
        $row['org'] = $org;
        $row['max'] = 2;
        $row['ts_exp'] = now()->getTimestamp() + 7 * 86400;

        return [
            $this->ok(['types' => ['os' => ['icons' => ['desktop-windows' => ['name' => 'Windows']]]]]),
            $this->ok(['profiles' => [['PK' => 'testprofile01']]]),
            $this->ok(['provision' => $row]),
            $this->ok(['provisions' => [$row]]),
        ];
    }

    private function stage(array $fixture, ?string $token = null): TechnicianRun
    {
        $response = $this->callTool($token ?? $this->token(), 'controld_onboard_client', [
            'client_id' => $fixture['client']->id, 'ticket_id' => $fixture['ticket']->id, 'reason' => 'new client', 'staged' => true,
        ]);
        $result = $this->decoded($response);
        $this->assertTrue($result['success'] ?? false, json_encode($result));

        return TechnicianRun::findOrFail($result['run_id']);
    }

    /** P7A74iGD (b): the plan's steps on a staged run. */
    private function steps(TechnicianRun $run): array
    {
        $this->assertSame('plan', $run->proposed_meta['redacted_params']['step']);

        return $run->proposed_meta['redacted_params']['steps'];
    }

    private function approve(TechnicianRun $run, User $approver): TestResponse
    {
        return $this->actingAs($approver)->post(route('cockpit.approve', $run));
    }

    // ── surface & gating ────────────────────────────────────────────────────────

    public function test_verb_is_registry_backed_held_only_and_explicit_grant_only(): void
    {
        $this->assertContains('controld_onboard_client', McpToolRegistry::allToolNames());
        $this->assertNotContains('controld_stage_onboard_client', McpToolRegistry::allToolNames());
        $this->assertSame('controld_onboard_client', McpToolModes::canonicalForAlias('controld_stage_onboard_client'));
        $this->assertTrue(McpToolModes::isHeldOnly('controld_onboard_client'));
        $this->assertSame(McpToolModes::MODE_STAGED, McpToolModes::defaultMode('controld_onboard_client'));
        $this->assertSame('controld', McpToolRegistry::integrationForToolName('controld_onboard_client'));
        [$name, $mode] = McpToolModes::parseGrantEntry('controld_onboard_client:immediate');
        $this->assertNull($mode, 'the :immediate grant must be rejected, not silently staged');

        // Legacy full-surface token never inherits it.
        $this->configure();
        $legacy = McpConfig::rotateStaffToken(allowedTools: null, label: 'legacy');
        $fixture = $this->fixture();
        $response = $this->callTool($legacy, 'controld_onboard_client', ['client_id' => $fixture['client']->id, 'ticket_id' => $fixture['ticket']->id, 'reason' => 'x', 'staged' => true]);
        $this->assertStringContainsString('Tool not allowed', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_verb_is_published_only_when_toggle_on_and_defaults_complete(): void
    {
        $token = $this->token();
        $this->configure(toggle: false);
        $this->assertNotContains('controld_onboard_client', array_column($this->listTools($token), 'name'));
        $this->configure(toggle: true, defaults: false);
        $this->assertNotContains('controld_onboard_client', array_column($this->listTools($token), 'name'));
        $this->configure();
        $this->assertContains('controld_onboard_client', array_column($this->listTools($token), 'name'));
    }

    /** RED CONTROL: toggle off → verb inert (no run, no vendor call), even with the grant. */
    public function test_verb_is_inert_with_the_toggle_off(): void
    {
        $this->configure(toggle: false);
        $fixture = $this->fixture();
        $response = $this->callTool($this->token(), 'controld_onboard_client', ['client_id' => $fixture['client']->id, 'ticket_id' => $fixture['ticket']->id, 'reason' => 'x', 'staged' => true]);
        // Unpublished tools are refused at the grant gate before dispatch.
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('not allowed', (string) $response->json('result.content.0.text'));
        // And the executor itself refuses if reached directly.
        $direct = app(StaffControlDOnboardingToolExecutor::class)->execute('controld_stage_onboard_client', ['ticket_id' => $fixture['ticket']->id, 'reason' => 'x'], $fixture['client']->id, 'test');
        $this->assertArrayHasKey('error', $direct, json_encode($direct));
        $this->assertStringContainsString('not enabled', $direct['error']);
        $this->assertSame(0, TechnicianRun::count());
        $this->assertSame(0, ControlDOnboardingIntent::count());
    }

    /** RED CONTROL: verb executes without staging → refused whatever mode; nothing created. */
    public function test_immediate_call_is_refused_and_nothing_is_created(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture();
        $this->vendor([]);
        $response = $this->callTool($this->token(), 'controld_onboard_client', ['client_id' => $fixture['client']->id, 'reason' => 'now', 'staged' => false]);
        $result = $this->decoded($response);
        // Staged-only grant downgrades to staged: that is the #1277 contract, and the
        // staged path then needs a ticket. Either way there is no immediate lane.
        $this->assertArrayHasKey('error', $result, json_encode($result));
        $this->assertSame(0, TechnicianRun::count());
        $this->assertSame(0, ControlDOnboardingIntent::count());
        $this->assertNull($fixture['client']->fresh()->controld_org_id);
        $this->assertCount(0, $this->history);

        // The canonical name reached directly (no alias, no downgrade) is refused by the executor.
        $executor = app(StaffControlDOnboardingToolExecutor::class);
        $direct = $executor->execute('controld_onboard_client', ['reason' => 'now'], $fixture['client']->id, 'test');
        $this->assertArrayHasKey('error', $direct, json_encode($direct));
        $this->assertStringContainsString('held-only', $direct['error']);
        $this->assertSame(0, TechnicianRun::count());
        $this->assertDatabaseHas('technician_action_logs', ['action_type' => 'controld_onboard_client', 'result_status' => 'rejected', 'client_id' => $fixture['client']->id]);
        $this->assertSame(0, ControlDOnboardingIntent::count());
    }

    public function test_caller_supplied_pin_prefix_icon_or_pk_is_refused_by_name(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture();
        foreach (['pin' => '1234', 'name_prefix' => 'ACME-', 'icon' => 'router', 'org_pk' => 'x', 'profile_id' => 'p'] as $key => $value) {
            $response = $this->callTool($this->token(), 'controld_onboard_client', ['client_id' => $fixture['client']->id, 'ticket_id' => $fixture['ticket']->id, 'reason' => 'x', 'staged' => true, $key => $value]);
            $result = $this->decoded($response);
            $this->assertArrayHasKey('error', $result, "{$key} must be refused: ".json_encode($result));
            $error = (string) $result['error'];
            $this->assertStringContainsString("refused: {$key}", $error);
            $this->assertStringNotContainsString('1234', $error);
        }
        $this->assertSame(0, TechnicianRun::count());
        $this->assertSame(0, TechnicianActionLog::where('summary', 'like', '%1234%')->count());
    }

    // ── step 1: organization ────────────────────────────────────────────────────

    public function test_unmapped_client_stages_the_organization_step_and_second_admin_approval_binds_the_mapping(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture();
        $run = $this->stage($fixture);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame(['plan', ['organization', 'code']], [$run->proposed_meta['redacted_params']['step'], $run->proposed_meta['redacted_params']['steps']]);
        $this->assertStringContainsString('1. Organization (writes at Control D)', $run->proposed_content);
        $this->assertStringContainsString('Synthetic Organization', $run->proposed_content);
        $this->assertSame(0, ControlDOnboardingIntent::count(), 'staging makes no intent and no vendor call');

        $this->travelTo(now()->startOfSecond());
        $this->vendor([$this->ok(['organization' => $this->orgRow()]), $this->ok(['sub_organizations' => [$this->listed()]]), ...$this->codeResponses('syntheticOrg01')]);
        $approver = User::factory()->admin()->create(['is_active' => true]);
        $this->approve($run, $approver);

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame('syntheticOrg01', $fixture['client']->fresh()->controld_org_id);
        $intent = ControlDOnboardingIntent::where('operation', 'organization')->sole();
        $this->assertSame(['organization', 'bound', $approver->id], [$intent->operation, $intent->state, (int) $intent->actor_id]);
        $this->assertSame('bound', ControlDOnboardingIntent::where('operation', 'code')->sole()->state, 'one approval ran both steps');
        $this->assertCount(6, $this->history);
        $this->assertSame('/organizations/suborg', $this->history[0]['request']->getUri()->getPath());
        parse_str((string) $this->history[0]['request']->getBody(), $sent);
        $this->assertSame(['name' => 'Synthetic Organization', 'contact_email' => 'synthetic@example.invalid', 'twofa_req' => '1', 'stats_endpoint' => 'synthetic-region', 'parent_profile' => 'testprofile01'], $sent);
        $this->assertDatabaseHas('technician_action_logs', ['action_type' => 'controld_stage_onboard_client', 'result_status' => 'executed', 'run_id' => $run->id, 'approver_user_id' => $approver->id]);
    }

    /** RED CONTROL: a second `organization` intent for an already-mapped client is never staged. */
    public function test_mapped_client_never_stages_a_second_organization_step(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'existingOrg01']);
        // Staging a mapped client reads the parent inventory (read-only) to order the steps.
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('existingOrg01')]])]);
        $run = $this->stage($fixture);
        $this->assertSame(['code'], $this->steps($run), 'a mapped client proposes the code step, never organization');

        // Even a proposal that WAS staged as organization refuses at approval once mapped.
        $unmapped = $this->fixture();
        $orgRun = $this->stage($unmapped);
        $this->assertSame(['organization', 'code'], $this->steps($orgRun));
        $unmapped['client']->forceFill(['controld_org_id' => 'racedOrg01'])->save();
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('racedOrg01')]])]);
        $response = $this->approve($orgRun, User::factory()->admin()->create(['is_active' => true]));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $orgRun->fresh()->state);
        $this->assertSame(0, ControlDOnboardingIntent::count(), 'drift is refused by the executor before any intent is staged');
        $this->assertCount(1, $this->history, 'only the read-only step-ordering GET; no write');
        $this->assertSame('GET', $this->history[0]['request']->getMethod());
        $this->assertSame('racedOrg01', $unmapped['client']->fresh()->controld_org_id);
        $this->assertSame(1, TechnicianActionLog::where('run_id', $orgRun->id)->where('result_status', 'blocked')->where('summary', 'like', "%the client now needs the steps 'code', not 'organization, code'%")->count());
        $this->assertStringContainsString('Deny this proposal and stage again', (string) session('error'));
    }

    // ── step 2: code ────────────────────────────────────────────────────────────

    public function test_mapped_client_stages_the_code_step_and_approval_stores_the_code_encrypted_without_leaking_it(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001')]])]);
        $run = $this->stage($fixture);
        $this->assertSame(['code'], $this->steps($run));
        $this->assertStringContainsString('1. Provisioning code (writes at Control D)', $run->proposed_content);
        $this->assertStringContainsString('No deactivation PIN and no hostname prefix', $run->proposed_content);

        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001')]]), ...$this->codeResponses()]);
        $approver = User::factory()->admin()->create(['is_active' => true]);
        $response = $this->approve($run, $approver);

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $client = $fixture['client']->fresh();
        $this->assertSame('0123456789abcdef0123456789abcdef', $client->controld_provisioning_code);
        $this->assertNull($client->controld_deactivation_pin);
        $intent = ControlDOnboardingIntent::sole();
        $this->assertSame(['code', 'bound', 'fixture001'], [$intent->operation, $intent->state, $intent->vendor_pk]);
        $this->assertCount(5, $this->history);
        $sent = json_decode((string) $this->history[3]['request']->getBody(), true);
        $this->assertSame('desktop-windows', $sent['icon']);
        $this->assertArrayNotHasKey('deactivation_pin', $sent);
        $this->assertArrayNotHasKey('name_prefix', $sent);
        $this->assertSame('testprofile01', $sent['profile_id']);

        // RED CONTROL: the code (and any PIN) never appears in an audit row, the run, or the session flash.
        $code = '0123456789abcdef0123456789abcdef';
        $this->assertSame(0, TechnicianActionLog::where('summary', 'like', "%{$code}%")->count());
        $this->assertStringNotContainsString($code, json_encode(TechnicianRun::findOrFail($run->id)->toArray()));
        $this->assertStringNotContainsString($code, json_encode(session()->all()));
        $this->assertStringNotContainsString($code, json_encode($intent->toArray()));
    }

    /** RED CONTROL: approver == stager → refused; nothing created. */
    public function test_the_stager_cannot_approve_their_own_proposal(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture();
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $this->actingAs($admin)->post(route('clients.controld.onboard', $fixture['client']), ['ticket_id' => $fixture['ticket']->id, 'reason' => 'new client'])
            ->assertRedirect()->assertSessionHas('success');
        $run = TechnicianRun::sole();
        $this->assertSame($admin->id, $run->proposed_meta['staged_by_user_id']);
        $this->assertNull($this->payload($run)['staged_by_token_id'] ?? null, 'the button lane is staged by a person, not a token');

        $this->vendor([]);
        $this->approve($run, $admin);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(0, ControlDOnboardingIntent::count());
        $this->assertCount(0, $this->history);
        $this->assertDatabaseHas('technician_action_logs', ['run_id' => $run->id, 'result_status' => 'blocked']);
        $this->assertStringContainsString('approver is the stager', TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'blocked')->value('summary'));

        // A different active Admin may approve it.
        $this->travelTo(now()->startOfSecond());
        $this->vendor([$this->ok(['organization' => $this->orgRow()]), $this->ok(['sub_organizations' => [$this->listed()]]), ...$this->codeResponses('syntheticOrg01')]);
        $this->approve($run, User::factory()->admin()->create(['is_active' => true]));
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame('syntheticOrg01', $fixture['client']->fresh()->controld_org_id);
    }

    // ── B4.1 (#2043): the token lane is bound to ai_actor ─────────────────────────────────

    /** RED CONTROL (B4.1 a): a granted staff token that is NOT an ai_actor may not stage the verb at all. */
    public function test_a_non_ai_actor_token_cannot_stage_the_verb(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture();
        $this->vendor([]);
        $token = $this->humanToken();
        $this->assertFalse(McpToken::where('label', 'human-bearer')->sole()->ai_actor);
        // B4.2 (#2056, diff:4/contract:4): publish/dispatch parity — the verb is NOT published to a
        // granted non-ai_actor token, because such a token can never stage it. Before B4.2 this
        // line asserted the opposite (published, then refused at dispatch).
        $this->assertNotContains('controld_onboard_client', array_column($this->listTools($token), 'name'));
        $this->assertContains('controld_onboard_client', array_column($this->listTools($this->token()), 'name'), 'the same grant on an ai_actor token is published');

        // B4.2 (#2056, diff:5): the CATALOG tools still classify by the GRANT. This token IS
        // granted the verb, so list_tool_surface / search_tools report `granted` — never
        // `available_ungranted` ("an operator token grant enables it"), which would send the
        // operator to re-grant what it already holds, and never absent, which `absent_means`
        // reads as "does not exist on this server". The lane is named by the refusal below.
        $surface = $this->decoded($this->callTool($token, 'list_tool_surface', []));
        $this->assertSame('granted', collect($surface['tools'] ?? [])->firstWhere('name', 'controld_onboard_client')['state'] ?? null, json_encode($surface['counts'] ?? []));
        $matches = $this->decoded($this->callTool($token, 'search_tools', ['query' => 'controld_onboard_client']))['matches'] ?? [];
        $this->assertSame('granted', collect($matches)->firstWhere('name', 'controld_onboard_client')['grant_state'] ?? null, json_encode($matches));

        $response = $this->callTool($token, 'controld_onboard_client', ['client_id' => $fixture['client']->id, 'ticket_id' => $fixture['ticket']->id, 'reason' => 'new client', 'staged' => true]);
        $result = $this->decoded($response);
        $this->assertArrayHasKey('error', $result, json_encode($result));
        $this->assertStringContainsString('ai_actor', $result['error']);
        $this->assertStringContainsString('client page', $result['error']);
        $this->assertStringNotContainsString('psa-mcp-', $result['error']);
        $this->assertSame(0, TechnicianRun::count(), 'nothing is staged');
        $this->assertSame(0, ControlDOnboardingIntent::count());
        $this->assertCount(0, $this->history);
        $log = TechnicianActionLog::where('action_type', 'controld_stage_onboard_client')->where('result_status', 'rejected')->where('client_id', $fixture['client']->id)->sole();
        $this->assertStringContainsString('not an ai_actor', $log->summary);
        $this->assertSame('mcp-staff:human-bearer', $log->actor_label);
        $this->assertStringNotContainsString('psa-mcp-', $log->summary);

        // The executor reached directly with no token at all (legacy / unknown caller) refuses the same way.
        $direct = app(StaffControlDOnboardingToolExecutor::class)->execute('controld_stage_onboard_client', ['ticket_id' => $fixture['ticket']->id, 'reason' => 'x'], $fixture['client']->id, 'test');
        $this->assertArrayHasKey('error', $direct, json_encode($direct));
        $this->assertStringContainsString('ai_actor', $direct['error']);
        $this->assertSame(0, TechnicianRun::count());
    }

    /**
     * RED CONTROL (B4.1 b): an ai_actor-staged run records the token id in the encrypted
     * payload and is approved by ONE active Admin (agent-stages / one-human-approves).
     * Before B4.1 the same call was accepted for the wrong reason — the payload carried
     * no stager at all — so this test asserts the binding, not merely the outcome.
     */
    public function test_an_ai_actor_staged_run_is_bound_to_its_token_and_approved_by_a_single_admin(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture();
        $run = $this->stage($fixture);
        $tokenId = $this->tokenId('opsbot');
        $this->assertTrue(McpToken::findOrFail($tokenId)->ai_actor);

        $payload = $this->payload($run);
        $this->assertSame($tokenId, $payload['staged_by_token_id'] ?? null, 'the staging token is bound into the encrypted payload: '.json_encode($payload));
        $this->assertNull($payload['staged_by_user_id']);
        $this->assertSame($tokenId, $run->proposed_meta['staged_by_token_id'] ?? null);
        $this->assertSame('mcp-staff:opsbot', $run->proposed_meta['drafted_by']);
        // Card tY39CHiq: the BARE token label, which withdraw_staged_action matches.
        $this->assertSame('opsbot', $run->proposed_meta['drafted_by_token'] ?? null);
        $this->assertStringNotContainsString('psa-mcp-', json_encode($run->proposed_meta));

        $this->travelTo(now()->startOfSecond());
        $this->vendor([$this->ok(['organization' => $this->orgRow()]), $this->ok(['sub_organizations' => [$this->listed()]]), ...$this->codeResponses('syntheticOrg01')]);
        $approver = User::factory()->admin()->create(['is_active' => true]);
        $this->approve($run, $approver);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame('syntheticOrg01', $fixture['client']->fresh()->controld_org_id);
        $this->assertSame(2, ControlDOnboardingIntent::count());
        $this->assertCount(6, $this->history);
    }

    /** RED CONTROL (B4.1 b, approval arm): a token-lane run whose stager is not (or no longer) an ai_actor token is refused at approval. */
    public function test_approval_refuses_a_token_lane_run_whose_stager_is_not_an_ai_actor_token(): void
    {
        $this->configure();
        $this->aiActor();
        $approver = User::factory()->admin()->create(['is_active' => true]);

        // (1) The token's trust was withdrawn after staging: ai_actor flipped to false.
        $fixture = $this->fixture();
        $run = $this->stage($fixture);
        McpToken::findOrFail($this->tokenId('opsbot'))->forceFill(['ai_actor' => false])->save();
        $this->vendor([]);
        $this->approve($run, $approver);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(0, ControlDOnboardingIntent::count());
        $this->assertCount(0, $this->history);
        $blocked = TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'blocked')->where('approver_user_id', $approver->id)->sole();
        $this->assertStringContainsString('not an ai_actor token', $blocked->summary);

        // (2) A token-lane payload carrying no token id at all (pre-B4.1 shape, or a legacy caller) is refused, never approved on one signature.
        $other = $this->fixture();
        $run2 = $this->stage($other, $this->token(label: 'opsbot-2'));
        $payload = $this->payload($run2);
        unset($payload['staged_by_token_id']);
        $this->rewritePayload($run2, $payload);
        $this->approve($run2, $approver);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run2->fresh()->state);
        $this->assertSame(0, ControlDOnboardingIntent::count());
        $this->assertCount(0, $this->history);
        $this->assertSame(1, TechnicianActionLog::where('run_id', $run2->id)->where('result_status', 'blocked')->where('summary', 'like', '%no staging token%')->count());

        // (3) A token id that names no token row (deleted) is refused: unknown is not trusted.
        $third = $this->fixture();
        $run3 = $this->stage($third, $this->token(label: 'opsbot-3'));
        McpToken::where('label', 'opsbot-3')->delete();
        $this->approve($run3, $approver);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run3->fresh()->state);
        $this->assertSame(0, ControlDOnboardingIntent::count());
        $this->assertCount(0, $this->history);
        $this->assertNull($third['client']->fresh()->controld_org_id);
    }

    /**
     * RED CONTROL (B4.1 b, approval arm): the withdrawal actions the operator is told to use —
     * revoke, pause, or remove the grant — stop a pending token-lane run. Approval re-reads the
     * row under the same liveness gate authentication applies and the same grant projection, so a
     * token that can no longer call the verb cannot carry the lane's single signature either.
     */
    public function test_approval_refuses_a_token_lane_run_whose_staging_token_is_revoked_paused_or_ungranted(): void
    {
        $this->configure();
        $this->aiActor();
        $approver = User::factory()->admin()->create(['is_active' => true]);
        $this->vendor([]);

        foreach (['revoked' => ['revoked_at' => now()], 'paused' => ['paused_at' => now()]] as $state => $attributes) {
            $fixture = $this->fixture();
            $run = $this->stage($fixture, $this->token(label: "opsbot-{$state}"));
            McpToken::where('label', "opsbot-{$state}")->sole()->forceFill($attributes)->save();
            $this->approve($run, $approver);
            $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state, "a {$state} staging token must not carry the token lane");
            $this->assertSame(0, ControlDOnboardingIntent::count());
            $this->assertCount(0, $this->history);
            $this->assertNull($fixture['client']->fresh()->controld_org_id);
            $blocked = TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'blocked')->where('approver_user_id', $approver->id)->sole();
            $this->assertStringContainsString("no longer active (state: {$state})", $blocked->summary);
            $this->assertStringNotContainsString('psa-mcp-', $blocked->summary);
        }

        // The grant withdrawn: the row is live and still ai_actor, but it could no longer call the verb.
        $ungranted = $this->fixture();
        $run = $this->stage($ungranted, $this->token(label: 'opsbot-ungranted'));
        McpToken::where('label', 'opsbot-ungranted')->sole()->forceFill(['tools' => []])->save();
        $this->approve($run, $approver);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(0, ControlDOnboardingIntent::count());
        $this->assertCount(0, $this->history);
        $this->assertNull($ungranted['client']->fresh()->controld_org_id);
        $this->assertSame(1, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'blocked')->where('summary', 'like', '%no longer granted controld_onboard_client%')->count());
    }

    // ── B4.2 (#2056): grant parity, publication parity, honest copy ────────────────────────────

    /**
     * RED CONTROL (B4.2, c1:v1:1): the approval-time grant re-read sees the SAME normalised
     * list authentication sees. A legacy comma-joined stored entry (the shape
     * McpToken::importLegacyBlob() can store — trim only, no split) authenticates and stages
     * because McpConfig splits it; before B4.2 the executor's re-read parsed the raw array and
     * refused approval with the misleading reason "no longer granted". Both sides now read
     * McpConfig::grantedTools(), so the run stages AND approves the same way.
     */
    public function test_a_legacy_comma_joined_grant_stages_and_approves_the_same_way(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture();
        $plain = $this->token(label: 'legacy-blob');
        // Overwrite the stored grant with ONE comma-joined element, exactly as importLegacyBlob() stores it.
        $row = McpToken::where('label', 'legacy-blob')->sole();
        $row->forceFill(['tools' => ['controld_onboard_client:staged,find_clients']])->save();
        $this->assertSame(['controld_onboard_client:staged,find_clients'], $row->fresh()->tools);
        $this->assertSame(['controld_onboard_client', 'find_clients'], McpConfig::grantedTools($row->fresh())['tools']);

        $this->assertContains('controld_onboard_client', array_column($this->listTools($plain), 'name'), 'authentication splits the legacy entry');
        $run = $this->stage($fixture, $plain);
        $this->assertSame((int) $row->id, $this->payload($run)['staged_by_token_id'] ?? null);

        $this->travelTo(now()->startOfSecond());
        $this->vendor([$this->ok(['organization' => $this->orgRow()]), $this->ok(['sub_organizations' => [$this->listed()]]), ...$this->codeResponses('syntheticOrg01')]);
        $approver = User::factory()->admin()->create(['is_active' => true]);
        $this->approve($run, $approver);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state, 'the approval re-read must split the legacy entry the way authentication does');
        $this->assertSame(0, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'blocked')->count());
        $this->assertSame('syntheticOrg01', $fixture['client']->fresh()->controld_org_id);
        $this->assertCount(6, $this->history);

        // The withdrawal arm still holds through the same helper: replace the grant with a different tool.
        $other = $this->fixture();
        $run2 = $this->stage($other, $plain);
        $row->fresh()->forceFill(['tools' => ['find_clients,find_staff']])->save();
        $this->vendor([]);
        $this->approve($run2, $approver);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run2->fresh()->state);
        $this->assertCount(0, $this->history);
        $this->assertSame(1, TechnicianActionLog::where('run_id', $run2->id)->where('result_status', 'blocked')->where('summary', 'like', '%no longer granted controld_onboard_client%')->count());
    }

    /**
     * RED CONTROL (B4.2, diff:1/context:2/contract:2): the token-page help and INSTALL tell the
     * truth about ai_actor — it is the token lane's staging authority for this verb, a single
     * Admin who controls the token and approves is the residual the operator accepts by
     * granting it, and the second-person guarantee belongs to the button lane.
     */
    public function test_token_page_help_and_install_tell_the_truth_about_ai_actor(): void
    {
        $this->token();
        $row = McpToken::where('label', 'opsbot')->sole();
        $page = $this->actingAs(User::factory()->admin()->create(['is_active' => true]))->get(route('settings.mcp-tokens.show', $row))->assertOk();
        $page->assertDontSee('grants no permissions');
        $page->assertSee('staging authority for <code>controld_onboard_client</code>', false);
        $page->assertSee('only an Admin-managed token with this flag may stage that verb');
        $page->assertSee('granting the verb to this token is accepting that');
        $page->assertSee('belongs to the client page button');

        // Hard-wrapped prose: compare on collapsed whitespace.
        $install = preg_replace('/\s+/', ' ', (string) file_get_contents(base_path('docs/INSTALL.md')));
        $this->assertStringNotContainsString('no person can stage and approve alone through a bearer token', $install);
        $this->assertStringContainsString('token-lane staging requires an Admin-managed ai_actor token', $install);
        $this->assertStringContainsString('the residual the operator accepts by granting the verb', $install);
        $this->assertStringContainsString('Deny and re-stage anything staged before B4.1 first.', $install, 'the pre-B4.1 drain step is documented');
        $this->assertStringContainsString('This is procedure, not a migration', $install);
    }

    /** RED CONTROL: non-admin stages (button) or approves → refused; nothing created. */
    public function test_non_admin_cannot_stage_from_the_button_or_approve(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture();
        $tech = User::factory()->tech()->create(['is_active' => true]);
        $this->actingAs($tech)->post(route('clients.controld.onboard', $fixture['client']), ['ticket_id' => $fixture['ticket']->id, 'reason' => 'x'])->assertForbidden();
        $this->assertSame(0, TechnicianRun::count());

        $run = $this->stage($fixture);
        // B4.1: this is an ai_actor-staged run (token bound in the payload); the approver still has to be an active Admin.
        $this->assertSame($this->tokenId('opsbot'), $this->payload($run)['staged_by_token_id'] ?? null);
        $this->vendor([]);
        $this->approve($run, $tech);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(0, ControlDOnboardingIntent::count(), 'the executor refuses before B3 is ever reached');
        $this->assertCount(0, $this->history);
        $this->assertSame(1, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'blocked')->where('summary', 'like', '%approver is not an active Admin%')->where('approver_user_id', $tech->id)->count());

        $inactive = User::factory()->admin()->create(['is_active' => false]);
        $this->approve($run, $inactive);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(0, ControlDOnboardingIntent::count());
        $this->assertSame(1, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'blocked')->where('summary', 'like', '%approver is not an active Admin%')->where('approver_user_id', $inactive->id)->count());
    }

    /** RED CONTROL: the button is not rendered with the toggle off (nor for a non-admin). */
    public function test_button_is_rendered_only_for_an_admin_with_the_toggle_on(): void
    {
        $fixture = $this->fixture();
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $tech = User::factory()->tech()->create(['is_active' => true]);

        $this->configure(toggle: false);
        $this->actingAs($admin)->get(route('clients.show', $fixture['client']))->assertOk()->assertDontSee('id="controld-onboarding"', false)->assertDontSee(route('clients.controld.onboard', $fixture['client']));
        $this->actingAs($admin)->post(route('clients.controld.onboard', $fixture['client']), ['ticket_id' => $fixture['ticket']->id, 'reason' => 'x'])->assertNotFound();

        $this->configure(toggle: true, defaults: false);
        $this->actingAs($admin)->get(route('clients.show', $fixture['client']))->assertOk()->assertDontSee('id="controld-onboarding"', false);

        $this->configure();
        $this->actingAs($tech)->get(route('clients.show', $fixture['client']))->assertOk()->assertDontSee('id="controld-onboarding"', false);
        $this->actingAs($admin)->get(route('clients.show', $fixture['client']))->assertOk()
            ->assertSee('id="controld-onboarding"', false)->assertSee('Stage the onboarding plan for approval')->assertSee(route('clients.controld.onboard', $fixture['client']));
    }

    public function test_button_stages_the_same_proposal_as_the_verb_and_is_idempotent(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture();
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $this->actingAs($admin)->post(route('clients.controld.onboard', $fixture['client']), ['ticket_id' => $fixture['ticket']->id, 'reason' => 'new client'])->assertRedirect();
        $run = TechnicianRun::sole();
        $this->assertSame('controld_stage_onboard_client', $run->action_type);
        $this->assertSame(['organization', 'code'], $this->steps($run));
        // Card tY39CHiq: a logged-in Admin is not a token, so no token label is invented.
        $this->assertArrayNotHasKey('drafted_by_token', $run->proposed_meta);

        // The verb finds the same live proposal rather than a second one.
        $again = $this->decoded($this->callTool($this->token(), 'controld_onboard_client', ['client_id' => $fixture['client']->id, 'ticket_id' => $fixture['ticket']->id, 'reason' => 'again', 'staged' => true]));
        $this->assertTrue($again['idempotent']);
        $this->assertSame($run->id, $again['run_id']);
        $this->assertSame(1, TechnicianRun::count());

        // Another client's ticket is refused.
        $other = $this->fixture();
        $this->actingAs($admin)->post(route('clients.controld.onboard', $fixture['client']), ['ticket_id' => $other['ticket']->id, 'reason' => 'x'])->assertSessionHasErrors('ticket_id');
    }

    public function test_vendor_readonly_rejection_is_terminal_and_names_the_fix_without_creating_anything(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture();
        $run = $this->stage($fixture);
        $this->vendor([new Response(403, [], file_get_contents(base_path('tests/Fixtures/ControlD/read-only-rejection.json')))]);
        $response = $this->approve($run, User::factory()->admin()->create(['is_active' => true]));
        // P7A74iGD (b): the run closes Done (no new state, Jeeves 10:51Z) and the result says it did not complete.
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertStringStartsWith("The onboarding run did not complete: the 'organization' step ended rejected.", (string) session('error'));
        $this->assertNull($fixture['client']->fresh()->controld_org_id);
        $intent = ControlDOnboardingIntent::sole();
        $this->assertSame(['rejected', 40301, null], [$intent->state, $intent->reason_code, $intent->active_client_id]);
        $this->assertStringContainsString('Read token', (string) session('error'));
        $this->assertDatabaseHas('technician_action_logs', ['run_id' => $run->id, 'result_status' => 'error']);
        // A fresh proposal is possible after the key is fixed.
        $this->assertNotNull($this->stage($fixture));
    }

    public function test_uncertain_outcome_is_terminal_never_rearmed_and_keeps_the_lock(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture();
        $run = $this->stage($fixture);
        $this->vendor([new Response(503, [], 'upstream')]);
        $response = $this->approve($run, User::factory()->admin()->create(['is_active' => true]));
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertStringStartsWith("The onboarding run did not complete: the 'organization' step ended uncertain.", (string) session('error'));
        $intent = ControlDOnboardingIntent::sole();
        $this->assertSame(['uncertain', $fixture['client']->id], [$intent->state, (int) $intent->active_client_id]);
        $this->assertStringContainsString('HARD FAULT', (string) session('error'));
        $this->assertStringContainsString('Do NOT re-approve', (string) session('error'));
        // A second proposal for this client is refused while the intent holds the lock.
        $refused = $this->decoded($this->callTool($this->token(), 'controld_onboard_client', ['client_id' => $fixture['client']->id, 'ticket_id' => $fixture['ticket']->id, 'reason' => 'retry', 'staged' => true]));
        $this->assertStringContainsString('already owns this client', $refused['error']);
    }

    public function test_client_without_a_contact_email_is_refused_at_staging_with_no_vendor_call(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['email' => null]);
        $result = $this->decoded($this->callTool($this->token(), 'controld_onboard_client', ['client_id' => $fixture['client']->id, 'ticket_id' => $fixture['ticket']->id, 'reason' => 'x', 'staged' => true]));
        $this->assertStringContainsString('contact email', $result['error']);
        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_toggle_cannot_be_turned_on_while_defaults_are_incomplete(): void
    {
        $this->configure(toggle: false, defaults: false);
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $this->actingAs($admin)->post(route('settings.integrations.toggle'), ['integration' => 'controld_onboarding', 'enabled' => '1'])->assertRedirect()->assertSessionHas('error');
        $this->assertFalse(ControlDConfig::isOnboardingEnabled());
        $this->configure(toggle: false);
        $this->actingAs($admin)->post(route('settings.integrations.toggle'), ['integration' => 'controld_onboarding', 'enabled' => '1'])->assertRedirect()->assertSessionHas('success');
        $this->assertTrue(ControlDConfig::isOnboardingEnabled());
        $this->actingAs($admin)->get(route('settings.integrations'))->assertOk()->assertSee('Onboarding enabled')->assertSee('id="controld_onboarding_enabled"', false);
        $this->actingAs($admin)->post(route('settings.integrations.toggle'), ['integration' => 'controld_onboarding'])->assertRedirect();
        $this->assertFalse(ControlDConfig::isOnboardingEnabled());
        $this->assertFalse(ControlDConfig::isOnboardingEnabled(), 'default is off');
    }

    // ── XULQ2iix: enforce-global-profile step and ordering ───────────────────────

    /** A mapped sub-org WITHOUT parent_profile proposes the global-profile step, not code; approval PUTs and reads back. */
    public function test_mapped_org_without_the_global_profile_proposes_and_executes_the_global_profile_step_first(): void
    {
        $this->travelTo(now()->startOfSecond()); // the mocked read-back ts_exp must equal the sent one
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001', null)]])]);
        $run = $this->stage($fixture);
        $this->assertSame(['global-profile', 'code'], $this->steps($run));
        $this->assertStringContainsString('1. Global profile (writes at Control D)', $run->proposed_content);
        $this->assertStringContainsString('If a different global profile is set when this runs, the step refuses and writes nothing. '.self::RACE."\n", $run->proposed_content);
        $this->assertStringNotContainsString('would be replaced', $run->proposed_content);
        $this->assertStringNotContainsString('no conditional', $run->proposed_content);
        $this->assertStringNotContainsString('never replaces', $run->proposed_content);
        $this->assertStringContainsString('testprofile01', $run->proposed_content);
        $this->assertSame(0, ControlDOnboardingIntent::count());

        $this->vendor([
            $this->ok(['sub_organizations' => [$this->listed('testorg001', null)]]), // approval re-derives the step
            $this->ok(['sub_organizations' => [$this->listed('testorg001', null)]]), // pre-admit check
            $this->ok(['organization' => ['PK' => 'testorg001']]),
            $this->ok(['sub_organizations' => [$this->listed('testorg001')]]),
            ...$this->codeResponses(),
        ]);
        $approver = User::factory()->admin()->create(['is_active' => true]);
        $this->approve($run, $approver);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame(['GET', 'GET', 'PUT', 'GET', 'GET', 'GET', 'POST', 'GET'], array_map(fn ($h) => $h['request']->getMethod(), $this->history), 'one approval: the global profile, then the code');
        $intent = ControlDOnboardingIntent::where('operation', 'global-profile')->sole();
        $this->assertSame(['global-profile', 'bound', null], [$intent->operation, $intent->state, $intent->active_client_id]);
        $this->assertDatabaseHas('technician_action_logs', ['run_id' => $run->id, 'result_status' => 'executed', 'approver_user_id' => $approver->id]);
        $this->assertSame(0, TechnicianActionLog::where('run_id', $run->id)->where('summary', 'like', '%vendor text%')->count());

        // Both steps ran on the one approval; the client is onboarded and has no further step.
        $this->assertSame('bound', ControlDOnboardingIntent::where('operation', 'code')->sole()->state);
        $this->vendor([]);
        $next = $this->decoded($this->callTool($this->token(), 'controld_onboard_client', ['client_id' => $fixture['client']->id, 'ticket_id' => $fixture['ticket']->id, 'reason' => 'next', 'staged' => true]));
        $this->assertStringContainsString('already onboarded', $next['error'] ?? '', json_encode($next));
    }

    /**
     * XULQ2iix ruling (a): the sub_organizations row carries the key PRESENT with JSON null
     * (the shape a 2026-10-09 production read observed for an org with no Global Profile).
     * Staging proposes the global-profile step, exactly as for an absent key, and never
     * the unreadable refusal.
     */
    public function test_a_present_null_parent_profile_proposes_the_global_profile_step_like_an_absent_one(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $row = array_merge($this->listed('testorg001'), ['parent_profile' => null]);
        $this->assertArrayHasKey('parent_profile', $row);
        $this->vendor([$this->ok(['sub_organizations' => [$row]])]);
        $run = $this->stage($fixture);
        $this->assertSame([StaffControlDOnboardingToolExecutor::STEP_GLOBAL_PROFILE, StaffControlDOnboardingToolExecutor::STEP_CODE], $this->steps($run));
        $this->assertStringContainsString('1. Global profile (writes at Control D)', $run->proposed_content);
        $this->assertStringNotContainsString(self::UNREADABLE_REASON, $run->proposed_content);
        $this->assertSame(['GET'], array_map(fn ($h) => $h['request']->getMethod(), $this->history));
        $this->assertSame(0, ControlDOnboardingIntent::count());
    }

    /**
     * XULQ2iix ruling 2026-10-09 18:3xZ (a), the run-732 shape: after the global-profile PUT,
     * Control D lists the org with parent_profile as a BARE string holding the configured PK
     * (synthetic here, G-13). Staging reads it as enforced and proposes the code step, never
     * the unreadable refusal.
     */
    public function test_a_bare_string_parent_profile_equal_to_the_setting_proposes_the_code_step(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $this->vendor([$this->ok(['sub_organizations' => [array_merge($this->listed('testorg001'), ['parent_profile' => 'testprofile01'])]])]);
        $run = $this->stage($fixture);
        $this->assertSame([StaffControlDOnboardingToolExecutor::STEP_CODE], $this->steps($run));
        $this->assertSame(['GET'], array_map(fn ($h) => $h['request']->getMethod(), $this->history));
    }

    private const DERIVATION_LABEL = '[StaffControlDOnboardingToolExecutor] The Control D global-profile state was not established at step derivation';

    private const MARKER = 'synthetic-vendor-body-marker';

    /**
     * #6424: every throw arm of the step-derivation read (globalProfileState()), with the fixed
     * text the #6408 warning logs for it (#6429: the read-only GET arms say so, never 'outcome
     * may be unknown') and how many requests the arm sends. A missing setting is not here: it
     * turns onboarding off (isOnboardingActive()), so the verb refuses before nextStep(); an
     * invalid one reaches it.
     *
     * @return array<string, array{string, string, int}>
     */
    public static function derivationFaultArms(): array
    {
        return [
            'setting-invalid' => ['setting-invalid', 'controld_default_profile_id is required to onboard.', 0],
            'vendor-unconfigured' => ['unconfigured', 'Control D is disabled or unconfigured.', 0],
            'transport-failure' => ['transport', 'Control D parent request failed (read-only GET).', 1],
            'vendor-503' => ['503', 'Control D parent response is unconfirmed (read-only GET).', 1],
            'org-not-listed' => ['unlisted', 'Control D organization is not uniquely listed in the parent inventory.', 1],
            'org-listed-twice' => ['twice', 'Control D organization is not uniquely listed in the parent inventory.', 1],
            'inventory-malformed' => ['malformed-inventory', 'Control D parent inventory is malformed.', 1],
            'inventory-row-malformed' => ['malformed-row', 'Control D parent inventory is malformed.', 1],
            'parent-profile-malformed' => ['malformed', 'Control D global profile field is malformed.', 1],
        ];
    }

    /** Arrange $arm for the next derivation read of $org; every vendor answer and the transport error carry MARKER. */
    private function applyDerivationFault(string $arm, string $org): void
    {
        $named = fn (array $row) => array_merge($row, ['name' => self::MARKER]);
        match ($arm) {
            'setting-invalid' => [Setting::setValue('controld_default_profile_id', 'not a valid pk!'), $this->vendor([])],
            'unconfigured' => $this->vendor([], ''),
            'transport' => $this->vendor([new \GuzzleHttp\Exception\ConnectException(self::MARKER.' connect failure', new \GuzzleHttp\Psr7\Request('GET', 'https://synthetic.invalid/x'))]),
            '503' => $this->vendor([new Response(503, [], json_encode(['success' => false, 'error' => ['message' => self::MARKER]]))]),
            'unlisted' => $this->vendor([$this->ok(['sub_organizations' => [$named($this->listed('otherorg01'))]])]),
            'twice' => $this->vendor([$this->ok(['sub_organizations' => [$named($this->listed($org)), $named($this->listed($org))]])]),
            'malformed-inventory' => $this->vendor([$this->ok(['sub_organizations' => ['note' => self::MARKER]])]),
            'malformed-row' => $this->vendor([$this->ok(['sub_organizations' => [['PK' => $org, 'note' => self::MARKER]]])]),
            'malformed' => $this->vendor([$this->ok(['sub_organizations' => [$named(array_merge($this->listed($org), ['parent_profile' => ['updated' => 1, 'name' => self::MARKER]]))]])]),
        };
    }

    /**
     * Replace the Log facade with a recorder of EVERY call: any level, the generic log()/write(),
     * and chains through channel()/stack() (each returns the recorder, so the chained call is
     * recorded too). A control that counts only matching calls cannot see an extra record.
     */
    private function recordLogs(): object
    {
        $recorder = new class
        {
            /** @var array<int, array{string, array<int, mixed>}> */
            public array $calls = [];

            public function __call(string $method, array $args): static
            {
                $this->calls[] = [$method, $args];

                return $this;
            }
        };
        Log::swap($recorder);

        return $recorder;
    }

    /** Exactly one log call in total: the #6408 warning, with this exact message and context, and no marker, key, profile PK or setting value anywhere. */
    private function assertOnlyTheDerivationWarning(object $logs, int $clientId, string $org, string $message): void
    {
        $this->assertCount(1, $logs->calls, json_encode($logs->calls));
        $this->assertSame(['warning', [self::DERIVATION_LABEL, ['client_id' => $clientId, 'org_pk' => $org, 'exception' => \App\Services\ControlD\ControlDClientException::class, 'message' => $message]]], $logs->calls[0]);
        $flat = json_encode($logs->calls);
        foreach ([self::MARKER, 'synthetic-key', 'testprofile01', 'not a valid pk!', 'outcome may be unknown', 'could not be read'] as $needle) {
            $this->assertStringNotContainsString($needle, $flat);
        }
    }

    /**
     * #6408, #6424, #6425, #6427, #6429 (C-56, G-14): each throw arm of the staging read is
     * logged exactly once, at warning, with ids, the class and the executor's fixed text;
     * no other log call of any kind. The refusal text is unchanged.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('derivationFaultArms')]
    public function test_unreadable_staging_read_is_logged_with_ids_class_and_message_only(string $arm, string $message, int $requests): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $token = $this->token();
        $this->applyDerivationFault($arm, 'testorg001');
        $logs = $this->recordLogs();
        $result = $this->decoded($this->callTool($token, 'controld_onboard_client', $this->stageArgs($fixture)));
        $this->assertSame(self::UNREADABLE_REFUSAL, $result['error'] ?? null, json_encode($result));
        $this->assertSame(0, TechnicianRun::count());
        $this->assertCount($requests, $this->history);
        $this->assertOnlyTheDerivationWarning($logs, $fixture['client']->id, 'testorg001', $message);
    }

    /**
     * #6424 / #6425: the same read re-run at approval. Each arm refuses with the approval text,
     * leaves the run awaiting approval with no intent and no write, and logs exactly one
     * sanitised warning.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('derivationFaultArms')]
    public function test_unreadable_approval_read_refuses_and_logs_one_sanitised_warning(string $arm, string $message, int $requests): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001')]])]);
        $run = $this->stage($fixture);
        $this->assertSame(['code'], $this->steps($run));
        $approver = User::factory()->admin()->create(['is_active' => true]);
        $this->applyDerivationFault($arm, 'testorg001');
        $logs = $this->recordLogs();
        $result = app(StaffControlDOnboardingToolExecutor::class)->approveStagedRun($run, $approver->id);
        $this->assertSame(['gate_declined', self::UNREADABLE_AT_APPROVAL], [$result->status, $result->message]);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(0, ControlDOnboardingIntent::count());
        $this->assertSame(array_fill(0, $requests, 'GET'), array_map(fn ($h) => $h['request']->getMethod(), $this->history), 'read-only, no POST');
        $this->assertOnlyTheDerivationWarning($logs, $fixture['client']->id, 'testorg001', $message);
    }

    /**
     * #6428: a 'code' proposal (staged while the org enforced the configured profile) whose
     * approval read sees parent_profile PRESENT with JSON null. Null is unset (ruling (a)),
     * so the client now needs the global-profile step: approval refuses as a state change,
     * creates no intent and sends no POST.
     */
    public function test_a_present_null_parent_profile_at_approval_refuses_a_code_proposal_as_a_state_change(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001')]])]);
        $run = $this->stage($fixture);
        $this->assertSame(['code'], $this->steps($run));
        $row = array_merge($this->listed('testorg001'), ['parent_profile' => null]);
        $this->assertArrayHasKey('parent_profile', $row);
        $this->vendor([$this->ok(['sub_organizations' => [$row]])]);
        $result = app(StaffControlDOnboardingToolExecutor::class)->approveStagedRun($run, User::factory()->admin()->create(['is_active' => true])->id);
        $this->assertSame('gate_declined', $result->status);
        $this->assertSame("The client's Control D state changed since this was staged (the client now needs the steps 'global-profile, code', not 'code'). Deny this proposal and stage again so the current plan is read and approved on its own card. Nothing was created.", $result->message);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(0, ControlDOnboardingIntent::count());
        $this->assertSame(['GET'], array_map(fn ($h) => $h['request']->getMethod(), $this->history), 'no POST /provision');
    }

    /** Fail closed: an unreadable/malformed parent inventory at staging proposes nothing (never the code step). */
    public function test_unreadable_parent_inventory_refuses_staging_for_a_mapped_client(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        // A present-null parent_profile is unset, not unreadable (XULQ2iix ruling (a)); an
        // object without a PK is still malformed and still refuses here.
        foreach ([new Response(503), $this->ok(['sub_organizations' => [$this->listed('otherorg01')]]), $this->ok(['sub_organizations' => [array_merge($this->listed('testorg001'), ['parent_profile' => ['updated' => 1, 'name' => 'x']])]])] as $answer) {
            $this->vendor([$answer]);
            $result = $this->decoded($this->callTool($this->token(), 'controld_onboard_client', ['client_id' => $fixture['client']->id, 'ticket_id' => $fixture['ticket']->id, 'reason' => 'x', 'staged' => true]));
            $this->assertSame(self::UNREADABLE_REFUSAL, $result['error'] ?? null, json_encode($result));
        }
        $this->assertSame(0, TechnicianRun::count());
    }

    /** A global-profile proposal approved after someone else enforced it is refused as drift, with no write. */
    public function test_global_profile_step_approved_after_enforcement_is_refused_as_drift(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001', null)]])]);
        $run = $this->stage($fixture);
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001')]])]);
        $this->approve($run, User::factory()->admin()->create(['is_active' => true]));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(['GET'], array_map(fn ($h) => $h['request']->getMethod(), $this->history));
        $this->assertSame(0, ControlDOnboardingIntent::count());
    }

    /** A released intent does not own the client: the next step can be proposed. A staged one still does. */
    public function test_released_intent_does_not_own_the_client_but_a_staged_one_does(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture();
        $intent = new ControlDOnboardingIntent;
        $intent->forceFill(['id' => (string) \Illuminate\Support\Str::uuid(), 'client_id' => $fixture['client']->id, 'actor_id' => 1,
            'active_client_id' => $fixture['client']->id, 'operation' => 'organization', 'state' => 'staged', 'phase' => 'preflight', 'payload' => []])->save();
        $refused = $this->decoded($this->callTool($this->token(), 'controld_onboard_client', ['client_id' => $fixture['client']->id, 'ticket_id' => $fixture['ticket']->id, 'reason' => 'x', 'staged' => true]));
        $this->assertStringContainsString('already owns this client', $refused['error'] ?? '');
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $this->actingAs($admin)->post(route('clients.controld.intent.release', [$fixture['client'], $intent->id]), ['reason' => 'never admitted'])->assertSessionHas('success');
        $this->assertSame('released', $intent->fresh()->state);
        $this->assertSame(['organization', 'code'], $this->steps($this->stage($fixture)));
    }

    public function test_client_page_offers_release_only_for_a_never_admitted_intent(): void
    {
        $this->configure();
        $fixture = $this->fixture();
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $make = function (string $state, string $phase) use ($fixture): ControlDOnboardingIntent {
            $intent = new ControlDOnboardingIntent;
            $intent->forceFill(['id' => (string) \Illuminate\Support\Str::uuid(), 'client_id' => $fixture['client']->id, 'actor_id' => 1,
                'active_client_id' => $state === 'staged' ? $fixture['client']->id : null, 'operation' => 'organization', 'state' => $state, 'phase' => $phase, 'payload' => []])->save();

            return $intent;
        };
        $posted = $make('uncertain', 'readback');
        $html = $this->actingAs($admin)->get(route('clients.show', $fixture['client']))->assertOk()->getContent();
        $this->assertStringNotContainsString('controld-intent-release', $html);
        $staged = $make('staged', 'preflight');
        $html = $this->actingAs($admin)->get(route('clients.show', $fixture['client']))->assertOk()->getContent();
        $this->assertStringContainsString(route('clients.controld.intent.release', [$fixture['client'], $staged->id]), $html);
        $this->assertStringNotContainsString(route('clients.controld.intent.release', [$fixture['client'], $posted->id]), $html);
    }

    /** #6234/#6242 (diff:9, contract-s1:9): a staged/preflight row whose lock is NULL is not offered (the list uses release()'s lock predicate). */
    public function test_client_page_does_not_offer_release_for_a_staged_intent_without_the_lock(): void
    {
        $this->configure();
        $fixture = $this->fixture();
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $lockless = new ControlDOnboardingIntent;
        $lockless->forceFill(['id' => (string) \Illuminate\Support\Str::uuid(), 'client_id' => $fixture['client']->id, 'actor_id' => 1,
            'active_client_id' => null, 'operation' => 'organization', 'state' => 'staged', 'phase' => 'preflight', 'payload' => []])->save();
        $html = $this->actingAs($admin)->get(route('clients.show', $fixture['client']))->assertOk()->getContent();
        $this->assertStringNotContainsString('controld-intent-release', $html);
        $this->assertStringNotContainsString(route('clients.controld.intent.release', [$fixture['client'], $lockless->id]), $html);
        // Positive control on the same page: a staged row that holds the lock is offered, with the text that says so.
        $held = new ControlDOnboardingIntent;
        $held->forceFill(['id' => (string) \Illuminate\Support\Str::uuid(), 'client_id' => $fixture['client']->id, 'actor_id' => 1,
            'active_client_id' => $fixture['client']->id, 'operation' => 'organization', 'state' => 'staged', 'phase' => 'preflight', 'payload' => []])->save();
        $html = $this->actingAs($admin)->get(route('clients.show', $fixture['client']))->assertOk()->getContent();
        $this->assertStringContainsString(route('clients.controld.intent.release', [$fixture['client'], $held->id]), $html);
        $this->assertStringNotContainsString(route('clients.controld.intent.release', [$fixture['client'], $lockless->id]), $html);
        $this->assertStringContainsString('It holds this client\'s onboarding lock.', $html);
    }

    // ── r2 (Jeeves 22:4xZ must-carry) ───────────────────────────────────────────

    private function stageArgs(array $fixture): array
    {
        return ['client_id' => $fixture['client']->id, 'ticket_id' => $fixture['ticket']->id, 'reason' => 'x', 'staged' => true];
    }

    /** Item 1: a sub-org that already enforces a DIFFERENT global profile gets NO proposal (neither global-profile nor code). */
    public function test_a_different_global_profile_refuses_staging_and_proposes_nothing(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001', 'otherprofile9')]])]);
        $result = $this->decoded($this->callTool($this->token(), 'controld_onboard_client', $this->stageArgs($fixture)));
        $this->assertSame(ControlDOnboardingStaged::DIFFERENT_PROFILE_REFUSAL, $result['error'] ?? null, json_encode($result));
        $this->assertStringNotContainsString('otherprofile9', $result['error']);
        $this->assertSame(0, TechnicianRun::count());
        $this->assertSame(['GET'], array_map(fn ($h) => $h['request']->getMethod(), $this->history));
    }

    /** Item 1 at approval: a different profile set after staging refuses the global-profile proposal with no write. */
    public function test_a_different_global_profile_set_after_staging_refuses_approval_without_a_write(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001', null)]])]);
        $run = $this->stage($fixture);
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001', 'otherprofile9')]])]);
        $this->approve($run, User::factory()->admin()->create(['is_active' => true]));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(['GET'], array_map(fn ($h) => $h['request']->getMethod(), $this->history));
        $this->assertSame(0, ControlDOnboardingIntent::count());
        // #6329: this arm does not say 'stage again'; staging again refuses the same way (pinned below).
        // XULQ2iix #6362 r2 contract:4: the lead-in is true whether Control D or the setting moved.
        $this->assertSame(self::DIFFERENT_AT_APPROVAL, (string) session('error'));
        $this->assertStringNotContainsString('state changed since this was staged', (string) session('error'));
        $this->assertStringNotContainsString('stage again so the current step', (string) session('error'));
        // The claim it makes instead: a fresh stage on the same live state is refused with DIFFERENT_PROFILE_REFUSAL.
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001', 'otherprofile9')]])]);
        $restaged = $this->decoded($this->callTool($this->token(), 'controld_onboard_client', $this->stageArgs($fixture)));
        $this->assertSame(ControlDOnboardingStaged::DIFFERENT_PROFILE_REFUSAL, $restaged['error'] ?? null, json_encode($restaged));
    }

    /**
     * XULQ2iix #6362 r2 contract:4: only the configured default changed after staging; the
     * organization still enforces the profile it enforced then. The DIFFERENT arm's text must not
     * say the client's Control D state changed, and is the same exact text as when Control D moved.
     */
    public function test_a_setting_only_change_after_staging_refuses_approval_with_the_same_true_text(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001')]])]);
        $run = $this->stage($fixture);
        $this->assertSame(['code'], $this->steps($run));
        Setting::setValue('controld_default_profile_id', 'testprofile02');
        // The organization is unchanged: it still enforces testprofile01.
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001')]])]);
        $this->approve($run, User::factory()->admin()->create(['is_active' => true]));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(['GET'], array_map(fn ($h) => $h['request']->getMethod(), $this->history), 'no write');
        $this->assertSame(0, ControlDOnboardingIntent::count());
        $this->assertSame(self::DIFFERENT_AT_APPROVAL, (string) session('error'));
        $this->assertStringNotContainsString('state changed since this was staged', (string) session('error'));
        // #6391: the text's claim on this arm too: staging again on the same live state is refused the same way.
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001')]])]);
        $restaged = $this->decoded($this->callTool($this->token(), 'controld_onboard_client', $this->stageArgs($fixture)));
        $this->assertSame(ControlDOnboardingStaged::DIFFERENT_PROFILE_REFUSAL, $restaged['error'] ?? null, json_encode($restaged));
        $this->assertSame(1, TechnicianRun::count(), 'no second proposal');
    }

    /** #6249 r3 context:2: a different profile first seen by execute()'s pre-admission read is refused with the whole text, flash and audit. */
    public function test_a_different_global_profile_seen_at_execution_refuses_with_the_whole_text(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001', null)]])]);
        $run = $this->stage($fixture);
        // Approval GET: absent. Pre-admit GET in execute(): a different profile.
        $this->vendor([
            $this->ok(['sub_organizations' => [$this->listed('testorg001', null)]]),
            $this->ok(['sub_organizations' => [$this->listed('testorg001', 'otherprofile9')]]),
        ]);
        $this->approve($run, User::factory()->admin()->create(['is_active' => true]));
        $intent = ControlDOnboardingIntent::sole();
        $this->assertSame(['staged', $fixture['client']->id], [$intent->state, (int) $intent->active_client_id]);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame('Control D onboarding was refused before any vendor write: '.ControlDOnboardingStaged::DIFFERENT_PROFILE_REFUSAL." Intent {$intent->id} remains staged and holds this client's onboarding lock; an Admin can release it from the Control D onboarding card on the client page (shown while client onboarding is enabled) before another proposal.", (string) session('error'));
        $this->assertSame(1, TechnicianActionLog::where('run_id', $run->id)->where('summary', 'like', '%would not be detected. Intent %')->count(), 'the audit row carries the whole refusal');
        $this->assertSame(['GET', 'GET'], array_map(fn ($h) => $h['request']->getMethod(), $this->history), 'no write');
    }

    /** Item 2: the global-profile proposal pins org and profile; approval refuses when the setting moved since staging. */
    public function test_global_profile_proposal_pins_org_and_profile_and_approval_refuses_drift(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001', null)]])]);
        $run = $this->stage($fixture);
        $this->assertSame(['testorg001', 'testprofile01'], [$this->payload($run)['org_pk'] ?? null, $this->payload($run)['profile_pk'] ?? null]);
        Setting::setValue('controld_default_profile_id', 'testprofile02');
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001', null)]])]);
        $this->approve($run, User::factory()->admin()->create(['is_active' => true]));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(0, ControlDOnboardingIntent::count());
        $this->assertSame(['GET'], array_map(fn ($h) => $h['request']->getMethod(), $this->history), 'no write');
        // #6393: only the configured setting moved; the text does not say Control D changed.
        $this->assertSame("The values pinned on this card are out of date (the client's Control D organization or the configured global profile no longer matches the values on this card). Deny this proposal and stage again so the current values are read and approved on a new card. Nothing was created.", (string) session('error'));
        $this->assertStringNotContainsString('state changed since this was staged', (string) session('error'));
    }

    /** Item 2: a tampered payload pin (org not the client's) refuses at approval with no write. */
    public function test_global_profile_approval_refuses_a_payload_pin_that_is_not_the_live_org(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001', null)]])]);
        $run = $this->stage($fixture);
        $this->rewritePayload($run, ['org_pk' => 'otherorg002'] + $this->payload($run));
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001', null)]])]);
        $this->approve($run, User::factory()->admin()->create(['is_active' => true]));
        $this->assertSame(0, ControlDOnboardingIntent::count());
        $this->assertSame(['GET'], array_map(fn ($h) => $h['request']->getMethod(), $this->history));
        // #6393: the same exact text on the tampered-pin arm.
        $this->assertSame("The values pinned on this card are out of date (the client's Control D organization or the configured global profile no longer matches the values on this card). Deny this proposal and stage again so the current values are read and approved on a new card. Nothing was created.", (string) session('error'));
        $this->assertStringNotContainsString('state changed since this was staged', (string) session('error'));
    }

    /** Item 9 (diff:7, contract-s1:5): an unreadable inventory at approval refuses fail-closed and is not called a state change. */
    public function test_unreadable_inventory_at_approval_refuses_and_is_not_called_a_state_change(): void
    {
        $this->configure();
        $this->aiActor();
        foreach (['503' => new Response(503), 'listed-twice' => null, 'malformed' => $this->ok(['sub_organizations' => (object) []])] as $case => $answer) {
            $fixture = $this->fixture(['controld_org_id' => 'testorg'.substr(md5($case), 0, 4)]);
            $org = $fixture['client']->controld_org_id;
            $this->vendor([$this->ok(['sub_organizations' => [$this->listed($org)]])]);
            $run = $this->stage($fixture);
            $this->assertSame(['code'], $this->steps($run));
            $this->vendor([$answer ?? $this->ok(['sub_organizations' => [$this->listed($org), $this->listed($org)]])]);
            $this->approve($run, User::factory()->admin()->create(['is_active' => true]));
            $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state, $case);
            $this->assertSame(0, ControlDOnboardingIntent::count(), $case);
            $this->assertCount(1, $this->history, "{$case}: only the read-only GET");
            $error = (string) session('error');
            $this->assertSame(self::UNREADABLE_AT_APPROVAL, $error, $case);
            $this->assertStringNotContainsString('nothing was staged', $error, $case);
            $this->assertStringNotContainsString('state changed', $error, $case);
            $this->assertStringNotContainsString('once Control D answers', $error, $case);
        }
    }

    /** #6249 r3 context:3: the owned and already-onboarded refusals at approval omit 'nothing was staged' (the proposal stays staged). */
    public function test_owned_and_onboarded_refusals_at_approval_do_not_say_nothing_was_staged(): void
    {
        $this->configure();
        $this->aiActor();
        $changes = [
            'owned' => [self::OWNED_REASON, function (Client $client): void {
                $intent = new ControlDOnboardingIntent;
                $intent->forceFill(['id' => (string) \Illuminate\Support\Str::uuid(), 'client_id' => $client->id, 'actor_id' => User::factory()->admin()->create(['is_active' => true])->id,
                    'active_client_id' => $client->id, 'operation' => 'code', 'state' => 'staged', 'phase' => 'preflight', 'payload' => []])->save();
            }],
            'onboarded' => [self::ONBOARDED_REASON, function (Client $client): void {
                $client->forceFill(['controld_provisioning_code' => 'synthetic-code'])->save();
            }],
        ];
        foreach ($changes as $case => [$reason, $change]) {
            $fixture = $this->fixture(['controld_org_id' => 'testorg'.substr(md5($case), 0, 4)]);
            $org = $fixture['client']->controld_org_id;
            $this->vendor([$this->ok(['sub_organizations' => [$this->listed($org)]])]);
            $run = $this->stage($fixture);
            $this->assertSame(['code'], $this->steps($run), $case);
            $change($fixture['client']);
            $this->vendor([]);
            $this->approve($run, User::factory()->admin()->create(['is_active' => true]));
            $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state, $case);
            $error = (string) session('error');
            $this->assertSame("The client's Control D state changed since this was staged ({$reason}). Deny this proposal and stage again so the current plan is read and approved on its own card. Nothing was created.", $error, $case);
            $this->assertStringNotContainsString('nothing was staged', $error, $case);
            $this->assertSame(0, TechnicianActionLog::where('run_id', $run->id)->where('summary', 'like', '%nothing was staged%')->count(), $case);
            $this->assertCount(0, $this->history, "{$case}: no vendor request");
        }
    }

    private const OWNED_REASON = 'A Control D onboarding intent already owns this client (staged, posted or uncertain). A never-admitted (staged) intent can be released by an Admin from the Control D onboarding card on the client page while client onboarding is enabled; a posted or uncertain one needs manual reconciliation before the next step is proposed';

    private const ONBOARDED_REASON = 'This client is already onboarded: it is mapped to a Control D organization and carries a provisioning code. Re-cutting a code is a separate, explicit verb';

    /** #6232 (diff:7): exact text, true whether globalProfileState() threw before or after its GET. */
    private const UNREADABLE_REFUSAL = 'Could not confirm whether the Control D global profile is enforced on this client\'s organization (the configured global profile setting is missing or invalid, Control D is disabled or unconfigured, or the parent organization list could not be read or did not list this organization exactly once with a well-formed global profile); nothing was staged.';

    /** #6249 r2 diff:2/contract:2: the approval arm embeds the reason WITHOUT 'nothing was staged' (the proposal is still staged). */
    private const UNREADABLE_REASON = 'Could not confirm whether the Control D global profile is enforced on this client\'s organization (the configured global profile setting is missing or invalid, Control D is disabled or unconfigured, or the parent organization list could not be read or did not list this organization exactly once with a well-formed global profile)';

    private const UNREADABLE_AT_APPROVAL = 'Control D onboarding approval could not re-check the client\'s current step ('.self::UNREADABLE_REASON.'). Nothing was created. Approving again helps only if the cause was temporary; otherwise fix it or deny this proposal.';

    /** #6249 r2 context:1/diff:5: the race disclosure, exact. It claims nothing about what Control D does with the update. */
    private const DIFFERENT_AT_APPROVAL = "This organization's global profile does not match the configured profile (".ControlDOnboardingStaged::DIFFERENT_PROFILE_REFUSAL.'). This proposal cannot be approved, and staging again is refused the same way, while this organization enforces a global profile other than the configured one. Nothing was created.';

    private const RACE = 'The global-profile step sends an unconditional update, so a global profile set after the last read before that update is not detected and the update may replace it. Only a refusal in the Control D error envelope (an HTTP 4xx whose body reports success false with an integer error code) ends the step rejected; any other refusal, an unknown outcome, or a read-back that does not show the configured profile ends it uncertain. If that outcome cannot be recorded, or the process stops after the step is admitted for its update and before its outcome is recorded, the step stays posted instead. Posted and uncertain steps are never retried and keep this client locked until reconciled by hand.';

    /** Item 8 (contract-s1:6): a pre-admission refusal after a read says 'before any vendor write', never 'vendor call'. */
    public function test_pre_admission_refusal_after_a_read_says_vendor_write_and_names_admin_release(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001', null)]])]);
        $run = $this->stage($fixture);
        // Approval GET sees absent; the pre-admit GET in execute() is unreadable.
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001', null)]]), new Response(503)]);
        $this->approve($run, User::factory()->admin()->create(['is_active' => true]));
        $error = (string) session('error');
        $this->assertStringContainsString('was refused before any vendor write', $error);
        $this->assertStringNotContainsString('before any vendor call', $error);
        $this->assertStringContainsString('an Admin can release it from the Control D onboarding card on the client page', $error);
        $this->assertSame('staged', ControlDOnboardingIntent::sole()->state);
        $this->assertSame(['GET', 'GET'], array_map(fn ($h) => $h['request']->getMethod(), $this->history));
        // The staged-intent refusal at the next staging names Admin release too.
        $refused = $this->decoded($this->callTool($this->token(), 'controld_onboard_client', $this->stageArgs($fixture)));
        $this->assertStringContainsString('can be released by an Admin from the Control D onboarding card', $refused['error'] ?? '');
    }

    /** Item 8 (contract-s2:2): the HARD FAULT on the global-profile arm says PUT and org PK, not POST/vendor PK. */
    public function test_global_profile_hard_fault_says_put_and_org_pk(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001', null)]])]);
        $run = $this->stage($fixture);
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001', null)]]), $this->ok(['sub_organizations' => [$this->listed('testorg001', null)]]), new Response(503)]);
        $this->approve($run, User::factory()->admin()->create(['is_active' => true]));
        $error = (string) session('error');
        $this->assertStringContainsString('HARD FAULT', $error);
        $this->assertStringContainsString('The vendor PUT may have committed.', $error);
        $this->assertStringContainsString('org PK testorg001', $error);
        $this->assertStringNotContainsString('POST', $error);
        $this->assertStringNotContainsString('vendor PK', $error);
    }

    /** Item 4 (contract-s2:9): a failing audit insert on the released no-op never reports a possible vendor write. */
    public function test_released_no_op_with_a_failing_audit_never_reports_a_possible_vendor_write(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001', null)]])]);
        $run = $this->stage($fixture);
        // Approval GET: absent. Pre-admit GET: already enforced (someone else did it) -> no-op release.
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001', null)]]), $this->ok(['sub_organizations' => [$this->listed('testorg001')]])]);
        TechnicianActionLog::creating(function (TechnicianActionLog $row): void {
            if ($row->action_type === StaffControlDOnboardingToolExecutor::STAGED_TOOL && $row->result_status === 'error') {
                throw new \RuntimeException('audit store unavailable');
            }
        });
        try {
            $this->approve($run, User::factory()->admin()->create(['is_active' => true]));
        } finally {
            TechnicianActionLog::flushEventListeners();
        }
        $this->assertSame('released', ControlDOnboardingIntent::sole()->state);
        $error = (string) session('error');
        $this->assertStringNotContainsString('possible vendor write', $error);
        $this->assertStringNotContainsString('HARD FAULT', $error);
        $this->assertStringContainsString('already enforced', $error);
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'controld_release_intent')->count());
        $this->assertSame(['GET', 'GET'], array_map(fn ($h) => $h['request']->getMethod(), $this->history));
    }

    /** Item 8 (contract-s2:6): the release form is offered whenever the card renders, including for an onboarded client. */
    public function test_release_form_shows_whenever_the_card_renders_and_never_without_it(): void
    {
        $this->configure();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $fixture['client']->forceFill(['controld_provisioning_code' => 'synthetic-code'])->save();
        $intent = new ControlDOnboardingIntent;
        $intent->forceFill(['id' => (string) \Illuminate\Support\Str::uuid(), 'client_id' => $fixture['client']->id, 'actor_id' => 1,
            'active_client_id' => $fixture['client']->id, 'operation' => 'code', 'state' => 'staged', 'phase' => 'preflight', 'payload' => []])->save();
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $url = route('clients.controld.intent.release', [$fixture['client'], $intent->id]);
        $this->assertStringContainsString($url, $this->actingAs($admin)->get(route('clients.show', $fixture['client']))->assertOk()->getContent());
        Setting::setValue(ControlDConfig::ONBOARDING_ENABLED_SETTING, '0');
        $this->assertStringNotContainsString($url, $this->actingAs($admin)->get(route('clients.show', $fixture['client']))->assertOk()->getContent());
    }

    /** Item 8 (context:10, contract-s1:8): no '2 of 2' text survives; tool descriptions name three steps. */
    public function test_texts_describe_three_steps(): void
    {
        $this->configure();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $html = $this->actingAs(User::factory()->admin()->create(['is_active' => true]))->get(route('clients.show', $fixture['client']))->assertOk()->getContent();
        $this->assertStringNotContainsString('of 2', $html);
        $this->assertStringContainsString('Organization mapped; no code yet', $html);
        $this->assertStringNotContainsString('Step 2 or 3', $html);
        $descriptions = json_encode(StaffControlDOnboardingToolExecutor::definitions());
        // P7A74iGD (b): one plan, one approval; the steps are listed in order.
        $this->assertStringContainsString('as ONE plan with ONE approval', $descriptions);
        $this->assertStringNotContainsString('separately staged steps', $descriptions);
        $this->assertStringContainsString('(2) cut one provisioning code', $descriptions);
        $this->assertStringContainsString('(3) optional, only when deploy is given', $descriptions);
    }

    // ── follow-up (Jeeves 2026-10-08 17:51 PT item 3; #6204 residuals) ──────────

    /** Stage a global-profile proposal and queue the approval exchange that binds it (GET, GET, PUT, GET). */
    private function stagedGlobalProfileThatBinds(): TechnicianRun
    {
        $this->travelTo(now()->startOfSecond());
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001', null)]])]);
        $run = $this->stage($fixture);
        $this->vendor([
            $this->ok(['sub_organizations' => [$this->listed('testorg001', null)]]),
            $this->ok(['sub_organizations' => [$this->listed('testorg001', null)]]),
            $this->ok(['organization' => ['PK' => 'testorg001']]),
            $this->ok(['sub_organizations' => [$this->listed('testorg001')]]),
            ...$this->codeResponses(),
        ]);

        return $run;
    }

    /** XULQ2iix #6362 r2 context:5/contract:3: the rendered race disclosure, exact. */
    public function test_race_disclosure_text_is_exact(): void
    {
        $this->assertSame(self::RACE, StaffControlDOnboardingToolExecutor::RACE_DISCLOSURE);
        $this->assertStringNotContainsString('stops after the step is admitted for its update, the step stays posted', StaffControlDOnboardingToolExecutor::RACE_DISCLOSURE);
    }

    /**
     * #6249 r1 context:5, inner catch: a refusal arrives with an intent id whose row is gone.
     * Admission cannot be ruled out, so it is not reported as 'refused before any vendor write'.
     */
    public function test_inner_catch_with_an_intent_id_but_no_row_is_not_called_pre_admission(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001', null)]])]);
        $run = $this->stage($fixture);
        $intentId = null;
        $this->vendor([
            $this->ok(['sub_organizations' => [$this->listed('testorg001', null)]]),
            function () use (&$intentId) {
                $intentId = ControlDOnboardingIntent::sole()->id;
                ControlDOnboardingIntent::query()->delete();

                return new Response(503);
            },
        ]);
        $this->approve($run, User::factory()->admin()->create(['is_active' => true]));
        $error = (string) session('error');
        $this->assertStringNotContainsString('refused before any vendor write', $error);
        $this->assertStringContainsString('HARD FAULT', $error);
        $this->assertStringContainsString('ended unknown', $error);
        // #6249 r2 context:1: the row (and so its active_client_id lock) is gone; the text must not claim the lock.
        $this->assertStringNotContainsString('keeps this client\'s onboarding lock', $error);
        // #6405: the exact text, one audit row carrying it, and no row holding this client's lock.
        $this->assertNotNull($intentId, 'the intent existed before the pre-admission read');
        $this->assertSame("The onboarding run did not complete: the 'global-profile' step ended unknown. HARD FAULT: the Control D 'global-profile' write for client #{$fixture['client']->id} ended unknown. The vendor PUT may have committed. Do NOT re-approve or re-stage: reconcile upstream and local state by hand; intent {$intentId} could not be found afterwards, so it holds no onboarding lock and does not block a new proposal for this client; do not stage one until then. The run is closed and is not re-approved; its state reads done, which here does not mean every step succeeded. Steps already bound are not redone when the plan is staged again; a step that ended uncertain must be reconciled first.", $error);
        // P7A74iGD (b): one step row carrying the text, plus the plan summary row (code not run).
        $this->assertSame(2, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'error')->count(), 'the step row and the summary row');
        $this->assertSame(1, TechnicianActionLog::where('run_id', $run->id)->where('summary', 'like', '%holds no onboarding lock and does not block a new proposal%')->count(), 'the step audit row carries the same text');
        $this->assertSame(1, TechnicianActionLog::where('run_id', $run->id)->where('summary', 'like', '%plan summary: global-profile=unknown, code=not-run; run did not complete; failed step: global-profile (unknown); run closed (state done).')->count());
        $this->assertSame(0, ControlDOnboardingIntent::where('active_client_id', $fixture['client']->id)->count(), 'nothing holds the per-client lock');
        $this->assertSame(0, TechnicianActionLog::where('run_id', $run->id)->where('summary', 'like', '%keeps this client%')->count());
        $this->assertSame(0, ControlDOnboardingIntent::count(), 'no intent row, so no lock row');
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame(['GET', 'GET'], array_map(fn ($h) => $h['request']->getMethod(), $this->history));
    }

    /**
     * #6406: on the technician_runs UPDATE that binds 'done' (advanceTo(Done)), the first
     * $throws such UPDATEs throw (to reach the outer catch); the next one finds the run claimed
     * by another request first (its claimed_at moved), so the fenced UPDATE matches no row and
     * advanceTo() returns false. Records whether the steal happened.
     */
    private function loseFenceOnDone(int $throws, bool &$stolen, bool &$armed, ?callable $onThrow = null, bool $closeElsewhere = false): void
    {
        $doneUpdates = 0;
        \Illuminate\Support\Facades\DB::connection()->beforeExecuting(function (string $sql, array $bindings) use ($throws, &$stolen, &$armed, &$doneUpdates, $onThrow, $closeElsewhere): void {
            if (! $armed || $stolen || ! str_starts_with(strtolower(ltrim($sql)), 'update') || ! str_contains($sql, 'technician_runs')
                || ! in_array(TechnicianRunState::Done->value, $bindings, true)) {
                return;
            }
            if ($doneUpdates++ < $throws) {
                if ($onThrow !== null) {
                    $onThrow();
                }
                throw new \RuntimeException('synthetic run store failure');
            }
            $stolen = true;
            \Illuminate\Support\Facades\DB::table('technician_runs')->update($closeElsewhere
                ? ['state' => TechnicianRunState::Done->value]
                : ['claimed_at' => now()->addHour()]);
        });
    }

    /** #6244 (contract-s2:6): a staged intent whose lock is NULL is not said to hold this client's onboarding lock. */
    public function test_pre_admission_refusal_does_not_claim_a_lock_the_intent_does_not_hold(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $this->vendor([$this->ok(['sub_organizations' => [$this->listed('testorg001', null)]])]);
        $run = $this->stage($fixture);
        // Approval GET: absent. Pre-admit GET: the lock moves off the row, then already enforced -> zero-row no-op, intent stays staged.
        $this->vendor([
            $this->ok(['sub_organizations' => [$this->listed('testorg001', null)]]),
            function () {
                ControlDOnboardingIntent::query()->update(['active_client_id' => null]);

                return $this->ok(['sub_organizations' => [$this->listed('testorg001')]]);
            },
        ]);
        $this->approve($run, User::factory()->admin()->create(['is_active' => true]));
        $intent = ControlDOnboardingIntent::sole();
        $this->assertSame(['staged', null], [$intent->state, $intent->active_client_id]);
        $this->assertSame('Control D onboarding was refused before any vendor write: The Control D global profile is already enforced on this organization; no vendor write was made, but the intent no longer matched a never-admitted staged intent of this client and was not released.', (string) session('error'));
        $this->assertSame(0, TechnicianActionLog::where('run_id', $run->id)->where('summary', 'like', '%holds this client%')->count());
    }

    /** #6228 (diff:2), #6235 (diff:10): the tool descriptions state the refusal and the race, and that global-profile proposals carry pins. */
    public function test_tool_descriptions_state_the_race_and_the_stored_pins(): void
    {
        $descriptions = json_encode(StaffControlDOnboardingToolExecutor::definitions(), JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('set the configured global profile on it (refused if a different one is already set when staged, when approved, or at the read just before the update; this code sends an unconditional update, so one set after that last read is not detected and the update may replace it);', $descriptions);
        // #6330: the undetected window starts after the pre-admission read, not the approval read.
        $this->assertStringNotContainsString('one set after that read is not detected', $descriptions);
        $this->assertStringNotContainsString('no conditional', $descriptions);
        $this->assertStringContainsString('the held proposal stores ids, the steps and their pinned values (organization and profile PKs, the code values, the deploy scope and each selected asset\'s Tactical agent), and approval derives the plan again and refuses unless it is identical.', $descriptions);
        $this->assertStringNotContainsString('never replacing', $descriptions);
        $this->assertStringNotContainsString('stores only ids and the step', $descriptions);
    }

    /** #6228 (diff:2), #6230 (diff:5): the mapped-client paragraph numbers no step and states the refusal, not 'never replaced'. */
    public function test_mapped_client_paragraph_numbers_no_step_and_states_the_refusal(): void
    {
        $this->configure();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $html = $this->actingAs(User::factory()->admin()->create(['is_active' => true]))->get(route('clients.show', $fixture['client']))->assertOk()->getContent();
        $this->assertStringContainsString('One plan, one approval: if the organization has no global profile set, the plan sets the configured one first; then it cuts one provisioning code with the Control D panel defaults (stored encrypted, never shown); then, if you choose devices below, it deploys to them through Tactical. If a different global profile is already set when staging reads it, staging refuses and no proposal is created; if one is set by the time approval reads it, approval refuses; either way nothing is written to Control D. '.self::RACE, $html);
        $this->assertStringNotContainsString('the proposal is refused', $html);
        $this->assertStringNotContainsString('(step 3)', $html);
        $this->assertStringNotContainsString('(step 2)', $html);
        $this->assertStringNotContainsString('is never replaced', $html);
    }

    /** #6232 (diff:7), no-request arm: an invalid profile setting refuses in globalProfileState() before any GET; the text does not claim a failed read. */
    public function test_unreadable_refusal_without_any_request_does_not_claim_a_failed_read(): void
    {
        $this->configure();
        $this->aiActor();
        Setting::setValue('controld_default_profile_id', 'not a valid pk!');
        $this->assertTrue(ControlDConfig::isOnboardingActive(), 'the verb is live, so nextStep() is reached');
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $this->vendor([]);
        $result = $this->decoded($this->callTool($this->token(), 'controld_onboard_client', $this->stageArgs($fixture)));
        $this->assertSame(self::UNREADABLE_REFUSAL, $result['error'] ?? null, json_encode($result));
        $this->assertStringNotContainsString('check of the parent organization list failed', $result['error']);
        $this->assertCount(0, $this->history, 'no request was made');
        $this->assertSame(0, TechnicianRun::count());
    }
}
