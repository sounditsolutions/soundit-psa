<?php

namespace Tests\Feature\Mcp;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Services\Comet\CometClient;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Comet + Servosity backup reads over the real /api/mcp/staff boundary
 * (psa-z30dv): grant-gating, ships-ungranted, OFF=OFF/dormant-until-configured,
 * registry routing, a live tools/call roundtrip, and the cross-client check the
 * server-wide Comet admin API makes mandatory.
 */
class BackupReadToolsMcpTest extends TestCase
{
    use RefreshDatabase;

    private const BACKUP_READS = [
        'comet_get_backup_posture',
        'comet_list_backup_jobs',
        'servosity_get_backup_status',
    ];

    private function configureComet(): void
    {
        Setting::setValue('comet_server_url', 'https://comet.example.test');
        Setting::setEncrypted('comet_admin_user', 'admin');
        Setting::setEncrypted('comet_admin_password', 'pw');
    }

    private function configureServosity(): void
    {
        Setting::setEncrypted('servosity_api_token', 't');
        Setting::setValue('servosity_enabled', '1');
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

    private function cometJob(string $deviceId, int $status): \Comet\BackupJobDetail
    {
        $job = new \Comet\BackupJobDetail;
        $job->DeviceID = $deviceId;
        $job->Classification = \Comet\Def::JOB_CLASSIFICATION_BACKUP;
        $job->Status = $status;
        $job->StartTime = now()->subHours(6)->timestamp;
        $job->EndTime = now()->subHours(5)->timestamp;

        return $job;
    }

    public function test_backup_reads_are_registry_grantable_and_route_to_an_integration(): void
    {
        foreach (self::BACKUP_READS as $tool) {
            $this->assertContains($tool, McpToolRegistry::allToolNames(), "{$tool} should be token-grantable");
            // Prefix routing: nothing may land uncategorised as PSA-native.
            $this->assertSame('other', McpToolRegistry::integrationForToolName($tool), "{$tool} must route to the 'other' integration bucket");
        }
    }

    public function test_backup_reads_are_explicit_grant_only(): void
    {
        $this->configureComet();
        $this->configureServosity();

        $scopedToken = $this->token(['comet_get_backup_posture']);
        $names = array_column($this->listTools($scopedToken), 'name');
        $this->assertContains('comet_get_backup_posture', $names);
        $this->assertNotContains('comet_list_backup_jobs', $names, 'ungranted backup tools stay hidden');
        $this->assertNotContains('servosity_get_backup_status', $names, 'ungranted backup tools stay hidden');

        // A legacy full-surface token (no explicit allowlist) must NOT gain the reads.
        $legacyNames = array_column($this->listTools(McpConfig::rotateStaffToken()), 'name');
        foreach (self::BACKUP_READS as $tool) {
            $this->assertNotContains($tool, $legacyNames, "legacy token must not gain {$tool}");
        }
    }

    public function test_backup_reads_are_dormant_until_their_integration_is_configured(): void
    {
        // Neither vendor configured: granted or not, nothing publishes.
        $names = array_column($this->listTools($this->token(self::BACKUP_READS)), 'name');

        foreach (self::BACKUP_READS as $tool) {
            $this->assertNotContains($tool, $names, "{$tool} must stay dormant until its integration is configured");
        }
    }

    public function test_each_vendors_tools_publish_independently(): void
    {
        $this->configureComet(); // Servosity stays unconfigured

        $names = array_column($this->listTools($this->token(self::BACKUP_READS)), 'name');

        $this->assertContains('comet_get_backup_posture', $names);
        $this->assertContains('comet_list_backup_jobs', $names);
        $this->assertNotContains('servosity_get_backup_status', $names, 'an unconfigured Servosity must not ride in on Comet');
    }

    public function test_the_servosity_master_switch_withdraws_its_tool_even_when_configured(): void
    {
        // OFF=OFF (CLAUDE.md): a switch labelled off must withdraw the capability.
        Setting::setEncrypted('servosity_api_token', 't');
        Setting::setValue('servosity_enabled', '0');

        $names = array_column($this->listTools($this->token(['servosity_get_backup_status'])), 'name');

        $this->assertNotContains('servosity_get_backup_status', $names, 'switching Servosity off must withdraw its tool');
    }

    public function test_an_ungranted_call_is_refused_at_the_boundary(): void
    {
        $this->configureComet();
        $client = Client::factory()->create(['comet_group_id' => 'grp-acme']);

        $response = $this->callTool($this->token(['comet_list_backup_jobs']), 'comet_get_backup_posture', [
            'client_id' => $client->id,
        ]);

        $this->assertTrue((bool) $response->json('result.isError'), 'a tool outside the token allowlist must be refused');
    }

    public function test_a_call_without_client_id_is_refused_at_the_boundary(): void
    {
        $this->configureComet();

        $response = $this->callTool($this->token(['comet_get_backup_posture']), 'comet_get_backup_posture');

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('client_id is required', (string) $response->json('result.content.0.text'));
    }

    public function test_backup_posture_roundtrips_over_the_mcp_boundary(): void
    {
        $this->configureComet();
        $client = Client::factory()->create(['name' => 'Acme', 'comet_group_id' => 'grp-acme']);
        Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'ACME-PC-01',
            'comet_username' => 'acme-backup',
            'comet_device_id' => 'acme-dev',
            'comet_backup_enabled' => true,
            'backup_synced_at' => now()->subHours(2),
        ]);

