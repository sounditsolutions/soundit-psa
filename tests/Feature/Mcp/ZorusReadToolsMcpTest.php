<?php

namespace Tests\Feature\Mcp;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Zorus reads over the real /api/mcp/staff boundary (psa-5wg2i): grant-gating,
 * ships-ungranted, OFF=OFF, a live tools/call roundtrip, and the cross-client
 * bleed check the unreliable upstream customer filter makes mandatory.
 */
class ZorusReadToolsMcpTest extends TestCase
{
    use RefreshDatabase;

    private const ZORUS_READS = [
        'zorus_get_filtering_status',
        'zorus_list_endpoints',
    ];

    private function configureZorus(): void
    {
        Setting::setEncrypted('zorus_api_key', 'k');
        Setting::setValue('zorus_enabled', '1');
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

    private function mappedClient(string $name): Client
    {
        return Client::factory()->create([
            'name' => $name,
            'zorus_customer_id' => (string) Str::uuid(),
        ]);
    }

    private function zorusAsset(Client $client, array $overrides = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'client_id' => $client->id,
            'zorus_endpoint_id' => (string) Str::uuid(),
            'zorus_group_name' => 'Default Policy',
            'zorus_filtering_enabled' => true,
            'zorus_cybersight_enabled' => false,
            'zorus_agent_version' => '4.1.0',
            'zorus_agent_state' => 'Connected',
            'zorus_last_seen_at' => now()->subHour(),
            'zorus_synced_at' => now()->subHours(2),
        ], $overrides));
    }

    public function test_zorus_reads_are_registry_grantable_and_explicit_grant_only(): void
    {
        $this->configureZorus();

        foreach (self::ZORUS_READS as $tool) {
            $this->assertContains($tool, McpToolRegistry::allToolNames(), "{$tool} should be token-grantable");
        }

        $scopedToken = $this->token(['zorus_get_filtering_status']);
        $names = array_column($this->listTools($scopedToken), 'name');
        $this->assertContains('zorus_get_filtering_status', $names);
        $this->assertNotContains('zorus_list_endpoints', $names, 'ungranted Zorus tools stay hidden');

        // A legacy full-surface token (no explicit allowlist) must NOT gain the reads.
        $legacyNames = array_column($this->listTools(McpConfig::rotateStaffToken()), 'name');
        foreach (self::ZORUS_READS as $tool) {
            $this->assertNotContains($tool, $legacyNames, "legacy token must not gain {$tool}");
        }
    }

    public function test_zorus_reads_are_dormant_until_the_key_is_configured(): void
    {
        Setting::setValue('zorus_enabled', '1'); // switched on, but no key

        $names = array_column($this->listTools($this->token(['zorus_get_filtering_status'])), 'name');

        $this->assertNotContains('zorus_get_filtering_status', $names, 'Zorus reads stay dormant until a key is configured');
    }

    public function test_the_master_switch_withdraws_the_tools_even_when_configured(): void
    {
        // OFF=OFF (CLAUDE.md): a switch labelled off must withdraw the capability from
        // the AI surface, not merely stop background syncs.
        Setting::setEncrypted('zorus_api_key', 'k');
        Setting::setValue('zorus_enabled', '0');

        $names = array_column($this->listTools($this->token(['zorus_get_filtering_status'])), 'name');

        $this->assertNotContains('zorus_get_filtering_status', $names, 'switching Zorus off must withdraw its tools');
    }

    public function test_an_ungranted_call_is_refused_at_the_boundary(): void
    {
        $this->configureZorus();
        $client = $this->mappedClient('Acme');

        $response = $this->callTool($this->token(['zorus_list_endpoints']), 'zorus_get_filtering_status', [
            'client_id' => $client->id,
        ]);

        $this->assertTrue((bool) $response->json('result.isError'), 'a tool outside the token allowlist must be refused');
    }

    public function test_a_call_without_client_id_is_refused_at_the_boundary(): void
    {
        $this->configureZorus();

        $response = $this->callTool($this->token(['zorus_get_filtering_status']), 'zorus_get_filtering_status');

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('client_id is required', (string) $response->json('result.content.0.text'));
    }

    public function test_filtering_status_roundtrips_over_the_mcp_boundary(): void
    {
        $this->configureZorus();
        $client = $this->mappedClient('Acme');
        $this->zorusAsset($client, ['hostname' => 'ACME-PC-01']);
        $this->zorusAsset($client, ['hostname' => 'ACME-PC-02', 'zorus_filtering_enabled' => false, 'zorus_agent_state' => 'Disconnected']);

        $response = $this->callTool($this->token(['zorus_get_filtering_status']), 'zorus_get_filtering_status', [
            'client_id' => $client->id,
        ]);

        $response->assertOk();
        $this->assertFalse($response->json('result.isError'));

        $result = $this->decodedResult($response);
        $this->assertSame('Acme', $result['psa_client_name']);
        $this->assertSame(2, $result['endpoint_count']);
        $this->assertSame(1, $result['filtering_enabled_count']);
        $this->assertSame(1, $result['filtering_disabled_count']);
        $this->assertStringContainsString('not a live Zorus query', $result['data_source']);
    }

    public function test_endpoint_reads_stay_scoped_over_the_mcp_boundary(): void
    {
        // The upstream customer filter is unreliable, so the sync groups endpoints
        // client-side — meaning OUR client_id scoping is the only boundary between
        // one client's DNS filtering posture and another's. Prove it holds over the
        // real transport, not just at the toolset seam.
        $this->configureZorus();
        $acme = $this->mappedClient('Acme');
        $rival = $this->mappedClient('Rival Corp');
        $this->zorusAsset($acme, ['hostname' => 'ACME-PC-01']);
        $this->zorusAsset($rival, [
            'hostname' => 'RIVAL-SECRET-HOST',
            'zorus_group_name' => 'Rival Executive Policy',
        ]);

        $token = $this->token(['zorus_list_endpoints']);
        $response = $this->callTool($token, 'zorus_list_endpoints', ['client_id' => $acme->id]);

        $response->assertOk();
        $this->assertFalse($response->json('result.isError'));

        $raw = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('ACME-PC-01', $raw);
        $this->assertStringNotContainsString('RIVAL-SECRET-HOST', $raw, "another client's endpoint crossed the MCP boundary");
        $this->assertStringNotContainsString('Rival Executive Policy', $raw, "another client's group crossed the MCP boundary");
    }
}
