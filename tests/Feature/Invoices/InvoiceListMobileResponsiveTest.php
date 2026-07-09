<?php

namespace Tests\Feature\Invoices;

use App\Enums\ContractType;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-sasp: the invoice list keeps the full table at md+ and falls back to
 * stacked cards below md, so the invoice number, status, dates, and total stay
 * readable at a glance without a horizontal scroll. The fix lives in the shared
 * invoices/_list partial, so it covers both the standalone invoice index and the
 * contract detail Invoices tab (the reported scenario). Mirrors psa-6zs7.
 *
 * The desktop table and the mobile cards are both server-rendered into the HTML;
 * only CSS (d-none d-md-block / d-md-none) decides which is shown. So assertSee
 * finds the key data in the response regardless of viewport.
 */
class InvoiceListMobileResponsiveTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(Client $client, array $attrs = []): Invoice
    {
        return Invoice::create(array_merge([
            'client_id' => $client->id,
            'invoice_number' => 'INV-TEST-'.rand(1000, 9999),
            'invoice_date' => now()->subDays(5),
            'due_date' => now()->addDays(25),
            'subtotal' => '1234.56',
            'tax' => '0.00',
            'total' => '1234.56',
            'status' => InvoiceStatus::Posted,
        ], $attrs));
    }

    public function test_invoices_index_renders_mobile_card_fallback(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $this->makeInvoice($client, ['invoice_number' => 'INV-MOBILE-1']);

        $resp = $this->actingAs($user)->get(route('invoices.index'))->assertOk();

        // Desktop: the full table is hidden below md.
        $resp->assertSee('card shadow-sm card-static d-none d-md-block', false);
        // Mobile: the stacked-card fallback container + card render. psa-sasp.
        $resp->assertSee('d-md-none invoice-cards', false);
        $resp->assertSee('class="invoice-card"', false);
        // The clipped-off signal (number + total) is present in the card markup.
        $resp->assertSee('INV-MOBILE-1');
        $resp->assertSee('1,234.56');
    }

    public function test_contract_invoices_tab_renders_mobile_card_fallback(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $contract = Contract::create([
            'client_id' => $client->id,
            'name' => 'Managed Services',
            'type' => ContractType::Managed,
            'start_date' => now()->subYear(),
        ]);
        $this->makeInvoice($client, [
            'contract_id' => $contract->id,
            'invoice_number' => 'INV-CONTRACT-1',
        ]);

        $resp = $this->actingAs($user)
            ->get(route('contracts.invoices', $contract))
            ->assertOk();

        // Desktop table hidden below md; mobile card fallback present. psa-sasp.
        $resp->assertSee('card shadow-sm card-static d-none d-md-block', false);
        $resp->assertSee('d-md-none invoice-cards', false);
        $resp->assertSee('class="invoice-card"', false);
        // The reported clipped columns (number + total) now ride in the mobile card.
        $resp->assertSee('INV-CONTRACT-1');
        $resp->assertSee('1,234.56');
    }
}
