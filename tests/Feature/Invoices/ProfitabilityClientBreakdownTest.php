<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\User;
use App\Services\ProfitabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression cover for ProfitabilityService::clientProfitability().
 *
 * The "breakdown by contract" query joins `invoices` to `contracts`, and both
 * tables carry `client_id` AND `deleted_at`. Filtering on the UNQUALIFIED column
 * names is ambiguous — MariaDB rejects it with error 1052 and SQLite with
 * "ambiguous column name" — so the per-client profitability page 500s on any
 * call. These tests exercise the join and assert the breakdown resolves cleanly.
 */
class ProfitabilityClientBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private function makeContract(Client $client, string $name): Contract
    {
        return Contract::create([
            'client_id' => $client->id,
            'name' => $name,
            'type' => 'managed',
            'start_date' => '2026-01-01',
        ]);
    }

    private function makeInvoice(Client $client, ?Contract $contract, array $attrs = []): Invoice
    {
        return Invoice::create(array_merge([
            'client_id' => $client->id,
            'contract_id' => $contract?->id,
            'invoice_number' => 'INV-'.rand(100000, 999999),
            'invoice_date' => '2026-03-15',
            'due_date' => '2026-04-15',
            'subtotal' => '0.00',
            'tax' => '0.00',
            'total' => '0.00',
            'total_cost' => '0.00',
            'status' => InvoiceStatus::Posted,
        ], $attrs));
    }

    public function test_client_profitability_by_contract_breakdown_does_not_crash_on_ambiguous_column(): void
    {
        $client = Client::factory()->create();
        $managed = $this->makeContract($client, 'Managed Services');
        $backup = $this->makeContract($client, 'Backup');

        $this->makeInvoice($client, $managed, ['subtotal' => '1000.00', 'total_cost' => '300.00']);
        $this->makeInvoice($client, $backup, ['subtotal' => '500.00', 'total_cost' => '200.00']);
        // A no-contract invoice counts toward the top-line totals but never the
        // by-contract breakdown (which filters `whereNotNull(contract_id)`).
        $this->makeInvoice($client, null, ['subtotal' => '100.00', 'total_cost' => '50.00']);

        $data = app(ProfitabilityService::class)->clientProfitability($client);

        // Top-line aggregates cover every client invoice, contract-linked or not.
        $this->assertSame(1600.0, $data['revenue']);
        $this->assertSame(550.0, $data['cost']);
        $this->assertSame(1050.0, $data['margin']);

        // Breakdown covers only the two contract-linked invoices, revenue-desc.
        $this->assertCount(2, $data['byContract']);

        $this->assertSame('Managed Services', $data['byContract'][0]['contract_name']);
        $this->assertSame(1000.0, $data['byContract'][0]['revenue']);
        $this->assertSame(300.0, $data['byContract'][0]['cost']);
        $this->assertSame(700.0, $data['byContract'][0]['margin']);

        $this->assertSame('Backup', $data['byContract'][1]['contract_name']);
        $this->assertSame(500.0, $data['byContract'][1]['revenue']);
        $this->assertSame(200.0, $data['byContract'][1]['cost']);
        $this->assertSame(300.0, $data['byContract'][1]['margin']);
    }

    public function test_client_profitability_breakdown_honours_a_date_range(): void
    {
        $client = Client::factory()->create();
        $contract = $this->makeContract($client, 'Managed Services');

        $this->makeInvoice($client, $contract, ['invoice_date' => '2026-03-15', 'subtotal' => '1000.00', 'total_cost' => '300.00']);
        // Outside the window — must be excluded by the qualified invoice_date filter.
        $this->makeInvoice($client, $contract, ['invoice_date' => '2026-01-01', 'subtotal' => '999.00', 'total_cost' => '111.00']);

        $data = app(ProfitabilityService::class)->clientProfitability(
            $client,
            \Illuminate\Support\Carbon::parse('2026-03-01'),
            \Illuminate\Support\Carbon::parse('2026-03-31'),
        );

        $this->assertCount(1, $data['byContract']);
        $this->assertSame(1000.0, $data['byContract'][0]['revenue']);
    }

    public function test_client_profitability_page_renders_without_500(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $contract = $this->makeContract($client, 'Managed Services');
        $this->makeInvoice($client, $contract, ['subtotal' => '1000.00', 'total_cost' => '300.00']);

        $response = $this->actingAs($user)->get(route('profitability.client', $client));

        $response->assertOk();
        $response->assertSee('Managed Services');
    }
}
