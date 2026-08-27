<?php

namespace Tests\Feature\Mcp;

use App\Models\Client;
use App\Models\ClientPowerdmarcDomain;
use App\Models\Setting;
use App\Services\PowerDmarc\PowerDmarcClient;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PowerDMARC reads over the real /api/mcp/staff boundary (issue #689):
 * grant-gating, ships-dormant, OFF=OFF, and a live tools/call roundtrip.
 */
class PowerDmarcReadToolsMcpTest extends TestCase
{
    use RefreshDatabase;

    private const POWERDMARC_READS = [
        'powerdmarc_list_domains',
        'powerdmarc_get_domain_status',
        'powerdmarc_get_aggregate_summary',
        'powerdmarc_get_dns_timeline',
    ];

    private function configurePowerDmarc(): void
    {
        Setting::setEncrypted('powerdmarc_api_key', 'k');
        Setting::setValue('powerdmarc_enabled', '1');
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

    /**
     * Bind a real PowerDmarcClient over a mocked HTTP transport (like the
     * toolset test) so a live tools/call exercises the true projection.
     *
     * @param  array<int, Response>  $queue
     */
    private function bindClientReturning(array $queue): void
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $http = new GuzzleClient(['base_uri' => 'https://app.powerdmarc.com/', 'handler' => $stack]);
        $this->app->instance(PowerDmarcClient::class, new PowerDmarcClient(['api_key' => 'k'], $http));
    }

    private function fixture(string $name): Response
    {
        $path = base_path("tests/Fixtures/powerdmarc/{$name}.json");

        return new Response(200, ['Content-Type' => 'application/json'], (string) file_get_contents($path));
    }

    public function test_powerdmarc_reads_are_registry_grantable_and_explicit_grant_only(): void
    {
        $this->configurePowerDmarc();

        foreach (self::POWERDMARC_READS as $tool) {
            $this->assertContains($tool, McpToolRegistry::allToolNames(), "{$tool} should be token-grantable");
        }

        $scopedToken = $this->token(['powerdmarc_list_domains', 'powerdmarc_get_domain_status']);
        $names = array_column($this->listTools($scopedToken), 'name');
        $this->assertContains('powerdmarc_list_domains', $names);
        $this->assertContains('powerdmarc_get_domain_status', $names);
        $this->assertNotContains('powerdmarc_get_dns_timeline', $names, 'ungranted PowerDMARC tools stay hidden');

        // A legacy full-surface token (no explicit allowlist) must NOT gain the reads.
        $legacyNames = array_column($this->listTools(McpConfig::rotateStaffToken()), 'name');
        foreach (self::POWERDMARC_READS as $tool) {
            $this->assertNotContains($tool, $legacyNames, "legacy token must not gain {$tool}");
        }
    }

    public function test_powerdmarc_reads_are_dormant_until_the_key_is_configured(): void
    {
        Setting::setValue('powerdmarc_enabled', '1'); // switched on, but no key

        $names = array_column($this->listTools($this->token(['powerdmarc_list_domains'])), 'name');

        $this->assertNotContains('powerdmarc_list_domains', $names, 'PowerDMARC reads ship dormant until a key is configured');
    }

    public function test_the_master_switch_withdraws_the_tools_even_when_configured(): void
    {
        // OFF=OFF (CLAUDE.md): a switch labelled off must withdraw the capability
        // from the AI surface, not merely stop background work. `enabled`
        // defaults '0', so a fresh key alone must not light the tools either.
        Setting::setEncrypted('powerdmarc_api_key', 'k');
        Setting::setValue('powerdmarc_enabled', '0');

        $names = array_column($this->listTools($this->token(['powerdmarc_list_domains'])), 'name');

        $this->assertNotContains('powerdmarc_list_domains', $names, 'switching PowerDMARC off must withdraw its tools');
    }

    public function test_powerdmarc_client_resolves_from_the_container_without_a_manual_binding(): void
    {
        // Same trap the UniFi ARCH review caught: the client's constructor takes
        // an unbound array, so without the AppServiceProvider singleton every
        // live powerdmarc_* call would throw before reaching the API — and every
        // other test here binds a mock, which would hide it exactly. This one
        // deliberately does NOT bind anything.
        $this->configurePowerDmarc();

        $client = app(PowerDmarcClient::class);

        $this->assertInstanceOf(PowerDmarcClient::class, $client);
        // And it resolves even when PowerDMARC is unconfigured — construction
        // must not depend on settings being present, only calls do.
        Setting::where('key', 'powerdmarc_api_key')->delete();
        app()->forgetInstance(PowerDmarcClient::class);
        $this->assertInstanceOf(PowerDmarcClient::class, app(PowerDmarcClient::class));
    }

    public function test_get_domain_status_roundtrips_and_stays_scoped_to_the_mapped_domain(): void
    {
        $this->configurePowerDmarc();
        $client = Client::factory()->create(['name' => 'Acme']);
        ClientPowerdmarcDomain::create(['client_id' => $client->id, 'powerdmarc_domain_id' => 1, 'domain_name' => 'acme.com']);

        // Vendor spec fixtures over the mocked transport, in the projection's
        // deterministic order: records-current-score first, then domain-health.
        $this->bindClientReturning([
            $this->fixture('records_current_score'),
            $this->fixture('domain_health'),
        ]);

        $token = $this->token(['powerdmarc_get_domain_status']);
        $response = $this->callTool($token, 'powerdmarc_get_domain_status', ['client_id' => $client->id]);

        $response->assertOk();
        $this->assertFalse($response->json('result.isError'));

        $result = $this->decodedResult($response);
        $this->assertSame('Acme', $result['psa_client_name']);
        $this->assertSame('acme.com', $result['domain']);
        $this->assertSame(1, $result['powerdmarc_domain_id']);
        // The two vendor casings survive the boundary un-"normalized".
        $this->assertSame('not_found', $result['records']['components']['mta-sts']['status']);
        $this->assertSame('notFound', $result['health']['statuses']['Bimi']);
        $this->assertSame('reject', $result['health']['policy']);
        $this->assertSame(95, $result['records']['percent']);
    }

    public function test_an_unmapped_client_gets_the_remediation_error_over_the_boundary(): void
    {
        $this->configurePowerDmarc();
        $client = Client::factory()->create(['name' => 'Acme']);
        $this->bindClientReturning([]);

        $token = $this->token(['powerdmarc_get_domain_status']);
        $response = $this->callTool($token, 'powerdmarc_get_domain_status', ['client_id' => $client->id]);

        $response->assertOk();
        $result = $this->decodedResult($response);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Domain Mapping', $result['error']);
    }
}
