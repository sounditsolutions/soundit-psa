<?php

namespace Tests\Feature\Mcp;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Models\Client;
use App\Models\Contract;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Feature coverage for the P3 Contracts READ MCP tools (list_client_contracts +
 * get_contract) in the dormant, grant-gated psa_read group. These are READ tools
 * that mirror the find_persons/get_person pattern (AssistantToolExecutor), NOT the
 * psa_records write pattern. Coverage/SLA is exposed; pricing/financial is held.
 */
class PsaContractReadToolsTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $tools, string $label = 'opsbot'): string
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

    private function contract(Client $client, array $overrides = []): Contract
    {
        return Contract::create(array_merge([
            'client_id' => $client->id,
            'name' => 'Managed Services',
            'type' => ContractType::Managed,
            'status' => ContractStatus::Active,
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'auto_renew' => true,
            // Pricing/financial columns that MUST NOT leak into tool output.
            'prepay_balance' => 500.00,
            'billing_period' => 'monthly',
            'payment_terms_days' => 30,
        ], $overrides));
    }

    public function test_registry_lists_contract_read_tools_in_psa_read_and_requires_grants(): void
    {
        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('psa_read', $groups);
        $this->assertTrue($groups['psa_read']['sensitive']);

        $names = array_column($groups['psa_read']['tools'], 'name');
        foreach (['list_client_contracts', 'get_contract'] as $name) {
            $this->assertContains($name, $names);
        }

        // Dormant: a legacy (no-grant) token cannot see them.
        $legacyNames = collect($this->tools($this->legacyToken()))->pluck('name')->all();
        $this->assertNotContains('list_client_contracts', $legacyNames);
        $this->assertNotContains('get_contract', $legacyNames);

        $scoped = collect($this->tools($this->token(['list_client_contracts', 'get_contract'], 'chet')))->keyBy('name');
        $this->assertTrue($scoped->has('list_client_contracts'));
        $this->assertTrue($scoped->has('get_contract'));

        $listSchema = $scoped['list_client_contracts']['inputSchema'];
        $this->assertContains('client_id', $listSchema['required']);

        $getSchema = $scoped['get_contract']['inputSchema'];
        $this->assertContains('client_id', $getSchema['required']);
        $this->assertContains('contract_id', $getSchema['required']);
    }

    public function test_ungranted_and_legacy_tokens_cannot_call_contract_read_tools(): void
    {
        $client = Client::factory()->create();
        $contract = $this->contract($client);

        $calls = [
            ['list_client_contracts', ['client_id' => $client->id]],
            ['get_contract', ['client_id' => $client->id, 'contract_id' => $contract->id]],
        ];

        foreach ([$this->token(['create_ticket'], 'chet'), $this->legacyToken()] as $token) {
            foreach ($calls as [$tool, $arguments]) {
                $response = $this->callTool($token, $tool, $arguments);
                $response->assertOk();
                $this->assertTrue((bool) $response->json('result.isError'), "{$tool} should be denied.");
                $this->assertStringContainsString('not allowed for this token', (string) $response->json('result.content.0.text'));
            }
        }
    }

    public function test_list_client_contracts_is_client_scoped_and_hides_pricing(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $this->contract($clientA, ['name' => 'A Managed']);
        $this->contract($clientA, ['name' => 'A Break-Fix', 'type' => ContractType::BreakFix, 'status' => ContractStatus::Expired]);
        $this->contract($clientB, ['name' => 'B Only']);
        $token = $this->token(['list_client_contracts'], 'chet');

        $response = $this->callTool($token, 'list_client_contracts', ['client_id' => $clientA->id]);
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $result = $this->decodedResult($response);
        $this->assertSame(2, $result['count']);
        $listedNames = collect($result['contracts'])->pluck('name')->all();
        $this->assertContains('A Managed', $listedNames);
        $this->assertContains('A Break-Fix', $listedNames);
        $this->assertNotContains('B Only', $listedNames);

        // Coverage fields present; pricing/financial fields absent.
        $row = collect($result['contracts'])->firstWhere('name', 'A Managed');
        $this->assertArrayHasKey('status', $row);
        $this->assertArrayHasKey('start_date', $row);
        foreach (['prepay_balance', 'billing_period', 'payment_terms_days', 'prepay_total'] as $money) {
            $this->assertArrayNotHasKey($money, $row);
        }
        $this->assertStringNotContainsString('prepay_balance', (string) $response->json('result.content.0.text'));
        $this->assertStringNotContainsString('billing_period', (string) $response->json('result.content.0.text'));

        // client_id=B returns only B's contract.
        $responseB = $this->callTool($token, 'list_client_contracts', ['client_id' => $clientB->id]);
        $resultB = $this->decodedResult($responseB);
        $this->assertSame(1, $resultB['count']);
        $this->assertSame('B Only', $resultB['contracts'][0]['name']);
    }

    public function test_list_client_contracts_requires_client_context(): void
    {
        $token = $this->token(['list_client_contracts'], 'chet');

        $response = $this->callTool($token, 'list_client_contracts', []);
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('client', (string) $response->json('result.content.0.text'));
    }

    public function test_get_contract_returns_coverage_detail_and_rejects_cross_client(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $contract = $this->contract($clientA, ['name' => 'A Managed', 'notes' => 'Gold SLA, 4h response.']);
        $token = $this->token(['get_contract'], 'chet');

        $response = $this->callTool($token, 'get_contract', ['client_id' => $clientA->id, 'contract_id' => $contract->id]);
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $result = $this->decodedResult($response);
        $this->assertSame($contract->id, $result['id']);
        $this->assertSame('A Managed', $result['name']);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('assets_count', $result);
        foreach (['prepay_balance', 'prepay_total', 'billing_period', 'billing_day', 'payment_terms_days', 'portal_prepay_sku_id'] as $money) {
            $this->assertArrayNotHasKey($money, $result);
        }
        $this->assertStringNotContainsString('prepay_balance', (string) $response->json('result.content.0.text'));

        // A token scoped to client B cannot read client A's contract.
        $cross = $this->callTool($token, 'get_contract', ['client_id' => $clientB->id, 'contract_id' => $contract->id]);
        $cross->assertOk();
        $this->assertTrue((bool) $cross->json('result.isError'));
        $this->assertStringContainsString('not found', (string) $cross->json('result.content.0.text'));
    }
}
