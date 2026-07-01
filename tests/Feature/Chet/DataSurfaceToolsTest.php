<?php

namespace Tests\Feature\Chet;

use App\Models\Asset;
use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Services\Cipp\CippClient;
use App\Services\Graph\GraphClient;
use App\Services\Tactical\TacticalClient;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class DataSurfaceToolsTest extends TestCase
{
    use RefreshDatabase;

    private function chetToken(array $tools): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: 'chet');
    }

    private function callTool(string $token, string $name, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** @return array<string, mixed> */
    private function decodedResult(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    /** @return array<int, string> */
    private function listToolNames(string $token): array
    {
        return collect($this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ])
            ->json('result.tools'))->pluck('name')->all();
    }

    private function configureCipp(): void
    {
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_client_id', 'client-1');
        Setting::setEncrypted('cipp_client_secret', 'secret');
    }

    private function configureTactical(): void
    {
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'secret');
    }

    private function configureTeamsBot(): void
    {
        Setting::setValue('teams_bot_app_id', 'bot-app-id');
        Setting::setValue('teams_bot_tenant_id', 'tenant-1');
    }

    public function test_chet_lists_only_scoped_read_only_data_surface_tools(): void
    {
        $this->configureCipp();
        $this->configureTactical();
        $this->configureTeamsBot();

        $token = $this->chetToken([
            'cipp_list_users',
            'tactical_get_device',
            'list_teams_chats',
            'get_teams_chat_members',
            'get_teams_chat_history',
        ]);

        $names = $this->listToolNames($token);

        $this->assertContains('cipp_list_users', $names);
        $this->assertContains('tactical_get_device', $names);
        $this->assertContains('list_teams_chats', $names);
        $this->assertContains('get_teams_chat_members', $names);
        $this->assertContains('get_teams_chat_history', $names);
        $this->assertNotContains('tactical_run_diagnostic', $names);

        $readOnlyToken = $this->chetToken(['find_staff']);
        $readOnlyNames = $this->listToolNames($readOnlyToken);
        $this->assertNotContains('cipp_list_users', $readOnlyNames);
        $this->assertNotContains('tactical_get_device', $readOnlyNames);
        $this->assertNotContains('list_teams_chats', $readOnlyNames);
    }

    public function test_legacy_full_surface_token_does_not_gain_new_data_surface_tools(): void
    {
        $this->configureTactical();
        $this->configureTeamsBot();

        $legacyToken = McpConfig::rotateStaffToken();

        $names = $this->listToolNames($legacyToken);
        $this->assertNotContains('tactical_get_device', $names);
        $this->assertNotContains('list_teams_chats', $names);
        $this->assertNotContains('get_teams_chat_history', $names);

        $response = $this->callTool($legacyToken, 'list_teams_chats', []);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('not allowed for this token', (string) $response->json('result.content.0.text'));
    }

    public function test_chet_can_call_cipp_read_tool_when_token_scoped_and_client_mapped(): void
    {
        $this->configureCipp();

        $client = Client::factory()->create(['cipp_tenant_domain' => 'acme.example']);
        $token = $this->chetToken(['cipp_list_users']);

        $cipp = Mockery::mock(CippClient::class);
        $cipp->shouldReceive('get')
            ->once()
            ->with('api/ListUsers', ['TenantFilter' => 'acme.example'])
            ->andReturn([
                ['userPrincipalName' => 'alex@acme.example', 'displayName' => 'Alex Acme'],
            ]);
        $this->app->instance(CippClient::class, $cipp);

        $response = $this->callTool($token, 'cipp_list_users', ['client_id' => $client->id]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertSame('alex@acme.example', $this->decodedResult($response)[0]['userPrincipalName']);

        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'tools/call',
            'tool_name' => 'cipp_list_users',
            'status' => 'success',
            'actor_label' => 'mcp-staff:chet',
        ]);
    }

    public function test_chet_can_read_tactical_device_for_client_scoped_asset(): void
    {
        $this->configureTactical();

        $client = Client::factory()->create();
        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'PC-01',
        ]);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'agent-1',
            'hostname' => 'PC-01',
        ]);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getAgent')
            ->once()
            ->with('agent-1')
            ->andReturn([
                'hostname' => 'PC-01',
                'status' => 'online',
                'operating_system' => 'Windows 11 Pro',
                'total_ram' => 16,
                'checks' => ['failing' => 1, 'total' => 8],
                'logged_in_username' => 'ACME\\alex',
            ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $token = $this->chetToken(['tactical_get_device']);
        $response = $this->callTool($token, 'tactical_get_device', [
            'client_id' => $client->id,
            'hostname' => 'pc-01',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $result = $this->decodedResult($response);
        $this->assertSame('PC-01', $result['hostname']);
        $this->assertSame('online', $result['status']);
        $this->assertSame('1 failing / 8 total', $result['checks_summary']);

        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'tools/call',
            'tool_name' => 'tactical_get_device',
            'status' => 'success',
            'actor_label' => 'mcp-staff:chet',
        ]);
    }

    public function test_tactical_read_respects_client_scope(): void
    {
        $this->configureTactical();

        $requestingClient = Client::factory()->create();
        $otherClient = Client::factory()->create();
        $otherAsset = Asset::factory()->create([
            'client_id' => $otherClient->id,
            'hostname' => 'PC-02',
        ]);
        TacticalAsset::create([
            'asset_id' => $otherAsset->id,
            'agent_id' => 'agent-2',
            'hostname' => 'PC-02',
        ]);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldNotReceive('getAgent');
        $this->app->instance(TacticalClient::class, $tactical);

        $token = $this->chetToken(['tactical_get_device']);
        $response = $this->callTool($token, 'tactical_get_device', [
            'client_id' => $requestingClient->id,
            'hostname' => 'PC-02',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('not found or belongs to a different client', (string) $response->json('result.content.0.text'));
    }

    public function test_chet_cannot_call_tactical_diagnostic_even_if_accidentally_scoped(): void
    {
        $this->configureTactical();

        $token = $this->chetToken(['tactical_run_diagnostic']);

        $this->assertNotContains('tactical_run_diagnostic', $this->listToolNames($token));

        $response = $this->callTool($token, 'tactical_run_diagnostic', [
            'client_id' => Client::factory()->create()->id,
            'hostname' => 'PC-01',
            'diagnostic' => 'disk_health',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('not allowed for this token', (string) $response->json('result.content.0.text'));
    }

    public function test_list_teams_chats_returns_only_chats_where_bot_is_member(): void
    {
        $this->configureTeamsBot();

        $graph = Mockery::mock(GraphClient::class);
        $graph->shouldReceive('getAllPages')
            ->once()
            ->withArgs(fn (string $endpoint): bool => $endpoint === 'users/bot-app-id/chats')
            ->andReturn([
                ['id' => 'chat-allowed', 'topic' => 'Ops', 'chatType' => 'group'],
                ['id' => 'chat-denied', 'topic' => 'Private', 'chatType' => 'group'],
            ]);
        $graph->shouldReceive('getAllPages')
            ->once()
            ->withArgs(fn (string $endpoint): bool => $endpoint === 'chats/chat-allowed/members')
            ->andReturn([
                ['displayName' => 'Chet', 'applicationId' => 'bot-app-id', 'tenantId' => 'tenant-1'],
            ]);
        $graph->shouldReceive('getAllPages')
            ->once()
            ->withArgs(fn (string $endpoint): bool => $endpoint === 'chats/chat-denied/members')
            ->andReturn([
                ['displayName' => 'Ada Admin', 'userId' => 'human-user-id', 'tenantId' => 'tenant-1'],
            ]);
        $this->app->instance(GraphClient::class, $graph);

        $token = $this->chetToken(['list_teams_chats']);
        $response = $this->callTool($token, 'list_teams_chats', ['limit' => 10]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $result = $this->decodedResult($response);
        $this->assertSame(1, $result['count']);
        $this->assertSame('chat-allowed', $result['chats'][0]['id']);
    }

    public function test_teams_chat_history_requires_bot_membership_before_reading_messages(): void
    {
        $this->configureTeamsBot();

        $graph = Mockery::mock(GraphClient::class);
        $graph->shouldReceive('getAllPages')
            ->once()
            ->withArgs(fn (string $endpoint): bool => $endpoint === 'chats/chat-1/members')
            ->andReturn([
                ['displayName' => 'Ada Admin', 'userId' => 'human-user-id', 'tenantId' => 'tenant-1'],
            ]);
        $graph->shouldNotReceive('getAllPages')
            ->withArgs(fn (string $endpoint): bool => $endpoint === 'chats/chat-1/messages');
        $this->app->instance(GraphClient::class, $graph);

        $token = $this->chetToken(['get_teams_chat_history']);
        $response = $this->callTool($token, 'get_teams_chat_history', [
            'chat_id' => 'chat-1',
            'limit' => 5,
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('bot is not a member', (string) $response->json('result.content.0.text'));
    }

    public function test_teams_chat_members_returns_members_after_membership_gate_passes(): void
    {
        $this->configureTeamsBot();

        $graph = Mockery::mock(GraphClient::class);
        $graph->shouldReceive('getAllPages')
            ->once()
            ->withArgs(fn (string $endpoint): bool => $endpoint === 'chats/chat-1/members')
            ->andReturn([
                ['displayName' => 'Chet', 'applicationId' => 'bot-app-id', 'tenantId' => 'tenant-1'],
                ['displayName' => 'Ada Admin', 'userId' => 'human-user-id', 'email' => 'ada@example.test', 'tenantId' => 'tenant-1'],
            ]);
        $this->app->instance(GraphClient::class, $graph);

        $token = $this->chetToken(['get_teams_chat_members']);
        $response = $this->callTool($token, 'get_teams_chat_members', [
            'chat_id' => 'chat-1',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $result = $this->decodedResult($response);
        $this->assertSame('chat-1', $result['chat_id']);
        $this->assertSame(2, $result['count']);
        $this->assertSame('Ada Admin', $result['members'][1]['display_name']);
        $this->assertSame('ada@example.test', $result['members'][1]['email']);
    }

    public function test_teams_chat_history_returns_messages_after_membership_gate_passes(): void
    {
        $this->configureTeamsBot();

        $graph = Mockery::mock(GraphClient::class);
        $graph->shouldReceive('getAllPages')
            ->once()
            ->withArgs(fn (string $endpoint): bool => $endpoint === 'chats/chat-1/members')
            ->andReturn([
                ['displayName' => 'Chet', 'applicationId' => 'bot-app-id', 'tenantId' => 'tenant-1'],
                ['displayName' => 'Ada Admin', 'userId' => 'human-user-id', 'tenantId' => 'tenant-1'],
            ]);
        $graph->shouldReceive('getAllPages')
            ->once()
            ->withArgs(fn (string $endpoint, array $params): bool => $endpoint === 'chats/chat-1/messages'
                && ($params['$top'] ?? null) === 2)
            ->andReturn([
                [
                    'id' => 'message-1',
                    'createdDateTime' => '2026-07-01T12:00:00Z',
                    'from' => ['user' => ['id' => 'human-user-id', 'displayName' => 'Ada Admin']],
                    'body' => ['contentType' => 'html', 'content' => '<p>Hello <b>Chet</b></p>'],
                ],
            ]);
        $this->app->instance(GraphClient::class, $graph);

        $token = $this->chetToken(['get_teams_chat_history']);
        $response = $this->callTool($token, 'get_teams_chat_history', [
            'chat_id' => 'chat-1',
            'limit' => 2,
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $result = $this->decodedResult($response);
        $this->assertSame('chat-1', $result['chat_id']);
        $this->assertSame(1, $result['count']);
        $this->assertSame('Ada Admin', $result['messages'][0]['from']['display_name']);
        $this->assertSame('Hello Chet', $result['messages'][0]['body']);

        $audit = McpAuditLog::where('tool_name', 'get_teams_chat_history')->firstOrFail();
        $this->assertSame('success', $audit->status);
        $this->assertSame('mcp-staff:chet', $audit->actor_label);
    }
}
