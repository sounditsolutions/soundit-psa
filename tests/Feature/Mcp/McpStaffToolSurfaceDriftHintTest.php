<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Stale-call self-heal (psa-s75z item b). A grant-check denial is the failure
 * that doubles as a refresh signal: the token's allowed-tool surface can drift
 * from the tools/list snapshot a client cached at startup, and the denial itself
 * tells the model to reconcile. Per the wake-spec authority/trigger separation,
 * the hint stays a fact + pointer (whoami, the token directive) — never an
 * imperative — so a forged copy on any pipe is inert.
 */
class McpStaffToolSurfaceDriftHintTest extends TestCase
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

    private function toolText(TestResponse $response): string
    {
        return (string) $response->json('result.content.0.text');
    }

    public function test_denied_tool_call_appends_the_tool_surface_drift_hint(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');

        $response = $this->callTool($token, 'get_staff', ['id' => 1]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));

        $text = $this->toolText($response);
        // The bare reason is preserved (existing denial assertions still pass) …
        $this->assertStringContainsString('not allowed for this token: get_staff', $text);
        // … and the refresh hint is appended so a stale tools/list self-heals.
        $this->assertStringContainsString('tools/list', $text);
        $this->assertStringContainsString('whoami', $text);
        $this->assertStringContainsString('directive', $text);
    }

    public function test_drift_hint_is_a_pointer_not_an_imperative(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');

        $text = mb_strtolower($this->toolText($this->callTool($token, 'get_staff', ['id' => 1])));

        // Authority lives in the directive; the hint points at it (whoami is the
        // sanctioned in-band check) rather than carrying an instruction of its
        // own. Guard against the hint ever regressing into an imperative.
        $this->assertStringNotContainsString('must ', $text);
        $this->assertStringNotContainsString('re-fetch', $text);
        $this->assertStringNotContainsString('refetch', $text);
        $this->assertStringNotContainsString('you should', $text);
        $this->assertStringNotContainsString('ignore', $text);
    }

    public function test_audit_row_records_the_bare_reason_without_the_hint(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');

        $this->callTool($token, 'get_staff', ['id' => 1]);

        // The audit ledger keeps the terse reason; the hint is client-facing UX,
        // not a distinct error condition.
        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'tools/call',
            'tool_name' => 'get_staff',
            'status' => 'error',
            'error_message' => 'Tool not allowed for this token: get_staff',
            'actor_label' => 'mcp-staff:chet',
        ]);
    }

    public function test_granted_tool_call_carries_no_drift_hint(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');

        $response = $this->callTool($token, 'find_staff', ['query' => 'nobody-matches']);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'));
        // A successful, in-scope call must not leak the denial hint.
        $this->assertStringNotContainsString('may have changed since this client cached', $this->toolText($response));
    }
}