        $this->mock(CometClient::class)
            ->shouldReceive('getJobsForUser')
            ->with('acme-backup')
            ->andReturn([$this->cometJob('acme-dev', \Comet\Def::JOB_STATUS_FAILED_ERROR)]);

        $response = $this->callTool($this->token(['comet_get_backup_posture']), 'comet_get_backup_posture', [
            'client_id' => $client->id,
        ]);

        $response->assertOk();
        $this->assertFalse($response->json('result.isError'));

        $result = $this->decodedResult($response);
        $this->assertSame('Acme', $result['psa_client_name']);
        $this->assertSame(1, $result['summary']['last_backup_failed']);
        $this->assertSame('last_backup_failed', $result['devices'][0]['job_state']);
        $this->assertNotNull($result['jobs_checked_at']);
    }

    public function test_backup_reads_stay_scoped_over_the_mcp_boundary(): void
    {
        // The Comet admin API is server-wide, so OUR device partition is the only
        // boundary between one client's backup posture and another's. Prove it
        // holds over the real transport, not just at the toolset seam.
        $this->configureComet();
        $acme = Client::factory()->create(['name' => 'Acme', 'comet_group_id' => 'grp-acme']);
        $rival = Client::factory()->create(['name' => 'Rival Corp', 'comet_group_id' => 'grp-rival']);
        Asset::factory()->create([
            'client_id' => $acme->id,
            'hostname' => 'ACME-PC-01',
            'comet_username' => 'acme-backup',
            'comet_device_id' => 'acme-dev',
        ]);
        Asset::factory()->create([
            'client_id' => $rival->id,
            'hostname' => 'RIVAL-SECRET-HOST',
            'comet_username' => 'rival-backup',
            'comet_device_id' => 'rival-dev',
        ]);

        // Upstream hands back a foreign DeviceID under Acme's username — it must be dropped.
        $this->mock(CometClient::class)
            ->shouldReceive('getJobsForUser')
            ->with('acme-backup')
            ->andReturn([
                $this->cometJob('acme-dev', \Comet\Def::JOB_STATUS_STOP_SUCCESS),
                $this->cometJob('rival-dev', \Comet\Def::JOB_STATUS_FAILED_ERROR),
            ]);

        $response = $this->callTool($this->token(['comet_get_backup_posture']), 'comet_get_backup_posture', [
            'client_id' => $acme->id,
        ]);

        $response->assertOk();
        $this->assertFalse($response->json('result.isError'));

        $raw = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('ACME-PC-01', $raw);
        $this->assertStringNotContainsString('RIVAL-SECRET-HOST', $raw, "another client's hostname crossed the MCP boundary");
        $this->assertStringNotContainsString('rival-dev', $raw, "another client's device id crossed the MCP boundary");
        $this->assertStringNotContainsString('rival-backup', $raw, "another client's username crossed the MCP boundary");
    }
}
