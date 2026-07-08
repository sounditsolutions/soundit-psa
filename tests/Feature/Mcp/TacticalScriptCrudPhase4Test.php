<?php

namespace Tests\Feature\Mcp;

use App\Models\McpAuditLog;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class TacticalScriptCrudPhase4Test extends TestCase
{
    use RefreshDatabase;

    private const SCRIPT_TOOLS = [
        'tactical_list_global_scripts',
        'tactical_create_script',
        'tactical_get_script_detail',
        'tactical_update_script',
        'tactical_delete_script',
        'tactical_download_script',
    ];

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

    /** @return array<int, array<string, mixed>> */
    private function scripts(): array
    {
        return [
            [
                'id' => 101,
                'name' => 'Community Cleanup',
                'description' => 'Built in cleanup',
                'script_type' => 'builtin',
                'shell' => 'powershell',
                'category' => 'Community',
                'favorite' => false,
                'hidden' => false,
                'default_timeout' => 90,
                'args' => [],
                'env_vars' => [],
                'supported_platforms' => ['windows'],
            ],
            [
                'id' => 102,
                'name' => 'Deploy App',
                'description' => 'Deploys the app',
                'script_type' => 'userdefined',
                'shell' => 'powershell',
                'category' => 'Ops',
                'favorite' => false,
                'hidden' => false,
                'default_timeout' => 120,
                'args' => ['-Mode', 'install'],
                'env_vars' => ['API_TOKEN=super-secret-env'],
                'supported_platforms' => ['windows'],
            ],
        ];
    }

    public function test_script_crud_tools_are_sensitive_global_and_explicit_grant_only(): void
    {
        $this->configureTactical();

        $groups = McpToolRegistry::groups();
        $adminNames = array_column($groups['tactical_admin']['tools'], 'name');

        foreach (self::SCRIPT_TOOLS as $tool) {
            $this->assertContains($tool, $adminNames, "{$tool} should be a sensitive Tactical admin tool");
            $this->assertContains($tool, McpToolRegistry::allToolNames(), "{$tool} should be token-grantable");
        }

        $legacyNames = array_column($this->listTools($this->legacyToken()), 'name');
        foreach (self::SCRIPT_TOOLS as $tool) {
            $this->assertNotContains($tool, $legacyNames, "legacy full-surface token must not gain {$tool}");
        }

        $scoped = collect($this->listTools($this->token([
            'tactical_list_global_scripts',
            'tactical_delete_script',
            'tactical_download_script',
        ])))->keyBy('name');

        $this->assertNotContains('client_id', $scoped['tactical_list_global_scripts']['inputSchema']['required'] ?? []);
        $this->assertNotContains('client_id', $scoped['tactical_delete_script']['inputSchema']['required'] ?? []);
        $this->assertStringContainsString('global Tactical script catalog', $scoped['tactical_list_global_scripts']['description']);
        $this->assertStringContainsString('cannot be undone', $scoped['tactical_delete_script']['description']);
        $this->assertContains('confirm_script_name', $scoped['tactical_download_script']['inputSchema']['required']);
    }

    public function test_create_update_and_delete_are_source_shaped_and_redact_script_body(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $token = $this->token(['tactical_create_script', 'tactical_update_script', 'tactical_delete_script']);

        $rejected = $this->callTool($token, 'tactical_update_script', [
            'tactical_script_id' => 102,
            'script_name' => 'Deploy App',
            'reason' => 'Caller supplied upstream script id.',
            'hidden' => true,
        ]);
        $this->assertTrue((bool) $rejected->json('result.isError'));
        $this->assertStringContainsString('upstream Tactical identifiers are not accepted', (string) $rejected->json('result.content.0.text'));

        $badShell = $this->callTool($token, 'tactical_create_script', [
            'name' => 'Deploy App',
            'shell' => 'ruby',
            'script_body' => 'puts ENV["SECRET"]',
            'reason' => 'Bad shell should fail before Tactical.',
        ]);
        $this->assertTrue((bool) $badShell->json('result.isError'));
        $this->assertStringContainsString('shell must be one of', (string) $badShell->json('result.content.0.text'));

        $badField = $this->callTool($token, 'tactical_create_script', [
            'name' => 'Deploy App',
            'shell' => 'powershell',
            'script_body' => 'Write-Host ok',
            'reason' => 'Reject broad serializer fields.',
            'script_type' => 'builtin',
        ]);
        $this->assertTrue((bool) $badField->json('result.isError'));
        $this->assertStringContainsString('Unsupported script fields', (string) $badField->json('result.content.0.text'));

        $badSelector = $this->callTool($token, 'tactical_create_script', [
            'script_id' => 102,
            'name' => 'Deploy App',
            'shell' => 'powershell',
            'script_body' => 'Write-Host ok',
            'reason' => 'Create should not accept selector fields.',
        ]);
        $this->assertTrue((bool) $badSelector->json('result.isError'));
        $this->assertStringContainsString('Unsupported script fields: script_id', (string) $badSelector->json('result.content.0.text'));

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('createScript')->once()->with([
            'name' => 'Deploy App',
            'shell' => 'powershell',
            'script_body' => 'Write-Host "SECRET_TOKEN=abc123"',
            'args' => ['-Mode', 'install'],
            'env_vars' => ['API_TOKEN=super-secret-env'],
            'default_timeout' => 120,
        ])->andReturn('Deploy App was added!');
        $tactical->shouldReceive('getScripts')->twice()->with(true, true)->andReturn($this->scripts());
        $tactical->shouldReceive('updateScript')->once()->with(102, [
            'description' => 'Updated safely',
            'script_body' => 'Write-Host "NEW_SECRET=xyz"',
        ])->andReturn('Deploy App was edited!');
        $tactical->shouldReceive('deleteScript')->once()->with(102)->andReturn('Deploy App was deleted!');
        $this->app->instance(TacticalClient::class, $tactical);

        $created = $this->callTool($token, 'tactical_create_script', [
            'name' => 'Deploy App',
            'shell' => 'powershell',
            'script_body' => 'Write-Host "SECRET_TOKEN=abc123"',
            'args' => ['-Mode', 'install'],
            'env_vars' => ['API_TOKEN=super-secret-env'],
            'default_timeout' => 120,
            'reason' => 'Create global deployment script.',
        ]);
        $this->assertFalse((bool) $created->json('result.isError'), (string) $created->json('result.content.0.text'));

        $updated = $this->callTool($token, 'tactical_update_script', [
            'script_name' => 'Deploy App',
            'description' => 'Updated safely',
            'script_body' => 'Write-Host "NEW_SECRET=xyz"',
            'reason' => 'Update global deployment script body.',
        ]);
        $this->assertFalse((bool) $updated->json('result.isError'), (string) $updated->json('result.content.0.text'));

        $deleted = $this->callTool($token, 'tactical_delete_script', [
            'script_name' => 'Deploy App',
            'confirm_script_name' => 'deploy app',
            'reason' => 'Delete obsolete global script.',
        ]);
        $this->assertFalse((bool) $deleted->json('result.isError'), (string) $deleted->json('result.content.0.text'));

        $mcpAuditJson = McpAuditLog::query()
            ->whereIn('tool_name', ['tactical_create_script', 'tactical_update_script'])
            ->get()
            ->map(fn (McpAuditLog $log): string => json_encode($log->arguments, JSON_THROW_ON_ERROR))
            ->implode("\n");
        $this->assertStringNotContainsString('SECRET_TOKEN', $mcpAuditJson);
        $this->assertStringNotContainsString('NEW_SECRET', $mcpAuditJson);
        $this->assertStringNotContainsString('super-secret-env', $mcpAuditJson);
        $this->assertStringContainsString('script_body_length', $mcpAuditJson);

        $technicianSummaries = TechnicianActionLog::query()
            ->whereIn('action_type', ['tactical_create_script', 'tactical_update_script'])
            ->pluck('summary')
            ->implode("\n");
        $this->assertStringNotContainsString('SECRET_TOKEN', $technicianSummaries);
        $this->assertStringNotContainsString('NEW_SECRET', $technicianSummaries);
        $this->assertStringNotContainsString('super-secret-env', $technicianSummaries);
    }

    public function test_builtin_scripts_only_allow_favorite_or_hidden_and_cannot_delete(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $token = $this->token(['tactical_update_script', 'tactical_delete_script']);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getScripts')->times(3)->with(true, true)->andReturn($this->scripts());
        $tactical->shouldReceive('updateScript')->once()->with(101, ['favorite' => true])->andReturn('Community Cleanup was edited!');
        $tactical->shouldNotReceive('deleteScript');
        $this->app->instance(TacticalClient::class, $tactical);

        $notAllowed = $this->callTool($token, 'tactical_update_script', [
            'script_name' => 'Community Cleanup',
            'description' => 'Should not edit builtin metadata',
            'reason' => 'Attempt protected edit.',
        ]);
        $this->assertTrue((bool) $notAllowed->json('result.isError'));
        $this->assertStringContainsString('builtin/community scripts can only change favorite or hidden', (string) $notAllowed->json('result.content.0.text'));

        $favorite = $this->callTool($token, 'tactical_update_script', [
            'script_name' => 'Community Cleanup',
            'favorite' => true,
            'script_body' => 'malicious body ignored',
            'reason' => 'Favorite builtin script.',
        ]);
        $this->assertFalse((bool) $favorite->json('result.isError'), (string) $favorite->json('result.content.0.text'));

        $delete = $this->callTool($token, 'tactical_delete_script', [
            'script_name' => 'Community Cleanup',
            'confirm_script_name' => 'Community Cleanup',
            'reason' => 'Try deleting protected script.',
        ]);
        $this->assertTrue((bool) $delete->json('result.isError'));
        $this->assertStringContainsString('builtin/community scripts cannot be deleted', (string) $delete->json('result.content.0.text'));
    }

    public function test_detail_redacts_body_and_download_requires_typed_confirm(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $token = $this->token(['tactical_get_script_detail', 'tactical_download_script']);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getScripts')->times(3)->with(true, true)->andReturn($this->scripts());
        $tactical->shouldReceive('getScriptDetail')->once()->with(102)->andReturn([
            'id' => 102,
            'name' => 'Deploy App',
            'shell' => 'powershell',
            'script_body' => 'Write-Host "EXISTING_SECRET=keepme"',
            'env_vars' => ['API_TOKEN=super-secret-env'],
        ]);
        $tactical->shouldReceive('downloadScript')->once()->with(102, false)->andReturn([
            'filename' => 'Deploy App.ps1',
            'code' => 'Write-Host "EXISTING_SECRET=keepme"',
        ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $detail = $this->callTool($token, 'tactical_get_script_detail', [
            'script_name' => 'Deploy App',
            'reason' => 'Review script metadata only.',
        ]);
        $this->assertFalse((bool) $detail->json('result.isError'), (string) $detail->json('result.content.0.text'));
        $detailResult = $this->decodedResult($detail);
        $this->assertSame('[script body withheld]', $detailResult['script_body']);
        $this->assertSame(35, $detailResult['script_body_length']);
        $this->assertStringNotContainsString('EXISTING_SECRET', (string) $detail->json('result.content.0.text'));

        $missingConfirm = $this->callTool($token, 'tactical_download_script', [
            'script_name' => 'Deploy App',
            'reason' => 'Try body read without typed confirm.',
        ]);
        $this->assertTrue((bool) $missingConfirm->json('result.isError'));
        $this->assertStringContainsString('typed script name', (string) $missingConfirm->json('result.content.0.text'));

        $download = $this->callTool($token, 'tactical_download_script', [
            'script_name' => 'Deploy App',
            'confirm_script_name' => 'Deploy App',
            'with_snippets' => false,
            'reason' => 'Explicitly retrieve script body for review.',
        ]);
        $this->assertFalse((bool) $download->json('result.isError'), (string) $download->json('result.content.0.text'));
        $downloadResult = $this->decodedResult($download);
        $this->assertSame('Deploy App.ps1', $downloadResult['filename']);
        $this->assertStringContainsString('EXISTING_SECRET', $downloadResult['code']);

        $auditJson = McpAuditLog::query()
            ->whereIn('tool_name', ['tactical_get_script_detail', 'tactical_download_script'])
            ->get()
            ->map(fn (McpAuditLog $log): string => json_encode($log->arguments, JSON_THROW_ON_ERROR))
            ->implode("\n");
        $this->assertStringNotContainsString('EXISTING_SECRET', $auditJson);
    }
}
