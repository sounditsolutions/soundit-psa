<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mobile responsive fix psa-e2no: the invoice list keeps the full table at md+
 * and falls back to stacked cards below md, so the total/status signal stays
 * visible without a horizontal scroll (mirrors the tickets queue, psa-6zs7).
 */
class MobileResponsiveTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(array $attrs = []): Invoice
    {
        $client = Client::factory()->create(['name' => 'Acme Widgets']);

        return Invoice::create(array_merge([
            'client_id' => $client->id,
            'invoice_number' => 'INV-TEST-'.rand(1000, 9999),
            'invoice_date' => now()->subDays(30),
            'due_date' => now()->addDays(10),
            'subtotal' => '100.00',
            'tax' => '0.00',
            'total' => '1234.56',
            'status' => InvoiceStatus::Posted,
        ], $attrs));
    }

    public function test_invoices_index_renders_mobile_card_fallback(): void
    {
        $user = User::factory()->create();
        $this->makeInvoice();

        $resp = $this->actingAs($user)->get(route('invoices.index'))->assertOk();

        // Desktop: the full table is hidden below md.
        $resp->assertSee('card shadow-sm card-static d-none d-md-block', false);
        // Mobile: the stacked-card fallback container + at least one card render. psa-e2no.
        $resp->assertSee('d-md-none invoice-cards', false);
        $resp->assertSee('ticket-card', false);
        // The key signal (total + status) is present so it reads without a scroll.
        $resp->assertSee('$1,234.56');
        $resp->assertSee('Posted');
    }

    public function test_invoices_index_mobile_card_shows_overdue_badge(): void
    {
        $user = User::factory()->create();
        $this->makeInvoice([
            'status' => InvoiceStatus::Posted,
            'due_date' => now()->subDay(),
        ]);

        $resp = $this->actingAs($user)->get(route('invoices.index'))->assertOk();

        $resp->assertSee('d-md-none invoice-cards', false);
        $resp->assertSee('Overdue');
    }
}
