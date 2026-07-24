<?php

namespace Tests\Feature\Mcp;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ScreenConnect reads over the real /api/mcp/staff boundary (psa-mjf6x): grant-gating,
 * ships-dormant, OFF=OFF, and a live tools/call roundtrip. Mirrors UnifiReadToolsMcpTest.
 */
class ScreenConnectReadToolsMcpTest extends TestCase
{
    use RefreshDatabase;

    private const SCREENCONNECT_READS = [
        'screenconnect_get_session_state',
        'screenconnect_list_devices',
    ];

    private function configureScreenConnect(): void
    {
        Setting::setValue('screenconnect_enabled', '1');
        Setting::setValue('screenconnect_base_url', 'https://sc.example.test');
        Setting::setValue('screenconnect_webhook_secret', 'test-secret');
    }

    private function token(array $tools): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: 'opsbot');
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

    /** @return array<string, mixed> */
    private function decodedResult(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    public function test_screenconnect_reads_are_grantable_and_explicit_grant_only(): void
    {
        $this->configureScreenConnect();

        $scopedToken = $this->token(['screenconnect_list_devices']);
        $names = array_column($this->listTools($scopedToken), 'name');
        $this->assertContains('screenconnect_list_devices', $names);
        $this->assertNotContains('screenconnect_get_session_state', $names, 'ungranted ScreenConnect tools stay hidden');

        // A legacy full-surface token (no explicit allowlist) must NOT gain the reads.
        $legacyNames = array_column($this->listTools(McpConfig::rotateStaffToken()), 'name');
        foreach (self::SCREENCONNECT_READS as $tool) {
            $this->assertNotContains($tool, $legacyNames, "legacy token must not gain {$tool}");
        }
    }

    public function test_screenconnect_reads_are_dormant_until_configured(): void
    {
        Setting::setValue('screenconnect_enabled', '1'); // switched on, but no URL/secret

        $names = array_column($this->listTools($this->token(['screenconnect_list_devices'])), 'name');

        $this->assertNotContains('screenconnect_list_devices', $names, 'ScreenConnect reads ship dormant until configured');
    }

    public function test_the_master_switch_withdraws_the_tools_even_when_configured(): void
    {
        // OFF=OFF (CLAUDE.md): a switch labelled off must withdraw the capability from
        // the AI surface — the local webhook snapshot must not keep answering.
        Setting::setValue('screenconnect_base_url', 'https://sc.example.test');
        Setting::setValue('screenconnect_webhook_secret', 'test-secret');
        Setting::setValue('screenconnect_enabled', '0');

        $names = array_column($this->listTools($this->token(['screenconnect_list_devices'])), 'name');

        $this->assertNotContains('screenconnect_list_devices', $names, 'switching ScreenConnect off must withdraw its tools');
    }

    public function test_get_session_state_roundtrips_with_the_psa_wedk_timestamp_pairing(): void
    {
        $this->configureScreenConnect();
        $client = Client::factory()->create(['name' => 'Acme Co']);
        Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'WS-FRONT-01',
            'screenconnect_session_id' => 'a1b2c3d4-0000-0000-0000-000000000001',
            'screenconnect_online' => true,
            'screenconnect_last_seen_at' => now()->subMinutes(5),
            'screenconnect_synced_at' => now()->subMinutes(5),
        ]);

        $token = $this->token(['screenconnect_get_session_state']);
        $response = $this->callTool($token, 'screenconnect_get_session_state', [
            'client_id' => $client->id,
            'hostname' => 'WS-FRONT-01',
        ]);

        $response->assertOk();
        $this->assertFalse($response->json('result.isError'));

        $result = $this->decodedResult($response);
        $this->assertSame('Acme Co', $result['psa_client_name']);
        $this->assertSame('online', $result['state']);
        $this->assertTrue($result['online']);
        $this->assertNotNull($result['online_reported_at'], 'the flag must arrive dated, even over MCP');
        $this->assertNotNull($result['last_webhook_at']);
        $this->assertStringContainsString('event-driven', $result['state_semantics']);
    }

    public function test_a_client_scoped_call_without_client_id_is_refused_at_the_boundary(): void
    {
        $this->configureScreenConnect();

        $token = $this->token(['screenconnect_list_devices']);
        $response = $this->callTool($token, 'screenconnect_list_devices', []);

        $response->assertOk();
        $this->assertTrue($response->json('result.isError'));
        $this->assertStringContainsString('client_id is required', (string) $response->json('result.content.0.text'));
    }
}
