<?php

namespace Tests\Feature\Mcp;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * psa-ij59 — the invoicing READ tools (list_invoices + get_invoice) in the dormant,
 * grant-gated psa_read group. Charlie's request, 2026-07-20.
 *
 * These are CROSS-CLIENT staff-class reads with client_id as an OPTIONAL filter —
 * they mirror list_email_items/list_phone_calls, NOT the hard client-scoped
 * list_client_contracts. That was Charlie's explicit ruling ("staff-wide, per-token
 * grant, just like the other tools").
 *
 * *** THE DATA BOUNDARY IS THE OPPOSITE OF ITS psa_read NEIGHBOURS AND THAT IS
 * DELIBERATE. *** list_client_contracts says "pricing and financial fields are not
 * exposed"; these tools expose totals AND cost/margin, on Charlie's explicit answer.
 * The tool description is therefore the only thing standing between a granter and a
 * cross-client margin read, so the tests below assert the cost fields are NAMED in it.
 */
class PsaInvoiceReadToolsTest extends TestCase
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

    private function contract(Client $client): Contract
    {
        return Contract::create([
            'client_id' => $client->id,
            'name' => 'Managed Services',
            'type' => ContractType::Managed,
            'status' => ContractStatus::Active,
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
        ]);
    }

    private function invoice(Client $client, array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'client_id' => $client->id,
            'invoice_number' => 'INV-'.$client->id.'-'.fake()->unique()->numberBetween(1000, 9999),
            'invoice_date' => now()->subDays(5)->toDateString(),
            'due_date' => now()->addDays(25)->toDateString(),
            'subtotal' => 1000.00,
            'tax' => 100.00,
            'total' => 1100.00,
            'total_cost' => 400.00,
            'margin' => 600.00,
            'status' => InvoiceStatus::Posted,
        ], $overrides));
    }

    // ── registry / dormancy ────────────────────────────────────────────────

    public function test_registry_lists_invoice_read_tools_in_psa_read_and_they_ship_dormant(): void
    {
        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('psa_read', $groups);
        $this->assertTrue($groups['psa_read']['sensitive'], 'invoicing is client financial data');

        $names = array_column($groups['psa_read']['tools'], 'name');
        foreach (['list_invoices', 'get_invoice'] as $name) {
            $this->assertContains($name, $names);
        }

        // SHIPS DORMANT: a legacy (no-grant) token cannot see them at all.
        $legacyNames = collect($this->tools($this->legacyToken()))->pluck('name')->all();
        $this->assertNotContains('list_invoices', $legacyNames);
        $this->assertNotContains('get_invoice', $legacyNames);

        $scoped = collect($this->tools($this->token(['list_invoices', 'get_invoice'])))->keyBy('name');
        $this->assertTrue($scoped->has('list_invoices'));
        $this->assertTrue($scoped->has('get_invoice'));

        // Cross-client by default: client_id must NOT be required on the list tool.
        $this->assertNotContains('client_id', $scoped['list_invoices']['inputSchema']['required'] ?? []);
        // The detail tool is addressed by invoice id.
        $this->assertContains('invoice_id', $scoped['get_invoice']['inputSchema']['required']);
    }

    /**
     * Charlie ruled that cost/margin ARE included. The safeguard he asked for is that
     * a grant be LEGIBLE — so the description must name the cost fields outright
     * rather than hiding them behind "financial details". A granter ticking this box
     * must be able to see they are handing over cross-client margin.
     */
    public function test_tool_descriptions_name_the_cost_fields_so_a_grant_is_legible(): void
    {
        $scoped = collect($this->tools($this->token(['list_invoices', 'get_invoice'])))->keyBy('name');

        $listDesc = $scoped['list_invoices']['description'];
        $getDesc = $scoped['get_invoice']['description'];

        foreach (['cost', 'margin'] as $term) {
            $this->assertStringContainsString($term, mb_strtolower($listDesc));
            $this->assertStringContainsString($term, mb_strtolower($getDesc));
        }

        // And it must be explicit that this is cross-client, not client-fenced.
        $this->assertStringContainsString('cross-client', mb_strtolower($listDesc));
    }

    // ── scope ──────────────────────────────────────────────────────────────

    public function test_list_is_cross_client_when_client_id_omitted_and_scoped_when_provided(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $invA = $this->invoice($clientA);
        $invB = $this->invoice($clientB);

        $token = $this->token(['list_invoices']);

        // Omitted: staff-wide, sees both clients' invoices (Charlie's ruling).
        $all = $this->decodedResult($this->callTool($token, 'list_invoices', []));
        $ids = collect($all['invoices'])->pluck('id')->all();
        $this->assertContains($invA->id, $ids);
        $this->assertContains($invB->id, $ids);

        // Provided: scoped to exactly that client.
        $scoped = $this->decodedResult($this->callTool($token, 'list_invoices', ['client_id' => $clientA->id]));
        $scopedIds = collect($scoped['invoices'])->pluck('id')->all();
        $this->assertContains($invA->id, $scopedIds);
        $this->assertNotContains($invB->id, $scopedIds);
    }

    // ── filters Charlie asked for ──────────────────────────────────────────

    public function test_list_filters_by_date_range(): void
    {
        $client = Client::factory()->create();
        $old = $this->invoice($client, ['invoice_date' => now()->subMonths(6)->toDateString()]);
        $recent = $this->invoice($client, ['invoice_date' => now()->subDays(2)->toDateString()]);

        $token = $this->token(['list_invoices']);

        $result = $this->decodedResult($this->callTool($token, 'list_invoices', [
            'from' => now()->subDays(30)->toDateString(),
            'to' => now()->toDateString(),
        ]));

        $ids = collect($result['invoices'])->pluck('id')->all();
        $this->assertContains($recent->id, $ids);
        $this->assertNotContains($old->id, $ids);
    }

    public function test_list_filters_by_contract(): void
    {
        $client = Client::factory()->create();
        $contract = $this->contract($client);
        $onContract = $this->invoice($client, ['contract_id' => $contract->id]);
        $offContract = $this->invoice($client);

        $token = $this->token(['list_invoices']);

        $result = $this->decodedResult($this->callTool($token, 'list_invoices', [
            'contract_id' => $contract->id,
        ]));

        $ids = collect($result['invoices'])->pluck('id')->all();
        $this->assertContains($onContract->id, $ids);
        $this->assertNotContains($offContract->id, $ids);
    }

    /**
     * CLAUDE.md rule 3: a degraded read must SCREAM, never return a clean empty.
     * A malformed date is a caller error, and answering it with [] would read as
     * "this client has no invoices in that range" — a confident, wrong all-clear.
     */
    public function test_a_malformed_date_errors_rather_than_returning_a_false_empty(): void
    {
        $client = Client::factory()->create();
        $this->invoice($client);

        $token = $this->token(['list_invoices']);

        $result = $this->decodedResult($this->callTool($token, 'list_invoices', ['from' => 'not-a-date']));

        $this->assertArrayHasKey('error', $result, 'a bad date must error, not silently return no rows');
        $this->assertArrayNotHasKey('invoices', $result);
    }

    // ── detail ─────────────────────────────────────────────────────────────

    /**
     * *** THE BILLING TRAP. *** A graduated line is EXPANDED into one invoice line per
     * consumed band (CLAUDE.md), so several lines legitimately share a description at
     * different unit prices, and quantity_source is the audit record naming WHICH rate
     * card priced each one. A detail payload that drops quantity_source, or reorders
     * the lines, cannot say how the invoice was priced — which is exactly the
     * confidently-wrong record this repo has been burned by before.
     */
    public function test_get_invoice_returns_lines_in_sort_order_with_their_quantity_source(): void
    {
        $client = Client::factory()->create();
        $invoice = $this->invoice($client);

        // Two bands of ONE graduated line, as BillingService would emit them.
        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Cloud backup (1–10 GB @ $1.00)',
            'quantity' => 10, 'unit_price' => 1.00, 'amount' => 10.00,
            'unit_cost' => 0.40, 'cost_amount' => 4.00,
            'quantity_source' => '10 GB backup storage [graduated: 2 bands]',
            'sort_order' => 1,
        ]);
        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Cloud backup (11–30 GB @ $0.80)',
            'quantity' => 20, 'unit_price' => 0.80, 'amount' => 16.00,
            'unit_cost' => 0.30, 'cost_amount' => 6.00,
            'quantity_source' => '20 GB backup storage [graduated: 2 bands]',
            'sort_order' => 2,
        ]);

        $token = $this->token(['get_invoice']);
        $result = $this->decodedResult($this->callTool($token, 'get_invoice', ['invoice_id' => $invoice->id]));

        $this->assertSame($invoice->id, $result['id']);
        $this->assertCount(2, $result['lines']);

        // Order preserved — band lines are only interpretable in sequence, and the
        // QBO push pairs lines by sort_order POSITION.
        $this->assertSame(1, $result['lines'][0]['sort_order']);
        $this->assertSame(2, $result['lines'][1]['sort_order']);

        // The audit record survives on every line.
        foreach ($result['lines'] as $line) {
            $this->assertArrayHasKey('quantity_source', $line);
            $this->assertStringContainsString('graduated', $line['quantity_source']);
        }
    }

    /** Charlie ruled cost/margin ARE exposed on the staff surface. */
    public function test_detail_exposes_cost_and_margin_per_charlies_ruling(): void
    {
        $client = Client::factory()->create();
        $invoice = $this->invoice($client);
        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Managed services',
            'quantity' => 1, 'unit_price' => 1000.00, 'amount' => 1000.00,
            'unit_cost' => 400.00, 'cost_amount' => 400.00,
            'sort_order' => 1,
        ]);

        $token = $this->token(['get_invoice']);
        $result = $this->decodedResult($this->callTool($token, 'get_invoice', ['invoice_id' => $invoice->id]));

        $this->assertEquals(400.00, (float) $result['total_cost']);
        $this->assertEquals(600.00, (float) $result['margin']);
        $this->assertEquals(400.00, (float) $result['lines'][0]['cost_amount']);
        $this->assertEquals(400.00, (float) $result['lines'][0]['unit_cost']);
    }

    public function test_get_invoice_reports_a_missing_invoice_rather_than_an_empty_shell(): void
    {
        $token = $this->token(['get_invoice']);
        $result = $this->decodedResult($this->callTool($token, 'get_invoice', ['invoice_id' => 999999]));

        $this->assertArrayHasKey('error', $result);
    }

    // ── grant gating ───────────────────────────────────────────────────────

    /**
     * Ungranted AND legacy tokens are both denied. Asserted in the canonical shape
     * used by PsaContractReadToolsTest: the transport answers 200 with
     * result.isError set and a "not allowed for this token" body — NOT a JSON-RPC
     * error field. (My first draft of this test looked for the latter and failed;
     * the gate was working, the assertion was wrong.)
     */
    public function test_ungranted_and_legacy_tokens_cannot_call_invoice_read_tools(): void
    {
        $client = Client::factory()->create();
        $invoice = $this->invoice($client);

        $calls = [
            ['list_invoices', []],
            ['get_invoice', ['invoice_id' => $invoice->id]],
        ];

        foreach ([$this->token(['create_ticket'], 'other'), $this->legacyToken()] as $token) {
            foreach ($calls as [$tool, $arguments]) {
                $response = $this->callTool($token, $tool, $arguments);
                $response->assertOk();
                $this->assertTrue((bool) $response->json('result.isError'), "{$tool} should be denied.");
                $this->assertStringContainsString(
                    'not allowed for this token',
                    (string) $response->json('result.content.0.text'),
                );
            }
        }
    }
}
