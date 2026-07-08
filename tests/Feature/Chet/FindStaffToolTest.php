<?php

namespace Tests\Feature\Chet;

use App\Models\User;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FindStaffToolTest extends TestCase
{
    use RefreshDatabase;

    private function chetToken(): string
    {
        return McpConfig::rotateStaffToken(allowedTools: ['find_staff', 'get_staff'], label: 'chet');
    }

    private function callTool(string $token, string $name, array $args): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $args],
            ]);
    }

    private function decodedResult(TestResponse $r): array
    {
        return json_decode((string) $r->json('result.content.0.text'), true) ?? [];
    }

    /** @return array<int, string> */
    private function listToolNames(string $token): array
    {
        return collect($this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []])
            ->json('result.tools'))->pluck('name')->all();
    }

    public function test_find_staff_returns_matches_with_oid_and_active_flag(): void
    {
        $token = $this->chetToken();
        $alice = User::factory()->create(['name' => 'Alice Ops', 'email' => 'alice@soundit.co', 'microsoft_id' => 'oid-alice', 'is_active' => true]);
        User::factory()->create(['name' => 'Zed Zephyr', 'email' => 'zed@soundit.co', 'is_active' => true]);

        $r = $this->callTool($token, 'find_staff', ['query' => 'alice']);

        $r->assertOk();
        $this->assertFalse((bool) $r->json('result.isError'));
        $out = $this->decodedResult($r);
        $this->assertCount(1, $out['staff']);
        $this->assertSame($alice->id, $out['staff'][0]['id']);
        $this->assertSame('oid-alice', $out['staff'][0]['microsoft_id']);
        $this->assertTrue($out['staff'][0]['is_active']);
    }

    public function test_find_staff_matches_inactive_users_too(): void
    {
        $token = $this->chetToken();
        User::factory()->create(['name' => 'Bob Gone', 'email' => 'bob@soundit.co', 'is_active' => false]);

        $out = $this->decodedResult($this->callTool($token, 'find_staff', ['query' => 'bob']));

        $this->assertCount(1, $out['staff']);
        $this->assertFalse($out['staff'][0]['is_active']);
    }

    public function test_get_staff_returns_the_user_or_an_error(): void
    {
        $token = $this->chetToken();
        $u = User::factory()->create(['name' => 'Carol', 'microsoft_id' => 'oid-carol']);

        $out = $this->decodedResult($this->callTool($token, 'get_staff', ['id' => $u->id]));
        $this->assertSame($u->id, $out['id']);
        $this->assertSame('oid-carol', $out['microsoft_id']);

        $missing = $this->decodedResult($this->callTool($token, 'get_staff', ['id' => 999999]));
        $this->assertArrayHasKey('error', $missing);
    }

    public function test_get_staff_does_not_leak_person_contact_fields(): void
    {
        $token = $this->chetToken();
        $u = User::factory()->create();

        $out = $this->decodedResult($this->callTool($token, 'get_staff', ['id' => $u->id]));

        $this->assertSame(['id', 'name', 'email', 'microsoft_id', 'is_active'], array_keys($out));
    }

    public function test_a_pack_token_without_find_staff_scope_is_denied(): void
    {
        $pack = McpConfig::rotateStaffToken(allowedTools: ['poll_operator_messages'], label: 'office-teams-pack');

        $r = $this->callTool($pack, 'find_staff', ['query' => 'alice']);

        $r->assertOk();
        $this->assertTrue((bool) $r->json('result.isError'));
        $this->assertStringContainsString('not allowed for this token', (string) $r->json('result.content.0.text'));
        $this->assertNotContains('find_staff', $this->listToolNames($pack));
    }

    public function test_legacy_full_surface_tokens_do_not_get_new_bridge_tools_by_default(): void
    {
        $legacy = McpConfig::rotateStaffToken();

        $r = $this->callTool($legacy, 'find_staff', ['query' => 'alice']);

        $r->assertOk();
        $this->assertTrue((bool) $r->json('result.isError'));
        $this->assertStringContainsString('not allowed for this token', (string) $r->json('result.content.0.text'));
        $this->assertNotContains('find_staff', $this->listToolNames($legacy));
    }

    public function test_tools_list_shows_find_staff_only_to_tokens_that_allow_it(): void
    {
        $chet = $this->chetToken();

        $names = $this->listToolNames($chet);

        $this->assertContains('find_staff', $names);
        $this->assertContains('get_staff', $names);
        $this->assertNotContains('create_ticket', $names);
    }
}
