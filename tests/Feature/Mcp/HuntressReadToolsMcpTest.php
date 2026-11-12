<?php

namespace Tests\Feature\Mcp;

use App\Models\Client;
use App\Models\Setting;
use App\Services\Huntress\HuntressClient;
use App\Services\Huntress\HuntressReadOnlyToolset;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Huntress P1 reads over the real /api/mcp/staff boundary (psa-shej):
 * grant-gating, ships-dormant-until-configured, and a live tools/call roundtrip.
 */
class HuntressReadToolsMcpTest extends TestCase
{
    use RefreshDatabase;

    private const HUNTRESS_READS = [
        'huntress_list_incident_reports',
        'huntress_get_incident_report',
        'huntress_list_escalations',
        'huntress_get_escalation',
        'huntress_list_organizations',
        'huntress_get_organization',
    ];

    private function configureHuntress(): void
    {
        Setting::setEncrypted('huntress_api_key', 'k');
        Setting::setEncrypted('huntress_api_secret', 's');
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

    public function test_huntress_reads_are_registry_grantable_and_explicit_grant_only(): void
    {
        $this->configureHuntress();

        foreach (self::HUNTRESS_READS as $tool) {
            $this->assertContains($tool, McpToolRegistry::allToolNames(), "{$tool} should be token-grantable");
        }

        $scopedToken = $this->token(['huntress_list_organizations', 'huntress_get_escalation']);
        $names = array_column($this->listTools($scopedToken), 'name');
        $this->assertContains('huntress_list_organizations', $names);
        $this->assertContains('huntress_get_escalation', $names);
        $this->assertNotContains('huntress_list_incident_reports', $names, 'ungranted Huntress tools stay hidden');

        // A legacy full-surface token (no explicit allowlist) must NOT gain the reads.
        $legacyNames = array_column($this->listTools(McpConfig::rotateStaffToken()), 'name');
        foreach (self::HUNTRESS_READS as $tool) {
            $this->assertNotContains($tool, $legacyNames, "legacy token must not gain {$tool}");
        }
    }

    public function test_huntress_reads_are_dormant_until_the_key_is_configured(): void
    {
        // No key configured — even a token that grants the tool must not see it listed.
        $token = $this->token(['huntress_list_organizations']);
        $names = array_column($this->listTools($token), 'name');

        $this->assertNotContains('huntress_list_organizations', $names, 'Huntress reads ship dormant until configured');
    }

    public function test_get_incident_report_roundtrips_and_stays_scoped_to_mapped_orgs(): void
    {
        $this->configureHuntress();
        Client::factory()->create(['name' => 'Acme', 'huntress_organization_id' => 42]);

        $mock = Mockery::mock(HuntressClient::class);
        $mock->shouldReceive('getIncidentReport')->with(7)->andReturn([
            'id' => 7,
            'organization_id' => 42,
            'status' => 'closed',
            'severity' => 'critical',
            'subject' => 'Incident on DESKTOP-1',
            'body' => 'threat detail',
        ]);
        app()->instance(HuntressClient::class, $mock);

        $token = $this->token(['huntress_get_incident_report']);
        $response = $this->callTool($token, 'huntress_get_incident_report', ['incident_report_id' => 7]);

        $response->assertOk();
        $this->assertFalse($response->json('result.isError'));
        $result = $this->decodedResult($response);
        $this->assertSame(7, $result['id']);
        $this->assertSame('Acme', $result['psa_client_name']);
    }

    public function test_client_id_crosses_the_boundary_and_resolves_the_mapped_org(): void
    {
        // Card tIP4JoIG: the boundary lifts client_id out of the arguments; the Huntress
        // reads must receive it and resolve through clients.huntress_organization_id.
        $this->configureHuntress();
        $client = Client::factory()->create(['name' => 'Gamma LLC', 'huntress_organization_id' => 42]);

        $mock = Mockery::mock(HuntressClient::class);
        $mock->shouldReceive('getOrganization')->once()->with(42)
            ->andReturn(['organization' => ['id' => 42, 'name' => 'Gamma Holdings', 'key' => 'gamma']]);
        app()->instance(HuntressClient::class, $mock);

        $token = $this->token(['huntress_get_organization']);
        $names = array_column($this->listTools($token), 'name');
        $this->assertContains('huntress_get_organization', $names);

        $response = $this->callTool($token, 'huntress_get_organization', ['client_id' => $client->id]);

        $response->assertOk();
        $this->assertFalse($response->json('result.isError'));
        $result = $this->decodedResult($response);
        $this->assertSame(42, $result['id']);
        $this->assertSame($client->id, $result['psa_client_id']);
    }

    public static function expandedOptionalClientTools(): array
    {
        return array_map(fn (string $tool) => [$tool], [
            'huntress_list_siem_log_sources',
            'huntress_get_siem_log_source',
            'huntress_list_signals',
            'huntress_get_signal',
            'huntress_list_identities',
            'huntress_get_identity',
            'huntress_list_unwanted_access_rules',
            'huntress_get_unwanted_access_rule',
            'huntress_list_agents',
            'huntress_get_agent',
            'huntress_list_external_ports',
            'huntress_get_external_port',
            'huntress_list_reports',
            'huntress_get_report',
            'huntress_list_platform_actions',
            'huntress_get_platform_action',
            'huntress_list_incident_comments',
            'huntress_list_incident_remediations',
            'huntress_get_incident_remediation',
        ]);
    }

    #[DataProvider('expandedOptionalClientTools')]
    public function test_expanded_read_refuses_malformed_client_before_dispatch(string $tool): void
    {
        $this->configureHuntress();
        $executor = Mockery::mock(HuntressReadOnlyToolset::class);
        $executor->shouldNotReceive('execute');
        app()->instance(HuntressReadOnlyToolset::class, $executor);
        $token = $this->token([$tool]);

        foreach (['abc', 0, -1, [], null, '1.5'] as $malformed) {
            $response = $this->callTool($token, $tool, ['client_id' => $malformed]);
            $response->assertOk();
            $this->assertTrue($response->json('result.isError'));
            $this->assertStringContainsString('not a positive integer', (string) $response->json('result.content.0.text'));
        }
    }

    #[DataProvider('expandedOptionalClientTools')]
    public function test_expanded_read_preserves_valid_and_omitted_client_at_dispatch(string $tool): void
    {
        $this->configureHuntress();
        $client = Client::factory()->create(['huntress_organization_id' => 42]);
        $executor = Mockery::mock(HuntressReadOnlyToolset::class);
        $executor->shouldReceive('execute')->once()->with($tool, [], $client->id)
            ->andReturn(['scope_control' => 'client']);
        $executor->shouldReceive('execute')->once()->with($tool, [], null)
            ->andReturn(['scope_control' => 'omitted']);
        app()->instance(HuntressReadOnlyToolset::class, $executor);
        $token = $this->token([$tool]);
        $this->assertContains($tool, array_column($this->listTools($token), 'name'));

        foreach ([[['client_id' => $client->id], 'client'], [[], 'omitted']] as [$arguments, $expected]) {
            $response = $this->callTool($token, $tool, $arguments);
            $response->assertOk();
            $this->assertFalse($response->json('result.isError'));
            $this->assertSame($expected, $this->decodedResult($response)['scope_control']);
        }
    }

    public function test_a_malformed_client_id_is_refused_rather_than_widened_to_the_whole_account(): void
    {
        $this->configureHuntress();
        $mock = Mockery::mock(HuntressClient::class);
        $mock->shouldNotReceive('get');
        app()->instance(HuntressClient::class, $mock);

        $token = $this->token(['huntress_list_organizations']);
        $response = $this->callTool($token, 'huntress_list_organizations', ['client_id' => 'abc']);

        $response->assertOk();
        $this->assertTrue($response->json('result.isError'));
        $this->assertStringContainsString('not a positive integer', (string) $response->json('result.content.0.text'));
    }
}
