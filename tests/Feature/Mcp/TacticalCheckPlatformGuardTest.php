<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\TacticalScript;
use App\Models\TechnicianActionLog;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * psa-0pb9m — the create-check platform guard (R2: FAIL CLOSED, evidence not
 * assertion).
 *
 * Root-cause-class prevention: tactical_create_check could attach a script
 * check whose script cannot run on the target agent's platform (e.g. a
 * PowerShell/Windows-only script on a Mac). Tactical runs it anyway and it
 * fails on 100% of executions forever — manufacturing exactly the
 * "one check on every Mac, fails on all of them" defect. The guard fails
 * CLOSED before any upstream call: an agent whose platform is unknown is
 * refused (remedy: sync devices), a provably incompatible agent create is
 * refused outright, script metadata without a usable platform signal is
 * refused (absence is not compatibility). On a POLICY, a script that
 * DECLARES supported_platforms is allowed regardless of membership: Tactical
 * scopes policy script-check delivery per member agent by the script's own
 * supported_platforms (agents/models.py is_supported_script — read at the
 * deployed v1.5.1 server 2026-08-25), so members on undeclared platforms
 * never receive the check (Charlie's ruling 2026-08-25, "Tactical Option
 * A"). A platform-bound check WITHOUT that upstream scoping — a shell-only
 * script (empty supported_platforms is delivered to every member), or any
 * non-script check — is allowed only on SERVER-DERIVED MEMBERSHIP PROOF —
 * the policy's current member agents enumerated live from Tactical, every
 * one on a compatible platform. The R1 acknowledge_platform_risk boolean is GONE
 * (psa-0pb9m R2 HIGH): a caller-assertable claim was not evidence, and an AI
 * caller could simply retry with it set. The same invariant is enforced again
 * at the shared TacticalClient TRANSPORT seam — post() asserts the guard on
 * every request that resolves to checks/, with createCheck() as the named
 * front door (psa-0pb9m R5: guarding only createCheck() left raw post() as a
 * bypass) — so no caller path can reach the upstream create unguarded.
 * Covered by the client-boundary and raw-transport tests at the bottom of
 * this file.
 */
class TacticalCheckPlatformGuardTest extends TestCase
{
    use RefreshDatabase;

    private function configureTactical(): void
    {
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'secret');
    }

    private function configureAiActor(): void
    {
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
    }

    private function token(): string
    {
        return McpConfig::rotateStaffToken(allowedTools: ['tactical_create_check'], label: 'opsbot');
    }

    private function callTool(string $token, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'tactical_create_check', 'arguments' => $arguments],
            ]);
    }

    /** @return array{client: Client} */
    private function macFixture(?string $plat = 'darwin', ?string $os = 'Darwin 23.6.0 arm64'): array
    {
        $client = Client::factory()->create(['name' => 'Acme', 'tactical_site_id' => 'Acme|Main']);
        Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Client',
            'last_name' => 'Contact',
            'email' => 'client@example.test',
            'is_active' => true,
        ]);
        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'MAC-01',
            'name' => 'MAC-01',
        ]);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'agent-mac',
            'hostname' => 'MAC-01',
            'plat' => $plat,
            'os' => $os,
            'status' => 'online',
            'synced_at' => now(),
        ]);

        return compact('client');
    }

    /** @return array<int, array<string, mixed>> */
    private function upstreamScripts(string $shell, array $supportedPlatforms): array
    {
        return [
            [
                'id' => 102,
                'name' => 'Fleet Health Detector',
                'script_type' => 'userdefined',
                'shell' => $shell,
                'args' => [],
                'env_vars' => [],
                'supported_platforms' => $supportedPlatforms,
            ],
        ];
    }

    private function seedLocalScript(): void
    {
        TacticalScript::create([
            'tactical_script_id' => 102,
            'name' => 'Fleet Health Detector',
            'shell' => 'powershell',
            'synced_at' => now(),
        ]);
    }

    public function test_windows_only_script_on_a_mac_agent_is_rejected_before_any_upstream_create(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->macFixture();
        $this->seedLocalScript();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)
            ->andReturn($this->upstreamScripts('powershell', ['windows']));
        $tactical->shouldNotReceive('createCheck');
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($this->token(), [
            'client_id' => $fixture['client']->id,
            'reason' => 'Add a health check to this Mac.',
            'hostname' => 'MAC-01',
            'confirm_hostname' => 'MAC-01',
            'script_name' => 'Fleet Health Detector',
        ]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $text = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('darwin', $text);
        $this->assertStringContainsString('fail on every run', $text);

        $rejected = TechnicianActionLog::query()
            ->where('action_type', 'tactical_create_check')
            ->where('result_status', 'rejected')
            ->exists();
        $this->assertTrue($rejected, 'platform-guard rejection must be audited');
    }

    public function test_cmd_shell_script_on_a_mac_agent_is_rejected_without_metadata(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->macFixture();
        $this->seedLocalScript();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)
            ->andReturn($this->upstreamScripts('cmd', []));
        $tactical->shouldNotReceive('createCheck');
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($this->token(), [
            'client_id' => $fixture['client']->id,
            'reason' => 'Add a health check to this Mac.',
            'hostname' => 'MAC-01',
            'confirm_hostname' => 'MAC-01',
            'script_name' => 'Fleet Health Detector',
        ]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('cmd', (string) $response->json('result.content.0.text'));
    }

    public function test_darwin_supported_script_on_a_mac_agent_is_created(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->macFixture();
        $this->seedLocalScript();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)
            ->andReturn($this->upstreamScripts('shell', ['darwin']));
        $tactical->shouldReceive('createCheck')->once()->andReturn('Script Check was added!');
        $tactical->shouldReceive('getAgentChecks')->once()->with('agent-mac')->andReturn([
            ['id' => 310, 'check_type' => 'script', 'script' => 102],
        ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($this->token(), [
            'client_id' => $fixture['client']->id,
            'reason' => 'Add a genuine macOS health check.',
            'hostname' => 'MAC-01',
            'confirm_hostname' => 'MAC-01',
            'script_name' => 'Fleet Health Detector',
        ]);

        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $payload = json_decode((string) $response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(310, $payload['check_id']);
        $this->assertArrayNotHasKey('platform_warning', $payload);
    }

    public function test_unknown_agent_platform_is_refused_before_any_upstream_create(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        // No plat, unrecognizable os — FAIL CLOSED (revise): an unknown
        // platform is precisely the state the original wrong-platform
        // always-failing check shipped in. The remedy is a device sync.
        $fixture = $this->macFixture(plat: null, os: null);
        $this->seedLocalScript();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)
            ->andReturn($this->upstreamScripts('powershell', ['windows']));
        $tactical->shouldNotReceive('createCheck');
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($this->token(), [
            'client_id' => $fixture['client']->id,
            'reason' => 'Platform unknown — proceed.',
            'hostname' => 'MAC-01',
            'confirm_hostname' => 'MAC-01',
            'script_name' => 'Fleet Health Detector',
        ]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $text = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('unknown', $text);
        $this->assertStringContainsString('tactical:sync-devices', $text);

        $rejected = TechnicianActionLog::query()
            ->where('action_type', 'tactical_create_check')
            ->where('result_status', 'rejected')
            ->exists();
        $this->assertTrue($rejected, 'unknown-platform refusal must be audited');
    }

    public function test_policy_target_with_a_mac_member_and_declared_platforms_creates_without_membership_proof(): void
    {
        $this->configureTactical();
        $this->configureAiActor();

        // Charlie's ruling (2026-08-25, "Tactical Option A"): the script
        // DECLARES supported_platforms=['windows'], and Tactical delivers a
        // policy script check only to member agents on a declared platform
        // (is_supported_script — read at the deployed v1.5.1 server), so a
        // mixed-platform membership is irrelevant: the darwin members never
        // receive the check. NO membership read happens and the create
        // proceeds, with a note explaining the upstream delivery scoping.
        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getPolicies')->once()->andReturn([['id' => 7, 'name' => 'Workstations']]);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)
            ->andReturn($this->upstreamScripts('powershell', ['windows']));
        $tactical->shouldNotReceive('getAutomationPolicyRelated');
        $tactical->shouldNotReceive('getAgents');
        $tactical->shouldReceive('createCheck')->once()->andReturn('Script Check was added!');
        $tactical->shouldReceive('getPolicyChecks')->once()->with(7)->andReturn([
            ['id' => 214, 'check_type' => 'script', 'script' => 102],
        ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $this->seedLocalScript();

        $response = $this->callTool($this->token(), [
            'reason' => 'Policy-wide detector; Tactical scopes delivery by supported_platforms.',
            'policy_id' => 7,
            'confirm_policy_name' => 'Workstations',
            'script_name' => 'Fleet Health Detector',
        ]);

        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $payload = json_decode((string) $response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(214, $payload['check_id']);
        $this->assertArrayHasKey('platform_note', $payload);
        $this->assertStringContainsStringIgnoringCase('supported_platforms', $payload['platform_note']);
        $this->assertStringContainsStringIgnoringCase('never receive', $payload['platform_note']);
    }

    public function test_policy_target_with_a_mac_member_is_refused_for_a_shell_only_script_naming_the_member(): void
    {
        $this->configureTactical();
        $this->configureAiActor();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getPolicies')->once()->andReturn([['id' => 7, 'name' => 'Workstations']]);
        // SHELL-ONLY metadata: powershell with an EMPTY supported_platforms
        // list. Tactical delivers such a policy check to EVERY member
        // (is_supported_script: `... if platforms else True`), so upstream
        // delivery scoping does NOT protect the darwin member and the
        // membership proof still governs.
        $tactical->shouldReceive('getScripts')->once()->with(true, true)
            ->andReturn($this->upstreamScripts('powershell', []));
        // SERVER-DERIVED membership proof (R2): the policy's related payload +
        // the fleet list are read live; the Mac member is discovered here, not
        // asserted away by a caller boolean.
        $tactical->shouldReceive('getAutomationPolicyRelated')->once()->with(7)->andReturn([
            'pk' => 7, 'name' => 'Workstations',
            'agents' => [
                ['id' => 1, 'hostname' => 'PC-01', 'agent_id' => 'agent-pc1', 'client' => 'Acme', 'site' => 'Main'],
                ['id' => 2, 'hostname' => 'MAC-01', 'agent_id' => 'agent-mac', 'client' => 'Acme', 'site' => 'Main'],
            ],
            'workstation_clients' => [], 'server_clients' => [],
            'workstation_sites' => [], 'server_sites' => [],
            'is_default_server_policy' => false, 'is_default_workstation_policy' => false,
        ]);
        $tactical->shouldReceive('getAgents')->once()->andReturn([
            ['agent_id' => 'agent-pc1', 'hostname' => 'PC-01', 'plat' => 'windows', 'monitoring_type' => 'workstation', 'client_name' => 'Acme', 'site_name' => 'Main'],
            ['agent_id' => 'agent-mac', 'hostname' => 'MAC-01', 'plat' => 'darwin', 'monitoring_type' => 'workstation', 'client_name' => 'Acme', 'site_name' => 'Main'],
        ]);
        // The whole point: NO write happens — a mixed-membership policy is
        // refused on evidence, with no caller-assertable override.
        $tactical->shouldNotReceive('createCheck');
        $this->app->instance(TacticalClient::class, $tactical);

        $this->seedLocalScript();

        $response = $this->callTool($this->token(), [
            'reason' => 'Policy-wide detector.',
            'policy_id' => 7,
            'confirm_policy_name' => 'Workstations',
            'script_name' => 'Fleet Health Detector',
        ]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $text = (string) $response->json('result.content.0.text');
        // The refusal is an informed affordance: it names the incompatible
        // member, says there is no override, and names the declared-platforms
        // remedy that WOULD make this create safe.
        $this->assertStringContainsString('MAC-01', $text);
        $this->assertStringContainsString('no override', $text);
        $this->assertStringContainsString('supported_platforms', $text);
        $this->assertStringNotContainsString('acknowledge_platform_risk', $text, 'the caller-assertable escape hatch is gone');
    }

    public function test_policy_target_with_all_windows_membership_proven_creates_with_the_proof_note(): void
    {
        $this->configureTactical();
        $this->configureAiActor();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getPolicies')->once()->andReturn([['id' => 7, 'name' => 'Workstations']]);
        // Shell-only (empty supported_platforms): no upstream delivery
        // scoping, so this Windows-bound script still needs the membership
        // proof — the path this test pins.
        $tactical->shouldReceive('getScripts')->once()->with(true, true)
            ->andReturn($this->upstreamScripts('powershell', []));
        $tactical->shouldReceive('getAutomationPolicyRelated')->with(7)->andReturn([
            'pk' => 7, 'name' => 'Workstations',
            'agents' => [
                ['id' => 1, 'hostname' => 'PC-01', 'agent_id' => 'agent-pc1', 'client' => 'Acme', 'site' => 'Main'],
            ],
            'workstation_clients' => [], 'server_clients' => [],
            'workstation_sites' => [], 'server_sites' => [],
            'is_default_server_policy' => false, 'is_default_workstation_policy' => false,
        ]);
        $tactical->shouldReceive('getAgents')->andReturn([
            ['agent_id' => 'agent-pc1', 'hostname' => 'PC-01', 'plat' => 'windows', 'monitoring_type' => 'workstation', 'client_name' => 'Acme', 'site_name' => 'Main'],
        ]);
        $tactical->shouldReceive('createCheck')->once()->andReturn('Script Check was added!');
        $tactical->shouldReceive('getPolicyChecks')->once()->with(7)->andReturn([
            ['id' => 212, 'check_type' => 'script', 'script' => 102],
        ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $this->seedLocalScript();

        $response = $this->callTool($this->token(), [
            'reason' => 'Policy-wide detector; policy is Windows-only.',
            'policy_id' => 7,
            'confirm_policy_name' => 'Workstations',
            'script_name' => 'Fleet Health Detector',
        ]);

        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $payload = json_decode((string) $response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('platform_note', $payload);
        $this->assertStringContainsStringIgnoringCase('membership proof', $payload['platform_note']);
        $this->assertStringContainsStringIgnoringCase('added to the policy later', $payload['platform_note']);
    }

    public function test_policy_target_is_refused_when_membership_cannot_be_read(): void
    {
        $this->configureTactical();
        $this->configureAiActor();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getPolicies')->once()->andReturn([['id' => 7, 'name' => 'Workstations']]);
        // Shell-only: still on the membership-proof path (see above).
        $tactical->shouldReceive('getScripts')->once()->with(true, true)
            ->andReturn($this->upstreamScripts('powershell', []));
        $tactical->shouldReceive('getAutomationPolicyRelated')->once()->with(7)
            ->andThrow(new \App\Services\Tactical\TacticalClientException('boom'));
        // Unverifiable membership is UNKNOWN, and unknown is never compatible.
        $tactical->shouldNotReceive('createCheck');
        $this->app->instance(TacticalClient::class, $tactical);

        $this->seedLocalScript();

        $response = $this->callTool($this->token(), [
            'reason' => 'Policy-wide detector.',
            'policy_id' => 7,
            'confirm_policy_name' => 'Workstations',
            'script_name' => 'Fleet Health Detector',
        ]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('could not be read', (string) $response->json('result.content.0.text'));
    }

    public function test_policy_target_with_cross_platform_script_has_no_warning(): void
    {
        $this->configureTactical();
        $this->configureAiActor();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getPolicies')->once()->andReturn([['id' => 7, 'name' => 'Workstations']]);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)
            ->andReturn($this->upstreamScripts('shell', []));
        $tactical->shouldReceive('createCheck')->once()->andReturn('Script Check was added!');
        $tactical->shouldReceive('getPolicyChecks')->once()->with(7)->andReturn([
            ['id' => 213, 'check_type' => 'script', 'script' => 102],
        ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $this->seedLocalScript();

        $response = $this->callTool($this->token(), [
            'reason' => 'Policy-wide detector.',
            'policy_id' => 7,
            'confirm_policy_name' => 'Workstations',
            'script_name' => 'Fleet Health Detector',
        ]);

        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $payload = json_decode((string) $response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('platform_note', $payload);
    }

    // ── Client-boundary enforcement (TacticalCheckPlatformGuard) ─────────────
    // The MCP pre-checks above are defence in depth; the MANDATORY gate lives
    // where every check creation converges: the TacticalClient TRANSPORT —
    // post() asserts the guard on every request that resolves to checks/,
    // and createCheck() is the named front door that delegates there
    // (psa-0pb9m R5: guarding only createCheck() left raw post() as a
    // bypass). These tests drive a REAL client over a mock transport with an
    // EMPTY response queue — if the guard let the call through, Guzzle's
    // MockHandler would throw "queue is empty", so a passing refusal proves
    // NOTHING was sent. The raw-transport tests further down keep a handle on
    // the MockHandler and assert the queued write response is NEVER consumed.

    private function realClient(array $responses = []): TacticalClient
    {
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler($responses));

        return new TacticalClient(new \GuzzleHttp\Client([
            'base_uri' => 'https://tactical.example.test/',
            'handler' => $stack,
            'headers' => ['X-API-KEY' => 'k', 'Content-Type' => 'application/json'],
        ]));
    }

    public function test_client_boundary_refuses_wrong_platform_agent_create_with_no_http_sent(): void
    {
        $this->macFixture(); // darwin agent 'agent-mac'
        $this->seedLocalScript(); // powershell, tactical_script_id 102

        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/powershell/');

        $this->realClient()->createCheck([
            'agent' => 'agent-mac',
            'check_type' => 'script',
            'script' => 102,
            'name' => 'Wrong platform',
        ]);
    }

    public function test_client_boundary_refuses_unknown_agent_platform_with_no_http_sent(): void
    {
        $this->macFixture(plat: null, os: null);
        $this->seedLocalScript();

        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/tactical:sync-devices/');

        $this->realClient()->createCheck([
            'agent' => 'agent-mac',
            'check_type' => 'script',
            'script' => 102,
            'name' => 'Unknown platform',
        ]);
    }

    public function test_client_boundary_refuses_script_absent_from_catalog_and_live_getscripts(): void
    {
        $this->macFixture();
        // Script 999 is in neither the local catalog nor the vendor's own
        // getScripts (the guard's live fallback — queued here as an empty
        // list) — attaching it blind is refused (fail closed), remedy named.
        // Only the one queued GET is consumed; a POST would blow up the empty
        // remainder of the mock queue with a different exception.

        $client = $this->realClient([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([])),
        ]);

        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/tactical:sync-scripts/');

        $client->createCheck([
            'agent' => 'agent-mac',
            'check_type' => 'script',
            'script' => 999,
            'name' => 'Uncatalogued script',
        ]);
    }

    public function test_client_boundary_refuses_when_uncatalogued_script_metadata_cannot_be_read_live(): void
    {
        $this->macFixture();
        // Script 999 is not in the local catalog and the live getScripts
        // fallback FAILS (empty mock queue → transport error). A failed read
        // refuses — it never degrades into "no constraints".

        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/could not be read live from Tactical/');

        $this->realClient()->createCheck([
            'agent' => 'agent-mac',
            'check_type' => 'script',
            'script' => 999,
            'name' => 'Unreadable script metadata',
        ]);
    }

    public function test_client_boundary_refuses_policy_create_when_membership_includes_an_incompatible_agent(): void
    {
        // Shell-only catalog row (powershell, no declared supported_platforms):
        // Tactical delivers such a policy check to EVERY member, so the
        // membership proof still governs — cannot run on darwin/linux.
        $this->seedLocalScript();

        // The guard reads membership over the SAME client: exactly two queued
        // read responses (related, then the fleet list). Refusal must consume
        // only those — a POST would hit an empty mock queue and blow up with a
        // different exception, so the expected TacticalClientException proves
        // no write was sent.
        $client = $this->realClient([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                'pk' => 7, 'name' => 'Workstations',
                'agents' => [['id' => 2, 'hostname' => 'MAC-01', 'agent_id' => 'agent-mac', 'client' => 'Acme', 'site' => 'Main']],
                'workstation_clients' => [], 'server_clients' => [],
                'workstation_sites' => [], 'server_sites' => [],
                'is_default_server_policy' => false, 'is_default_workstation_policy' => false,
            ])),
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                ['agent_id' => 'agent-mac', 'hostname' => 'MAC-01', 'plat' => 'darwin', 'monitoring_type' => 'workstation', 'client_name' => 'Acme', 'site_name' => 'Main'],
            ])),
        ]);

        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/MAC-01/');

        $client->createCheck([
            'policy' => 7,
            'check_type' => 'script',
            'script' => 102,
            'name' => 'Policy check',
        ]);
    }

    public function test_client_boundary_allows_policy_create_for_a_declared_platform_script_without_membership_reads(): void
    {
        // Charlie's ruling (2026-08-25, "Tactical Option A"): a script that
        // DECLARES supported_platforms is delivery-scoped by Tactical itself —
        // a policy script check reaches a member agent only when
        // is_supported_script(supported_platforms) says so (read at the
        // deployed v1.5.1 server) — so the boundary requires no membership
        // proof. The mock queue holds ONLY the write response: a membership
        // read would consume it (or hit an empty queue) and fail, so success
        // here is machine proof that zero membership HTTP happened.
        TacticalScript::create([
            'tactical_script_id' => 102,
            'name' => 'Fleet Health Detector',
            'shell' => 'powershell',
            'supported_platforms' => ['windows'],
            'synced_at' => now(),
        ]);

        $result = $this->realClient([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode('Script Check was added!')),
        ])->createCheck([
            'policy' => 7,
            'check_type' => 'script',
            'script' => 102,
            'name' => 'Policy check',
        ]);

        $this->assertSame('Script Check was added!', $result);
    }

    public function test_client_boundary_refuses_non_script_policy_check_without_all_windows_proof(): void
    {
        // The R2 security drive: policy=7/check_type=ping previously bypassed
        // the guard entirely and returned HTTP_SENT. Non-script checks are
        // Windows-only (vendor constraint), so a policy with a linux member
        // refuses before any write.
        $client = $this->realClient([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                'pk' => 7, 'name' => 'Mixed',
                'agents' => [['id' => 3, 'hostname' => 'LNX-01', 'agent_id' => 'agent-lnx', 'client' => 'Acme', 'site' => 'Main']],
                'workstation_clients' => [], 'server_clients' => [],
                'workstation_sites' => [], 'server_sites' => [],
                'is_default_server_policy' => false, 'is_default_workstation_policy' => false,
            ])),
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                ['agent_id' => 'agent-lnx', 'hostname' => 'LNX-01', 'plat' => 'linux', 'monitoring_type' => 'server', 'client_name' => 'Acme', 'site_name' => 'Main'],
            ])),
        ]);

        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/LNX-01/');

        $client->createCheck([
            'policy' => 7,
            'check_type' => 'ping',
            'name' => 'Ping check',
        ]);
    }

    public function test_client_boundary_refuses_a_catalog_row_without_platform_signal(): void
    {
        $this->macFixture();
        // A row whose upstream getScripts entry carried no shell is stored
        // with the honest NULL (psa-0pb9m R3 A5 — never defaulted to
        // 'powershell'); with no supported_platforms either, it carries no
        // usable platform signal and the create refuses.
        TacticalScript::create([
            'tactical_script_id' => 103,
            'name' => 'Signal-less script',
            'shell' => null,
            'supported_platforms' => null,
            'synced_at' => now(),
        ]);

        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/neither/');

        $this->realClient()->createCheck([
            'agent' => 'agent-mac',
            'check_type' => 'script',
            'script' => 103,
            'name' => 'Uncheckable catalog row',
        ]);
    }

    public function test_client_boundary_refuses_policy_create_when_membership_read_fails(): void
    {
        $this->seedLocalScript();

        // The related read fails (empty mock queue → transport error). The
        // guard must convert that into a refusal, never proceed unproven.
        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/could not be read/');

        $this->realClient()->createCheck([
            'policy' => 7,
            'check_type' => 'script',
            'script' => 102,
            'name' => 'Policy check',
        ]);
    }

    public function test_client_boundary_resolves_uncatalogued_script_live_and_allows_compatible_create(): void
    {
        $this->macFixture(); // darwin agent

        // The script is NOT in the local catalog (e.g. created upstream since
        // the last sync). The guard resolves its metadata ITSELF from the
        // vendor's own getScripts row over the same client — never from a
        // caller claim; createCheck no longer even has a parameter to carry
        // one (psa-0pb9m R3 A3/S3: a fabricated cross-platform claim for an
        // uncatalogued script previously sailed straight to POST). The live
        // row is darwin-compatible, so the create goes through: first queued
        // response is the getScripts read, second is the POST.
        $result = $this->realClient([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                ['id' => 555, 'name' => 'Shipped macOS check script', 'shell' => 'shell', 'supported_platforms' => ['darwin']],
            ])),
            new \GuzzleHttp\Psr7\Response(200, [], json_encode('Script Check was added!')),
        ])->createCheck([
            'agent' => 'agent-mac',
            'check_type' => 'script',
            'script' => 555,
            'name' => 'Shipped macOS check',
        ]);

        $this->assertSame('Script Check was added!', $result);
    }

    public function test_client_boundary_refuses_uncatalogued_script_whose_live_row_is_incompatible(): void
    {
        $this->macFixture(); // darwin agent

        // The R3 A3/S3 drive, closed: for an uncatalogued script the ONLY
        // metadata source is the vendor's own getScripts row, and that row
        // says Windows-only — refused. Under the removed scriptMeta parameter
        // a caller could fabricate {shell: shell, supported_platforms: [all]}
        // here and reach POST; now there is nothing to fabricate through.
        $client = $this->realClient([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                ['id' => 555, 'name' => 'Windows-only tool', 'shell' => 'powershell', 'supported_platforms' => ['windows']],
            ])),
        ]);

        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/does not include darwin/');

        $client->createCheck([
            'agent' => 'agent-mac',
            'check_type' => 'script',
            'script' => 555,
            'name' => 'Fabrication-proof',
        ]);
    }

    public function test_client_boundary_refuses_a_dual_agent_and_policy_target(): void
    {
        $this->macFixture();
        $this->seedLocalScript();

        // The upstream Check model accepts both FKs with no exactly-one
        // validation — such a row lands in BOTH agentchecks and policychecks,
        // so a compatible decoy agent must never smuggle an unproven policy
        // attachment past the guard (psa-0pb9m R3 A3). Refused before any
        // read or write: the empty mock queue proves no HTTP left the client.
        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/BOTH an agent and a policy/');

        $this->realClient()->createCheck([
            'agent' => 'agent-mac',
            'policy' => 7,
            'check_type' => 'script',
            'script' => 102,
            'name' => 'Dual target',
        ]);
    }

    public function test_client_boundary_refuses_policy_create_when_related_payload_is_structurally_empty(): void
    {
        $this->seedLocalScript(); // powershell → policy proof required

        // The exact R3 S2 reproducer: GET related/ returns 200 {} and GET
        // agents/ returns 200 [] — previously proven=true, members_checked=0,
        // then POST. A 200 missing the serializer's fields is drift, and
        // absence of proof is never zero members: both queued reads are
        // consumed, then the refusal lands before any write.
        $client = $this->realClient([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode((object) [])),
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([])),
        ]);

        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/missing `agents`/');

        $client->createCheck([
            'policy' => 7,
            'check_type' => 'script',
            'script' => 102,
            'name' => 'Empty-proof policy check',
        ]);
    }

    public function test_client_boundary_refuses_non_script_policy_check_on_the_same_empty_proof_shape(): void
    {
        // R3 S3's second probe: a direct NON-SCRIPT policy check with
        // related={} and agents=[] also reached HTTP_SENT. Same fix, no
        // script resolution involved.
        $client = $this->realClient([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode((object) [])),
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([])),
        ]);

        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/missing `agents`/');

        $client->createCheck([
            'policy' => 7,
            'check_type' => 'ping',
            'name' => 'Empty-proof ping check',
        ]);
    }

    public function test_client_boundary_refuses_policy_create_when_default_flags_are_not_booleans(): void
    {
        $this->seedLocalScript();

        // Type drift on the default-policy flags: the vendor serializer emits
        // real booleans; a string 'false' is a drifted response, and reading
        // it loosely could hide a whole-fleet default membership.
        $client = $this->realClient([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                'pk' => 7, 'name' => 'Workstations',
                'agents' => [],
                'workstation_clients' => [], 'server_clients' => [],
                'workstation_sites' => [], 'server_sites' => [],
                'is_default_server_policy' => 'false', 'is_default_workstation_policy' => false,
            ])),
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([])),
        ]);

        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/`is_default_server_policy` as string/');

        $client->createCheck([
            'policy' => 7,
            'check_type' => 'script',
            'script' => 102,
            'name' => 'Drifted flag types',
        ]);
    }

    public function test_client_boundary_refuses_policy_create_when_fleet_rows_lack_the_join_keys(): void
    {
        $this->seedLocalScript();

        // The policy assigns a server CLIENT, so membership is enumerated by
        // joining fleet rows on client_name + monitoring_type. A fleet row
        // missing monitoring_type could belong to the policy invisibly —
        // membership cannot be COMPLETELY enumerated, so the proof refuses
        // (psa-0pb9m R3 S2: these rows previously just fell out of the join).
        $client = $this->realClient([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                'pk' => 7, 'name' => 'Acme servers',
                'agents' => [],
                'workstation_clients' => [], 'server_clients' => [['id' => 3, 'name' => 'Acme']],
                'workstation_sites' => [], 'server_sites' => [],
                'is_default_server_policy' => false, 'is_default_workstation_policy' => false,
            ])),
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                ['agent_id' => 'agent-9', 'hostname' => 'SRV-9', 'plat' => 'windows', 'client_name' => 'Acme', 'site_name' => 'Main'],
            ])),
        ]);

        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/missing `monitoring_type`/');

        $client->createCheck([
            'policy' => 7,
            'check_type' => 'script',
            'script' => 102,
            'name' => 'Unenumerable membership',
        ]);
    }

    public function test_client_boundary_refuses_empty_fleet_when_the_synced_snapshot_knows_agents(): void
    {
        $this->macFixture(); // the synced snapshot knows one Tactical agent
        $this->seedLocalScript();

        // The policy has a client assignment but GET agents/ returns zero
        // rows while the local snapshot knows agents exist — a degraded read
        // wearing a 200, not an empty fleet. Zero members from missing
        // evidence must not prove compatibility (psa-0pb9m R3 S2).
        $client = $this->realClient([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                'pk' => 7, 'name' => 'Acme workstations',
                'agents' => [],
                'workstation_clients' => [['id' => 3, 'name' => 'Acme']], 'server_clients' => [],
                'workstation_sites' => [], 'server_sites' => [],
                'is_default_server_policy' => false, 'is_default_workstation_policy' => false,
            ])),
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([])),
        ]);

        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/zero agents while the local synced snapshot knows 1/');

        $client->createCheck([
            'policy' => 7,
            'check_type' => 'script',
            'script' => 102,
            'name' => 'Degraded fleet read',
        ]);
    }

    public function test_client_boundary_refuses_policy_create_when_a_client_assignment_name_is_blank(): void
    {
        $this->seedLocalScript(); // powershell → darwin/linux blocked, proof required

        // The R4 A5 reproducer: a STRUCTURALLY COMPLETE related payload whose
        // workstation-client assignment carries name:"" — present-but-blank
        // join evidence. It matches no fleet row, so the assignment's members
        // (here a Darwin Acme workstation) silently fell out of the proven
        // set and the guard previously answered proven=true,
        // members_checked=0. Blank join values now refuse before any write:
        // both queued reads are consumed, no POST remains in the queue.
        $client = $this->realClient([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                'pk' => 7, 'name' => 'Acme workstations',
                'agents' => [],
                'workstation_clients' => [['id' => 3, 'name' => '']], 'server_clients' => [],
                'workstation_sites' => [], 'server_sites' => [],
                'is_default_server_policy' => false, 'is_default_workstation_policy' => false,
            ])),
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                ['agent_id' => 'agent-mac', 'hostname' => 'MAC-01', 'plat' => 'darwin', 'monitoring_type' => 'workstation', 'client_name' => 'Acme', 'site_name' => 'Main'],
            ])),
        ]);

        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/workstation-client assignment .* no usable `name`/');

        $client->createCheck([
            'policy' => 7,
            'check_type' => 'script',
            'script' => 102,
            'name' => 'Blank client join',
        ]);
    }

    public function test_client_boundary_refuses_policy_create_when_a_site_assignment_join_value_is_blank(): void
    {
        $this->seedLocalScript();

        // Same A5 shape one join deeper: the site assignment's `name` is
        // present but its `client_name` is blank, so the two-key site join
        // can never match a fleet row — the proven member set silently
        // shrinks. Blank site join values refuse identically.
        $client = $this->realClient([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                'pk' => 7, 'name' => 'Acme main site',
                'agents' => [],
                'workstation_clients' => [], 'server_clients' => [],
                'workstation_sites' => [['id' => 9, 'name' => 'Main', 'client_name' => '  ']], 'server_sites' => [],
                'is_default_server_policy' => false, 'is_default_workstation_policy' => false,
            ])),
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                ['agent_id' => 'agent-mac', 'hostname' => 'MAC-01', 'plat' => 'darwin', 'monitoring_type' => 'workstation', 'client_name' => 'Acme', 'site_name' => 'Main'],
            ])),
        ]);

        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/workstation-site assignment .* no usable `name`\/`client_name`/');

        $client->createCheck([
            'policy' => 7,
            'check_type' => 'script',
            'script' => 102,
            'name' => 'Blank site join',
        ]);
    }

    public function test_incompatible_platforms_helper_refuses_signal_less_metadata_instead_of_widening_to_empty(): void
    {
        // R4 A4/S6: incompatiblePlatforms(null, null) returned [] — and every
        // write-side caller reads [] as "no platform blocked, no membership
        // proof required", i.e. absence of metadata promoted to a claim of
        // universal compatibility. The helper now refuses signal-less input
        // outright, in every blank shape.
        foreach ([
            'null shell, null platforms' => [null, null],
            'blank shell, empty platforms' => ['   ', []],
            'null shell, blank platform entries' => [null, ['', '  ']],
        ] as $label => [$shell, $platforms]) {
            try {
                \App\Services\Tactical\TacticalCheckPlatformGuard::incompatiblePlatforms($shell, $platforms);
                $this->fail("incompatiblePlatforms must refuse signal-less metadata ({$label}), never answer [].");
            } catch (\App\Services\Tactical\TacticalClientException $e) {
                $this->assertStringContainsString('cannot be verified', $e->getMessage());
                $this->assertStringContainsString('tactical:sync-scripts', $e->getMessage());
            }
        }

        // With a usable signal the helper still answers the honest list —
        // [] now MEANS "no platform is provably blocked", never "unknown".
        $this->assertSame(
            ['darwin', 'linux'],
            \App\Services\Tactical\TacticalCheckPlatformGuard::incompatiblePlatforms('cmd', null),
        );
        $this->assertSame(
            [],
            \App\Services\Tactical\TacticalCheckPlatformGuard::incompatiblePlatforms('shell', null),
        );
    }

    public function test_policy_target_with_signal_less_live_script_row_is_refused_at_the_precheck_before_any_membership_read(): void
    {
        $this->configureTactical();
        $this->configureAiActor();

        // R4 A4/S6 MCP regression: the live getScripts row carries NO
        // platform signal (no shell key, empty supported_platforms).
        // Previously incompatiblePlatforms() widened to [] and the precheck
        // skipped the membership proof entirely — "no proof required" from
        // absent metadata; only the later client boundary saved the write.
        // The precheck itself now refuses, with the audited surface copy,
        // BEFORE any membership read: no related/agents read, no create.
        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getPolicies')->once()->andReturn([['id' => 7, 'name' => 'Workstations']]);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)->andReturn([
            ['id' => 102, 'name' => 'Fleet Health Detector', 'script_type' => 'userdefined', 'args' => [], 'env_vars' => [], 'supported_platforms' => []],
        ]);
        $tactical->shouldNotReceive('getAutomationPolicyRelated');
        $tactical->shouldNotReceive('getAgents');
        $tactical->shouldNotReceive('createCheck');
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($this->token(), [
            'reason' => 'Policy-wide detector.',
            'policy_id' => 7,
            'confirm_policy_name' => 'Workstations',
            'script_name' => 'Fleet Health Detector',
        ]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $text = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('could not be verified', $text);
        $this->assertStringContainsString('tactical:sync-scripts', $text);
        $this->assertStringContainsString('Fleet Health Detector', $text);

        $rejected = TechnicianActionLog::query()
            ->where('action_type', 'tactical_create_check')
            ->where('result_status', 'rejected')
            ->exists();
        $this->assertTrue($rejected, 'signal-less precheck refusal must be audited');
    }

    public function test_agent_target_with_signal_less_live_script_row_is_refused_at_the_precheck(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->macFixture();

        // Same seam, agent target: scriptIncompatibility(darwin, null, null)
        // answers null (no claim on missing data), so before R4 the precheck
        // fell through to the boundary. The shared signal-less refusal now
        // lands first, as an audited rejection.
        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)->andReturn([
            ['id' => 102, 'name' => 'Fleet Health Detector', 'script_type' => 'userdefined', 'args' => [], 'env_vars' => [], 'supported_platforms' => []],
        ]);
        $tactical->shouldNotReceive('createCheck');
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($this->token(), [
            'client_id' => $fixture['client']->id,
            'reason' => 'Add a health check to this Mac.',
            'hostname' => 'MAC-01',
            'confirm_hostname' => 'MAC-01',
            'script_name' => 'Fleet Health Detector',
        ]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $text = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('could not be verified', $text);
        $this->assertStringContainsString('tactical:sync-scripts', $text);
    }

    public function test_client_boundary_allows_zero_member_policy_from_a_structurally_complete_response(): void
    {
        $this->seedLocalScript(); // powershell → policy proof required

        // Zero members is acceptable ONLY from an explicitly valid, complete
        // response: all seven serializer fields present with their runtime
        // types, collections genuinely empty, flags false. The one fleet row
        // is not a member (no assignment reaches it), so the Windows-bound
        // script is safe on this empty policy — the POST goes through.
        $result = $this->realClient([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                'pk' => 7, 'name' => 'Empty policy',
                'agents' => [],
                'workstation_clients' => [], 'server_clients' => [],
                'workstation_sites' => [], 'server_sites' => [],
                'is_default_server_policy' => false, 'is_default_workstation_policy' => false,
            ])),
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                ['agent_id' => 'agent-pc1', 'hostname' => 'PC-01', 'plat' => 'windows', 'monitoring_type' => 'workstation', 'client_name' => 'Acme', 'site_name' => 'Main'],
            ])),
            new \GuzzleHttp\Psr7\Response(200, [], json_encode('Script Check was added!')),
        ])->createCheck([
            'policy' => 7,
            'check_type' => 'script',
            'script' => 102,
            'name' => 'Empty-policy check',
        ]);

        $this->assertSame('Script Check was added!', $result);
    }

    // ── Raw-transport seam enforcement (psa-0pb9m R5) ────────────────────────
    // R5 proved the guard-inside-createCheck() arrangement left the generic
    // public post() as a second write seam: a raw post('checks/', …) reached
    // HTTP with no catalog/platform evidence. The guard now runs inside the
    // transport itself for every POST that resolves to checks/, so the
    // "future caller cannot bypass" claim is mechanical, not conventional.
    // These tests keep a handle on the MockHandler and assert the queued
    // would-be write response is NEVER consumed on refusal.

    /** @return array{0: TacticalClient, 1: \GuzzleHttp\Handler\MockHandler} */
    private function realClientWithMock(array $responses): array
    {
        $mock = new \GuzzleHttp\Handler\MockHandler($responses);

        $client = new TacticalClient(new \GuzzleHttp\Client([
            'base_uri' => 'https://tactical.example.test/',
            'handler' => \GuzzleHttp\HandlerStack::create($mock),
            'headers' => ['X-API-KEY' => 'k', 'Content-Type' => 'application/json'],
        ]));

        return [$client, $mock];
    }

    public function test_raw_transport_post_to_checks_with_no_evidence_is_refused_before_any_write(): void
    {
        // The exact R5 architecture repro, closed: a real client's public
        // post('checks/', …) — around createCheck() entirely — with a policy
        // target and NO catalog/platform evidence (script 102 is in neither
        // the local catalog nor a readable live getScripts; the empty queue
        // fails the live read). Previously this sent HTTP; the transport-seam
        // guard now refuses with ITS message ("could not be read live"), not
        // a POST transport error — the write never went out.
        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/could not be read live from Tactical/');

        $this->realClient()->post('checks/', [
            'policy' => 7,
            'check_type' => 'script',
            'script' => 102,
            'name' => 'Raw transport bypass',
        ]);
    }

    public function test_raw_transport_post_to_checks_never_consumes_the_write_when_membership_proof_fails(): void
    {
        $this->seedLocalScript(); // powershell → darwin/linux blocked, proof required

        // Raw post('checks/', …) with the would-be write response queued
        // LAST: the transport-seam guard consumes exactly the two membership
        // reads, meets the darwin member, and refuses — the write response is
        // still sitting in the mock queue, machine proof that no HTTP write
        // was consumed through the raw route.
        [$client, $mock] = $this->realClientWithMock([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                'pk' => 7, 'name' => 'Workstations',
                'agents' => [['id' => 2, 'hostname' => 'MAC-01', 'agent_id' => 'agent-mac', 'client' => 'Acme', 'site' => 'Main']],
                'workstation_clients' => [], 'server_clients' => [],
                'workstation_sites' => [], 'server_sites' => [],
                'is_default_server_policy' => false, 'is_default_workstation_policy' => false,
            ])),
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                ['agent_id' => 'agent-mac', 'hostname' => 'MAC-01', 'plat' => 'darwin', 'monitoring_type' => 'workstation', 'client_name' => 'Acme', 'site_name' => 'Main'],
            ])),
            new \GuzzleHttp\Psr7\Response(200, [], json_encode('Script Check was added!')),
        ]);

        try {
            $client->post('checks/', [
                'policy' => 7,
                'check_type' => 'script',
                'script' => 102,
                'name' => 'Raw policy check',
            ]);
            $this->fail('raw post(checks/) must be refused by the transport-seam guard');
        } catch (\App\Services\Tactical\TacticalClientException $e) {
            $this->assertStringContainsString('MAC-01', $e->getMessage());
        }

        $this->assertSame(1, $mock->count(), 'the queued write response must never be consumed through the raw route');
    }

    public function test_raw_transport_post_to_checks_refuses_on_local_evidence_with_zero_http(): void
    {
        $this->macFixture();      // darwin agent in the synced snapshot
        $this->seedLocalScript(); // powershell script 102 in the synced catalog

        // Agent-target raw post: the guard resolves the platform and the
        // script's constraints entirely from local server-derived state and
        // refuses before ANY request — the single queued response (the
        // would-be write) survives untouched: zero HTTP consumed.
        [$client, $mock] = $this->realClientWithMock([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode('Script Check was added!')),
        ]);

        try {
            $client->post('checks/', [
                'agent' => 'agent-mac',
                'check_type' => 'script',
                'script' => 102,
                'name' => 'Raw agent check',
            ]);
            $this->fail('raw post(checks/) must be refused by the transport-seam guard');
        } catch (\App\Services\Tactical\TacticalClientException $e) {
            $this->assertStringContainsString('powershell', $e->getMessage());
        }

        $this->assertSame(1, $mock->count(), 'no HTTP at all may be consumed when the refusal resolves locally');
    }

    public function test_raw_transport_guard_covers_check_endpoint_spelling_variants(): void
    {
        // The matcher normalizes the endpoint the same way PSR-7 does when
        // the request URI is built — query stripped, dot segments removed,
        // slashes trimmed — so no spelling of the checks collection slips an
        // unguarded creation past the seam. The dual-target payload refuses
        // before any read, so each variant proves itself with an untouched
        // one-response queue.
        foreach (['checks', '/checks/', 'checks/?dry_run=1', 'foo/../checks/'] as $endpoint) {
            [$client, $mock] = $this->realClientWithMock([
                new \GuzzleHttp\Psr7\Response(200, [], json_encode('never sent')),
            ]);

            try {
                $client->post($endpoint, [
                    'agent' => 'agent-mac',
                    'policy' => 7,
                    'check_type' => 'script',
                    'script' => 102,
                    'name' => 'Variant probe',
                ]);
                $this->fail("post('{$endpoint}', …) must be guarded");
            } catch (\App\Services\Tactical\TacticalClientException $e) {
                $this->assertStringContainsString('BOTH an agent and a policy', $e->getMessage(), $endpoint);
            }

            $this->assertSame(1, $mock->count(), "no HTTP may be consumed for '{$endpoint}'");
        }
    }

    /** @return array{0: TacticalClient, 1: \GuzzleHttp\Handler\MockHandler} */
    private function realPrefixedBaseClientWithMock(array $responses): array
    {
        $mock = new \GuzzleHttp\Handler\MockHandler($responses);

        $client = new TacticalClient(new \GuzzleHttp\Client([
            'base_uri' => 'https://tactical.example.test/api/v3/',
            'handler' => \GuzzleHttp\HandlerStack::create($mock),
            'headers' => ['X-API-KEY' => 'k', 'Content-Type' => 'application/json'],
        ]));

        return [$client, $mock];
    }

    public function test_raw_transport_guard_covers_the_fully_resolved_checks_collection_url(): void
    {
        // The psa-ou9pe.1 STILL-PRESENT repro, closed: with the client's
        // base_uri carrying the real /api/v3/ prefix, the fully resolved
        // same-origin collection URL previously sailed past the raw-path
        // matcher ('api/v3/checks' !== 'checks') while Guzzle sent the POST
        // to the checks collection regardless. The matcher now ALSO resolves
        // the endpoint against base_uri exactly as Guzzle builds the request
        // URI and compares the normalized resolved path against the resolved
        // collection — so no absolute-URL, absolute-path, dot-segment,
        // percent-encoded, or slash-doubled spelling of the collection can
        // carry an unguarded creation. The dual-target payload refuses before
        // any read, so each variant proves itself with an untouched
        // one-response queue.
        foreach ([
            'https://tactical.example.test/api/v3/checks/',
            'HTTPS://TACTICAL.EXAMPLE.TEST/api/v3/checks/',
            'https://tactical.example.test:443/api/v3/checks/',
            '/api/v3/checks/',
            '../v3/checks/',
            'https://tactical.example.test/api/v3//checks/',
            'https://tactical.example.test/api/v3/%63hecks/',
            'checks/',
        ] as $endpoint) {
            [$client, $mock] = $this->realPrefixedBaseClientWithMock([
                new \GuzzleHttp\Psr7\Response(200, [], json_encode('never sent')),
            ]);

            try {
                $client->post($endpoint, [
                    'agent' => 'agent-1',
                    'policy' => 7,
                    'check_type' => 'script',
                    'script' => 102,
                    'name' => 'Resolved-URL probe',
                ]);
                $this->fail("post('{$endpoint}', …) must be guarded on a prefixed base_uri");
            } catch (\App\Services\Tactical\TacticalClientException $e) {
                $this->assertStringContainsString('BOTH an agent and a policy', $e->getMessage(), $endpoint);
            }

            $this->assertSame(1, $mock->count(), "no HTTP may be consumed for '{$endpoint}'");
        }
    }

    public function test_raw_transport_guard_on_a_prefixed_base_leaves_non_collection_posts_unguarded(): void
    {
        // Resolution-level matching must not over-reach: other collections,
        // checks/{id}/ sub-paths, and absolute URLs that do NOT resolve to
        // the collection pass through the generic transport unchanged.
        foreach ([
            'tasks/',
            'checks/123/reset/',
            'https://tactical.example.test/api/v3/tasks/',
            '/api/v3/checks/123/reset/',
        ] as $endpoint) {
            [$client, $mock] = $this->realPrefixedBaseClientWithMock([
                new \GuzzleHttp\Psr7\Response(200, [], json_encode('ok')),
            ]);

            $this->assertSame('ok', $client->post($endpoint, ['anything' => true]), $endpoint);
            $this->assertSame(0, $mock->count(), $endpoint);
        }
    }

    /** @return array{0: TacticalClient, 1: \GuzzleHttp\Handler\MockHandler} */
    private function realCollectionValuedBaseClientWithMock(array $responses): array
    {
        $mock = new \GuzzleHttp\Handler\MockHandler($responses);

        // A base_uri that IS ALREADY the checks collection. This is an ACCEPTED
        // config shape: SafeTacticalUrl validates scheme/host/IP, not path
        // (IntegrationsController updateTactical), so nothing rejects it.
        $client = new TacticalClient(new \GuzzleHttp\Client([
            'base_uri' => 'https://tactical.example.test/api/v3/checks/',
            'handler' => \GuzzleHttp\HandlerStack::create($mock),
            'headers' => ['X-API-KEY' => 'k', 'Content-Type' => 'application/json'],
        ]));

        return [$client, $mock];
    }

    public function test_raw_transport_guard_covers_a_collection_valued_base_uri(): void
    {
        // psa-y9ae5.1 REVISE, closed: when base_uri IS ALREADY the checks
        // collection, the resolution matcher computed the collection as
        // resolve(base, 'checks/') = .../api/v3/checks/checks/ — a FICTITIOUS
        // child — so an empty / dot / query-only / absolute reference that
        // Guzzle resolves to the REAL .../api/v3/checks/ collection sailed past
        // unguarded and the write was SENT. The matcher now ALSO fails closed
        // when the configured base's own path IS the checks collection and the
        // request resolves to exactly it. Dual-target payload refuses before any
        // read, so each variant proves itself with an untouched one-response queue.
        foreach ([
            '',
            '.',
            '?dry=1',
            'https://tactical.example.test/api/v3/checks/',
            '/api/v3/checks/',
        ] as $endpoint) {
            [$client, $mock] = $this->realCollectionValuedBaseClientWithMock([
                new \GuzzleHttp\Psr7\Response(200, [], json_encode('never sent')),
            ]);

            try {
                $client->post($endpoint, [
                    'agent' => 'agent-1',
                    'policy' => 7,
                    'check_type' => 'script',
                    'script' => 102,
                    'name' => 'Collection-valued base probe',
                ]);
                $this->fail("post('{$endpoint}', …) must be guarded on a collection-valued base_uri");
            } catch (\App\Services\Tactical\TacticalClientException $e) {
                $this->assertStringContainsString('BOTH an agent and a policy', $e->getMessage(), $endpoint);
            }

            $this->assertSame(1, $mock->count(), "no HTTP may be consumed for '{$endpoint}'");
        }
    }

    public function test_raw_transport_post_to_non_check_endpoints_is_not_guarded(): void
    {
        // The seam guards exactly the checks collection. Other collections
        // and checks/{id}/ sub-paths are not check creation — they pass
        // through the generic transport unchanged, each consuming its queued
        // response.
        foreach (['tasks/', 'checks/123/reset/'] as $endpoint) {
            [$client, $mock] = $this->realClientWithMock([
                new \GuzzleHttp\Psr7\Response(200, [], json_encode('ok')),
            ]);

            $this->assertSame('ok', $client->post($endpoint, ['anything' => true]), $endpoint);
            $this->assertSame(0, $mock->count(), $endpoint);
        }
    }

    public function test_raw_transport_post_to_checks_with_full_evidence_still_creates(): void
    {
        $this->macFixture(); // darwin agent
        TacticalScript::create([
            'tactical_script_id' => 555,
            'name' => 'Mac health',
            'shell' => 'shell',
            'synced_at' => now(),
        ]);

        // Enforcement, not prohibition: a raw post('checks/', …) whose
        // evidence proves compatibility passes the seam guard and creates —
        // identical semantics to createCheck(), because it IS the same seam.
        $result = $this->realClient([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode('Script Check was added!')),
        ])->post('checks/', [
            'agent' => 'agent-mac',
            'check_type' => 'script',
            'script' => 555,
            'name' => 'Guarded raw create',
        ]);

        $this->assertSame('Script Check was added!', $result);
    }

    // ── Stale local evidence must not authorize a write (psa-ou9pe) ──────────
    // The psa-ou9pe.1 finding: the guard read TacticalAsset platform and
    // TacticalScript metadata from the local rows with NO freshness bound —
    // an arbitrarily old darwin snapshot plus an arbitrarily old script row
    // authorized the write with zero live reads, while the real agent had
    // been reimaged to Windows (or the script edited upstream) since. Local
    // evidence now authorizes on its own only within
    // TacticalCheckPlatformGuard::FRESH_EVIDENCE_MAX_HOURS; a staler (or
    // never-stamped) row is resolved LIVE at this write boundary, and a
    // failed or unresolvable live read REFUSES with a recovery instruction.

    private function staleAgentRow(string $plat = 'darwin', ?\Carbon\Carbon $syncedAt = null): void
    {
        TacticalAsset::create([
            'agent_id' => 'agent-stale',
            'hostname' => 'OLD-SNAPSHOT',
            'plat' => $plat,
            'os' => $plat === 'darwin' ? 'Darwin 23.6.0 arm64' : 'Windows 11 Pro',
            'status' => 'online',
            'synced_at' => $syncedAt ?? now()->subDays(30),
        ]);
    }

    private function macCompatibleScript(\Carbon\Carbon $syncedAt): void
    {
        TacticalScript::create([
            'tactical_script_id' => 900,
            'name' => 'Mac maintenance',
            'shell' => 'shell',
            'supported_platforms' => ['darwin'],
            'synced_at' => $syncedAt,
        ]);
    }

    /** @return array<string, mixed> The body of a script-check create against the stale agent. */
    private function staleAgentCheckBody(): array
    {
        return [
            'agent' => 'agent-stale',
            'check_type' => 'script',
            'script' => 900,
            'name' => 'Stale-evidence probe',
        ];
    }

    public function test_a_stale_agent_snapshot_is_not_trusted_the_live_platform_governs(): void
    {
        // The exact psa-ou9pe.1 repro, closed: a 30-day-old darwin snapshot
        // plus a fresh darwin-only script previously authorized the write
        // with zero live reads — but the real agent was reimaged to Windows
        // since. The guard now resolves the stale snapshot live, meets the
        // Windows answer, and refuses the darwin-only script; the queued
        // write survives untouched.
        $this->staleAgentRow('darwin');
        $this->macCompatibleScript(now());

        [$client, $mock] = $this->realClientWithMock([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                'agent_id' => 'agent-stale',
                'plat' => 'windows',
                'operating_system' => 'Windows 11 Pro',
            ])),
            new \GuzzleHttp\Psr7\Response(200, [], json_encode('never sent')),
        ]);

        try {
            $client->post('checks/', $this->staleAgentCheckBody());
            $this->fail('a stale snapshot must not authorize the write when the live platform is incompatible');
        } catch (\App\Services\Tactical\TacticalClientException $e) {
            $this->assertStringContainsString('fail on every run', $e->getMessage());
        }

        $this->assertSame(1, $mock->count(), 'the queued write must never be consumed');
    }

    public function test_a_stale_agent_snapshot_repaired_by_the_live_read_still_creates(): void
    {
        // Enforcement, not prohibition: when the live read confirms a
        // compatible platform, the stale snapshot is repaired at the boundary
        // and the create proceeds.
        $this->staleAgentRow('darwin');
        $this->macCompatibleScript(now());

        [$client, $mock] = $this->realClientWithMock([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                'agent_id' => 'agent-stale',
                'plat' => 'darwin',
                'operating_system' => 'Darwin 23.6.0 arm64',
            ])),
            new \GuzzleHttp\Psr7\Response(200, [], json_encode('Script Check was added!')),
        ]);

        $this->assertSame('Script Check was added!', $client->post('checks/', $this->staleAgentCheckBody()));
        $this->assertSame(0, $mock->count());
    }

    public function test_a_stale_agent_snapshot_with_a_failed_live_read_refuses_with_recovery(): void
    {
        // Minimum bar from the finding: stale evidence with no live
        // replacement is REFUSED with a recovery instruction — never sent.
        $this->staleAgentRow('darwin');
        $this->macCompatibleScript(now());

        [$client, $mock] = $this->realClientWithMock([
            new \GuzzleHttp\Psr7\Response(500, [], 'upstream error'),
            new \GuzzleHttp\Psr7\Response(200, [], json_encode('never sent')),
        ]);

        try {
            $client->post('checks/', $this->staleAgentCheckBody());
            $this->fail('a stale snapshot with no live replacement must refuse');
        } catch (\App\Services\Tactical\TacticalClientException $e) {
            $this->assertStringContainsString('stale', $e->getMessage());
            $this->assertStringContainsString('tactical:sync-devices', $e->getMessage());
        }

        $this->assertSame(1, $mock->count(), 'the queued write must never be consumed');
    }

    public function test_a_stale_agent_snapshot_with_an_unresolvable_live_platform_refuses(): void
    {
        // Bounded fail-closed validation of the live answer (the same posture
        // as the membership proof): a 200 whose payload carries no resolvable
        // plat/operating_system proves nothing — refuse, never guess.
        $this->staleAgentRow('darwin');
        $this->macCompatibleScript(now());

        [$client, $mock] = $this->realClientWithMock([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                'agent_id' => 'agent-stale',
                'plat' => 'amiga',
            ])),
            new \GuzzleHttp\Psr7\Response(200, [], json_encode('never sent')),
        ]);

        try {
            $client->post('checks/', $this->staleAgentCheckBody());
            $this->fail('an unresolvable live platform must refuse');
        } catch (\App\Services\Tactical\TacticalClientException $e) {
            $this->assertStringContainsString('stale', $e->getMessage());
            $this->assertStringContainsString('tactical:sync-devices', $e->getMessage());
        }

        $this->assertSame(1, $mock->count(), 'the queued write must never be consumed');
    }

    public function test_a_never_stamped_agent_snapshot_is_stale_evidence(): void
    {
        // synced_at NULL is "age unknown", and unknown age is never fresh —
        // the row goes through the same live resolution as a stale one.
        $this->staleAgentRow('darwin', syncedAt: null);
        TacticalAsset::where('agent_id', 'agent-stale')->update(['synced_at' => null]);
        $this->macCompatibleScript(now());

        [$client, $mock] = $this->realClientWithMock([
            new \GuzzleHttp\Psr7\Response(500, [], 'upstream error'),
            new \GuzzleHttp\Psr7\Response(200, [], json_encode('never sent')),
        ]);

        try {
            $client->post('checks/', $this->staleAgentCheckBody());
            $this->fail('a never-stamped snapshot must not authorize the write on its own');
        } catch (\App\Services\Tactical\TacticalClientException $e) {
            $this->assertStringContainsString('stale', $e->getMessage());
        }

        $this->assertSame(1, $mock->count());
    }

    public function test_a_stale_script_catalog_row_is_not_trusted_the_live_catalog_governs(): void
    {
        // The script half of the finding: a 30-day-old catalog row still
        // claiming darwin compatibility must not authorize the write when the
        // script was edited upstream — the live getScripts row governs.
        $this->macFixture(); // fresh darwin agent 'agent-mac'
        $this->macCompatibleScript(now()->subDays(30)); // stale row: shell + [darwin]

        [$client, $mock] = $this->realClientWithMock([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([[
                'id' => 900,
                'name' => 'Mac maintenance',
                'shell' => 'powershell',
                'supported_platforms' => ['windows'],
            ]])),
            new \GuzzleHttp\Psr7\Response(200, [], json_encode('never sent')),
        ]);

        try {
            $client->post('checks/', [
                'agent' => 'agent-mac',
                'check_type' => 'script',
                'script' => 900,
                'name' => 'Stale-catalog probe',
            ]);
            $this->fail('a stale catalog row must not authorize the write when the live metadata is incompatible');
        } catch (\App\Services\Tactical\TacticalClientException $e) {
            $this->assertStringContainsString('fail on every run', $e->getMessage());
        }

        $this->assertSame(1, $mock->count(), 'the queued write must never be consumed');
    }

    public function test_a_stale_script_catalog_row_with_a_failed_live_read_refuses_with_recovery(): void
    {
        $this->macFixture(); // fresh darwin agent
        $this->macCompatibleScript(now()->subDays(30));

        [$client, $mock] = $this->realClientWithMock([
            new \GuzzleHttp\Psr7\Response(500, [], 'upstream error'),
            new \GuzzleHttp\Psr7\Response(200, [], json_encode('never sent')),
        ]);

        try {
            $client->post('checks/', [
                'agent' => 'agent-mac',
                'check_type' => 'script',
                'script' => 900,
                'name' => 'Stale-catalog probe',
            ]);
            $this->fail('a stale catalog row with no live replacement must refuse');
        } catch (\App\Services\Tactical\TacticalClientException $e) {
            $this->assertStringContainsString('stale', $e->getMessage());
            $this->assertStringContainsString('tactical:sync-scripts', $e->getMessage());
        }

        $this->assertSame(1, $mock->count(), 'the queued write must never be consumed');
    }

    public function test_fresh_local_evidence_still_authorizes_with_zero_live_reads(): void
    {
        // The freshness bound must not demote the healthy path: rows synced
        // within FRESH_EVIDENCE_MAX_HOURS authorize exactly as before, with
        // no HTTP beyond the write itself.
        $this->staleAgentRow('darwin', syncedAt: now()->subHours(20));
        $this->macCompatibleScript(now()->subHours(20));

        [$client, $mock] = $this->realClientWithMock([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode('Script Check was added!')),
        ]);

        $this->assertSame('Script Check was added!', $client->post('checks/', $this->staleAgentCheckBody()));
        $this->assertSame(0, $mock->count(), 'fresh evidence resolves locally — only the write itself may consume HTTP');
    }
}
