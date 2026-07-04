<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\TacticalScript;
use App\Models\TechnicianActionLog;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class TacticalCheckCrudPhase4Test extends TestCase
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

    /** @return array<int, array<string, mixed>> */
    private function policies(): array
    {
        return [
            ['id' => 7, 'name' => 'Workstations'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function scripts(): array
    {
        return [
            [
                'id' => 102,
                'name' => 'HelpDesk Buttons Detector',
                'script_type' => 'userdefined',
                'shell' => 'powershell',
                'args' => [],
                'env_vars' => [],
                'supported_platforms' => ['windows'],
            ],
        ];
    }

    /** @return array{client: Client, asset: Asset} */
    private function endpointFixture(): array
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
            'hostname' => 'PC-01',
            'name' => 'PC-01',
        ]);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'agent-1',
            'hostname' => 'PC-01',
            'status' => 'online',
            'synced_at' => now(),
        ]);

        return compact('client', 'asset');
    }

    public function test_create_check_tool_is_sensitive_explicit_grant_only_and_exposes_policy_and_agent_inputs(): void
    {
        $this->configureTactical();

        $groups = McpToolRegistry::groups();
        $adminNames = array_column($groups['tactical_admin']['tools'], 'name');

        $this->assertContains('tactical_create_check', $adminNames);
        $this->assertContains('tactical_create_check', McpToolRegistry::allToolNames());

        $legacyNames = array_column($this->listTools($this->legacyToken()), 'name');
        $this->assertNotContains('tactical_create_check', $legacyNames);

        $tool = collect($this->listTools($this->token(['tactical_create_check'])))
            ->keyBy('name')
            ->get('tactical_create_check');

        $this->assertNotNull($tool);
        $this->assertContains('reason', $tool['inputSchema']['required']);
        $this->assertContains('script_id', array_keys($tool['inputSchema']['properties']));
        $this->assertContains('script_name', array_keys($tool['inputSchema']['properties']));
        $this->assertContains('policy_id', array_keys($tool['inputSchema']['properties']));
        $this->assertContains('client_id', array_keys($tool['inputSchema']['properties']));
        $this->assertContains('success_return_codes', array_keys($tool['inputSchema']['properties']));
        $this->assertContains('confirm_policy_name', array_keys($tool['inputSchema']['properties']));
    }

    public function test_create_policy_script_check_resolves_policy_and_script_confirms_and_redacts_audit(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        TacticalScript::create([
            'tactical_script_id' => 102,
            'name' => 'HelpDesk Buttons Detector',
            'description' => 'Detector',
            'shell' => 'powershell',
            'category' => 'Self-Heal',
            'synced_at' => now(),
        ]);
        $token = $this->token(['tactical_create_check']);

        $badReturnCodes = $this->callTool($token, 'tactical_create_check', [
            'reason' => 'Reject malformed return codes.',
            'policy_id' => 7,
            'confirm_policy_name' => 'Workstations',
            'script_name' => 'HelpDesk Buttons Detector',
            'success_return_codes' => ['nope'],
        ]);
        $this->assertTrue((bool) $badReturnCodes->json('result.isError'));
        $this->assertStringContainsString('success_return_codes must be a list of non-negative integers', (string) $badReturnCodes->json('result.content.0.text'));

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getPolicies')->once()->andReturn($this->policies());
        $tactical->shouldReceive('getScripts')->once()->with(true, true)->andReturn($this->scripts());
        $tactical->shouldReceive('createCheck')->once()->with([
            'policy' => 7,
            'check_type' => 'script',
            'script' => 102,
            'name' => 'HelpDesk Buttons desktop icon detector',
            'fails_b4_alert' => 2,
            'timeout' => 90,
            'script_args' => ['--path', 'C:\\Users\\Public\\Desktop\\HelpDesk.url'],
            'env_vars' => ['SECRET_TOKEN=hidden'],
            'success_return_codes' => [0],
            'info_return_codes' => [10],
            'warning_return_codes' => [7],
        ])->andReturn('Script Check: HelpDesk Buttons Detector was added!');
        $tactical->shouldReceive('getPolicyChecks')->once()->with(7)->andReturn([
            [
                'id' => 212,
                'policy' => 7,
                'check_type' => 'script',
                'script' => 102,
                'name' => 'HelpDesk Buttons desktop icon detector',
                'success_return_codes' => [0],
                'info_return_codes' => [10],
                'warning_return_codes' => [7],
            ],
        ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($token, 'tactical_create_check', [
            'reason' => 'Create detector check.',
            'policy_id' => 7,
            'confirm_policy_name' => 'Workstations',
            'script_id' => 1,
            'name' => 'HelpDesk Buttons desktop icon detector',
            'fails_b4_alert' => 2,
            'timeout' => 90,
            'script_args' => ['--path', 'C:\\Users\\Public\\Desktop\\HelpDesk.url'],
            'env_vars' => ['SECRET_TOKEN=hidden'],
            'success_return_codes' => [0],
            'info_return_codes' => [10],
            'warning_return_codes' => [7],
        ]);

        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $payload = json_decode((string) $response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(212, $payload['check_id']);
        $this->assertSame('policy', $payload['target_type']);

        $auditJson = McpAuditLog::query()
            ->where('tool_name', 'tactical_create_check')
            ->get()
            ->map(fn (McpAuditLog $log): string => json_encode($log->arguments, JSON_THROW_ON_ERROR))
            ->implode("\n");
        $this->assertStringContainsString('script_args_count', $auditJson);
        $this->assertStringContainsString('env_vars_count', $auditJson);
        $this->assertStringNotContainsString('SECRET_TOKEN', $auditJson);
        $this->assertStringNotContainsString('HelpDesk.url', $auditJson);

        $summary = TechnicianActionLog::query()
            ->where('action_type', 'tactical_create_check')
            ->latest('id')
            ->value('summary');
        $this->assertStringContainsString('Created Tactical script check', (string) $summary);
        $this->assertStringNotContainsString('SECRET_TOKEN', (string) $summary);
    }

    public function test_create_agent_script_check_requires_client_scope_and_hostname_confirmation(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->endpointFixture();
        TacticalScript::create([
            'tactical_script_id' => 102,
            'name' => 'HelpDesk Buttons Detector',
            'shell' => 'powershell',
            'synced_at' => now(),
        ]);
        $token = $this->token(['tactical_create_check']);

        $missingClient = $this->callTool($token, 'tactical_create_check', [
            'reason' => 'Agent check needs a PSA client scope.',
            'hostname' => 'PC-01',
            'confirm_hostname' => 'PC-01',
            'script_name' => 'HelpDesk Buttons Detector',
        ]);
        $this->assertTrue((bool) $missingClient->json('result.isError'));
        $this->assertStringContainsString('client_id is required when creating an agent check', (string) $missingClient->json('result.content.0.text'));

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)->andReturn($this->scripts());
        $tactical->shouldReceive('createCheck')->once()->with([
            'agent' => 'agent-1',
            'check_type' => 'script',
            'script' => 102,
            'fails_b4_alert' => 1,
            'success_return_codes' => [0],
            'info_return_codes' => [],
            'warning_return_codes' => [],
        ])->andReturn('Script Check: HelpDesk Buttons Detector was added!');
        $tactical->shouldReceive('getAgentChecks')->once()->with('agent-1')->andReturn([
            ['id' => 310, 'agent' => 55, 'check_type' => 'script', 'script' => 102],
        ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($token, 'tactical_create_check', [
            'client_id' => $fixture['client']->id,
            'reason' => 'Create one endpoint detector.',
            'hostname' => 'PC-01',
            'confirm_hostname' => 'PC-01',
            'script_name' => 'HelpDesk Buttons Detector',
        ]);

        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $payload = json_decode((string) $response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(310, $payload['check_id']);
        $this->assertSame('agent', $payload['target_type']);
    }
}
