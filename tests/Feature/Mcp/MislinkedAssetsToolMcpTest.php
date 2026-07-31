<?php

namespace Tests\Feature\Mcp;

use App\Models\Asset;
use App\Models\Client;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * MCP boundary coverage for list_mislinked_assets — the READ-ONLY cross-client
 * mislink sweep in the dormant, grant-gated psa_read group. Explicit-grant only;
 * client_id is an OPTIONAL filter (per-client vs fleet-wide); each row carries
 * rule + other-client + evidence; the caveat rides in the response.
 */
class MislinkedAssetsToolMcpTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $tools, string $label = 'chet'): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: $label);
    }

    private function legacyToken(): string
    {
        return McpConfig::rotateStaffToken();
    }

    /** @param  array<string, mixed>  $arguments */
    private function callTool(string $token, string $name, array $arguments): TestResponse
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
    private function tools(string $token): array
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

    /** Seed a genuine cross-client serial collision; returns [clientA, clientB, assetA]. */
    private function seedCrossClientSerial(): array
    {
        $a = Client::factory()->create(['name' => 'Acme']);
        $b = Client::factory()->create(['name' => 'Bravo']);
        $assetA = Asset::factory()->create(['client_id' => $a->id, 'serial_number' => 'DUP-SN', 'hostname' => 'HOST-A']);
        Asset::factory()->create(['client_id' => $b->id, 'serial_number' => 'DUP-SN', 'hostname' => 'HOST-B']);

        return [$a, $b, $assetA];
    }

    public function test_registry_lists_the_tool_in_psa_read_and_it_ships_dormant(): void
    {
        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('psa_read', $groups);
        $names = array_column($groups['psa_read']['tools'], 'name');
        $this->assertContains('list_mislinked_assets', $names);

        // Dormant: a legacy (no-grant) token cannot see it.
        $legacyNames = collect($this->tools($this->legacyToken()))->pluck('name')->all();
        $this->assertNotContains('list_mislinked_assets', $legacyNames);

        // Granted: visible, with an OPTIONAL client_id (not in required).
        $scoped = collect($this->tools($this->token(['list_mislinked_assets'])))->keyBy('name');
        $this->assertTrue($scoped->has('list_mislinked_assets'));
        $schema = $scoped['list_mislinked_assets']['inputSchema'];
        $this->assertArrayHasKey('client_id', $schema['properties']);
        $this->assertNotContains('client_id', $schema['required'] ?? []);
    }

    public function test_ungranted_and_legacy_tokens_cannot_call_the_tool(): void
    {
        $this->seedCrossClientSerial();

        foreach ([$this->token(['create_ticket']), $this->legacyToken()] as $token) {
            $response = $this->callTool($token, 'list_mislinked_assets', []);
            $response->assertOk();
            $this->assertTrue((bool) $response->json('result.isError'), 'must be denied without an explicit grant');
            $this->assertStringContainsString('not allowed for this token', (string) $response->json('result.content.0.text'));
        }
    }

    public function test_fleet_wide_call_returns_both_sides_with_rule_other_client_and_evidence(): void
    {
        [$a, $b, $assetA] = $this->seedCrossClientSerial();
        $token = $this->token(['list_mislinked_assets']);

        $response = $this->callTool($token, 'list_mislinked_assets', []);
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $result = $this->decodedResult($response);
        $this->assertSame('fleet', $result['scope']);
        $this->assertSame(2, $result['tier_a_count']);
        $this->assertArrayHasKey('tier_a', $result);
        $this->assertArrayHasKey('tier_b', $result);

        $row = collect($result['tier_a'])->firstWhere('asset_id', $assetA->id);
        $this->assertSame('duplicate_serial_cross_client', $row['rule']);
        $this->assertSame($b->id, $row['other_client_id']);
        $this->assertSame('Bravo', $row['other_client_name']);
        $this->assertSame('DUP-SN', $row['evidence']['duplicate_serial']);

        // The Tier-A absence caveat must ride in the response text.
        $this->assertStringContainsString('Absence of a Tier A hit is not proof', (string) $response->json('result.content.0.text'));
    }

    public function test_client_id_argument_scopes_the_sweep_to_one_client(): void
    {
        [$a, $b, $assetA] = $this->seedCrossClientSerial();
        $token = $this->token(['list_mislinked_assets']);

        $response = $this->callTool($token, 'list_mislinked_assets', ['client_id' => $a->id]);
        $response->assertOk();
        $result = $this->decodedResult($response);

        $this->assertSame('client:'.$a->id, $result['scope']);
        $this->assertSame(1, $result['tier_a_count']);
        $this->assertSame($assetA->id, $result['tier_a'][0]['asset_id']);
        $this->assertSame($b->id, $result['tier_a'][0]['other_client_id']);
    }

    public function test_include_inactive_passthrough_over_the_boundary(): void
    {
        $a = Client::factory()->create();
        $b = Client::factory()->create();
        Asset::factory()->create(['client_id' => $a->id, 'serial_number' => 'INACT-SN', 'hostname' => 'H-IA', 'is_active' => true]);
        Asset::factory()->create(['client_id' => $b->id, 'serial_number' => 'INACT-SN', 'hostname' => 'H-IB', 'is_active' => false]);
        $token = $this->token(['list_mislinked_assets']);

        $off = $this->decodedResult($this->callTool($token, 'list_mislinked_assets', []));
        $this->assertSame(0, $off['tier_a_count']);

        $on = $this->decodedResult($this->callTool($token, 'list_mislinked_assets', ['include_inactive' => true]));
        $this->assertTrue($on['include_inactive']);
        $this->assertGreaterThanOrEqual(1, $on['tier_a_count']);
    }
}
