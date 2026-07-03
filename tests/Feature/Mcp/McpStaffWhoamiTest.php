<?php

namespace Tests\Feature\Mcp;

use App\Models\McpToken;
use App\Models\Setting;
use App\Models\User;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class McpStaffWhoamiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->create();
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

    public function test_whoami_is_listed_for_scoped_tokens_without_explicit_grant(): void
    {
        $plain = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');

        $names = $this->listToolNames($plain);

        $this->assertContains('whoami', $names);
        $this->assertContains('find_staff', $names);
        $this->assertNotContains('get_staff', $names);
    }

    public function test_whoami_returns_label_directive_allowed_tools_and_posture(): void
    {
        Setting::setValue('agent_enabled', '1');
        Setting::setValue('technician_kill_switch', '1');
        Setting::setValue('propose_close_auto_threshold', '0.95');

        $plain = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        McpToken::where('label', 'chet')->update(['directive' => 'You are the Chet bridge token.']);

        $response = $this->callTool($plain, 'whoami');

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'));
        $payload = json_decode((string) $response->json('result.content.0.text'), true);

        $this->assertSame('chet', $payload['label']);
        $this->assertSame('You are the Chet bridge token.', $payload['directive']);
        $this->assertSame(['whoami', 'find_staff'], $payload['allowed_tools']);
        $this->assertTrue($payload['posture']['agent_enabled']);
        $this->assertTrue($payload['posture']['kill_switch']);
        $this->assertTrue($payload['posture']['held_only']);
        $this->assertTrue($payload['posture']['auto_close_enabled']);
        $this->assertSame(0.95, $payload['posture']['auto_close_threshold']);
        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'tools/call',
            'tool_name' => 'whoami',
            'status' => 'success',
            'actor_label' => 'mcp-staff:chet',
        ]);
    }

    public function test_whoami_reports_held_only_when_auto_close_threshold_is_null(): void
    {
        $plain = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');

        $payload = json_decode((string) $this->callTool($plain, 'whoami')->json('result.content.0.text'), true);

        $this->assertTrue($payload['posture']['held_only']);
        $this->assertFalse($payload['posture']['auto_close_enabled']);
        $this->assertNull($payload['posture']['auto_close_threshold']);
    }

    public function test_whoami_uses_non_colliding_legacy_label_for_labelless_token(): void
    {
        $legacyPlain = McpConfig::rotateStaffToken();
        $scopedPlain = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'legacy');

        $legacyPayload = json_decode((string) $this->callTool($legacyPlain, 'whoami')->json('result.content.0.text'), true);
        $scopedPayload = json_decode((string) $this->callTool($scopedPlain, 'whoami')->json('result.content.0.text'), true);

        $this->assertSame('mcp-legacy', $legacyPayload['label']);
        $this->assertNull($legacyPayload['allowed_tools']);
        $this->assertSame('legacy', $scopedPayload['label']);
        $this->assertSame(['whoami', 'find_staff'], $scopedPayload['allowed_tools']);

        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'tools/call',
            'tool_name' => 'whoami',
            'status' => 'success',
            'actor_label' => 'mcp-legacy',
        ]);
        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'tools/call',
            'tool_name' => 'whoami',
            'status' => 'success',
            'actor_label' => 'mcp-staff:legacy',
        ]);
    }
}
