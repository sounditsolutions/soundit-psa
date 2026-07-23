<?php

namespace Tests\Feature\Mcp;

use App\Models\Client;
use App\Models\Setting;
use App\Services\Unifi\UnifiClient;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * UniFi reads over the real /api/mcp/staff boundary (psa-1ynqc): grant-gating,
 * ships-dormant, OFF=OFF, and a live tools/call roundtrip.
 */
class UnifiReadToolsMcpTest extends TestCase
{
    use RefreshDatabase;

    private const UNIFI_READS = [
        'unifi_list_sites',
        'unifi_get_site_health',
        'unifi_list_devices',
        'unifi_get_isp_metrics',
    ];

    private const SITE_A = '661de833b6b2463f0c20b319';

    private function configureUnifi(): void
    {
        Setting::setEncrypted('unifi_api_key', 'k');
        Setting::setValue('unifi_enabled', '1');
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

    public function test_unifi_reads_are_registry_grantable_and_explicit_grant_only(): void
    {
        $this->configureUnifi();

        foreach (self::UNIFI_READS as $tool) {
            $this->assertContains($tool, McpToolRegistry::allToolNames(), "{$tool} should be token-grantable");
        }

        $scopedToken = $this->token(['unifi_list_sites', 'unifi_get_site_health']);
        $names = array_column($this->listTools($scopedToken), 'name');
        $this->assertContains('unifi_list_sites', $names);
        $this->assertContains('unifi_get_site_health', $names);
        $this->assertNotContains('unifi_get_isp_metrics', $names, 'ungranted UniFi tools stay hidden');

        // A legacy full-surface token (no explicit allowlist) must NOT gain the reads.
        $legacyNames = array_column($this->listTools(McpConfig::rotateStaffToken()), 'name');
        foreach (self::UNIFI_READS as $tool) {
            $this->assertNotContains($tool, $legacyNames, "legacy token must not gain {$tool}");
        }
    }

    public function test_unifi_reads_are_dormant_until_the_key_is_configured(): void
    {
        Setting::setValue('unifi_enabled', '1'); // switched on, but no key

        $names = array_column($this->listTools($this->token(['unifi_list_sites'])), 'name');

        $this->assertNotContains('unifi_list_sites', $names, 'UniFi reads ship dormant until a key is configured');
    }

    public function test_the_master_switch_withdraws_the_tools_even_when_configured(): void
    {
        // OFF=OFF (CLAUDE.md): a switch labelled off must withdraw the capability from
        // the AI surface, not merely stop background syncs.
        Setting::setEncrypted('unifi_api_key', 'k');
        Setting::setValue('unifi_enabled', '0');

        $names = array_column($this->listTools($this->token(['unifi_list_sites'])), 'name');

        $this->assertNotContains('unifi_list_sites', $names, 'switching UniFi off must withdraw its tools');
    }

    public function test_get_site_health_roundtrips_and_stays_scoped_to_the_mapped_site(): void
    {
        $this->configureUnifi();
        $client = Client::factory()->create(['name' => 'Acme', 'unifi_site_id' => self::SITE_A]);

        $mock = Mockery::mock(UnifiClient::class);
        $mock->shouldReceive('listSites')->andReturn([
            'data' => [[
                'siteId' => self::SITE_A,
                'hostId' => 'host-1',
                'meta' => ['name' => 'default'],
                'statistics' => [
                    'counts' => ['totalDevice' => 9, 'offlineDevice' => 2],
                    'ispInfo' => ['name' => 'Comcast'],
                    'percentages' => ['wanUptime' => 88],
                    'internetIssues' => [],
                ],
            ]],
            'httpStatusCode' => 200,
        ]);
        app()->instance(UnifiClient::class, $mock);

        $token = $this->token(['unifi_get_site_health']);
        $response = $this->callTool($token, 'unifi_get_site_health', ['client_id' => $client->id]);

        $response->assertOk();
        $this->assertFalse($response->json('result.isError'));

        $result = $this->decodedResult($response);
        $this->assertSame('Acme', $result['psa_client_name']);
        $this->assertSame(88, $result['wan_uptime_percent']);
        $this->assertSame(2, $result['counts']['offlineDevice']);
        $this->assertStringContainsString('Comcast', $result['isp_name']);
    }
}
