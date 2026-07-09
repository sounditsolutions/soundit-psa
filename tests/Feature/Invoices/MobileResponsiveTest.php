<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mobile responsive fix (psa-5ngn): the invoices list keeps the full table at
 * md+ and falls back to stacked cards below md, so each invoice's status and
 * total stay visible at a glance without a horizontal scroll. Mirrors the
 * tickets queue pattern (psa-6zs7).
 */
class MobileResponsiveTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(array $attrs = []): Invoice
    {
        $client = Client::factory()->create();

        return Invoice::create(array_merge([
            'client_id' => $client->id,
            'invoice_number' => 'INV-TEST-'.rand(1000, 9999),
            'invoice_date' => now()->subDays(30),
            'due_date' => now()->addDays(10),
            'subtotal' => '100.00',
            'tax' => '0.00',
            'total' => '100.00',
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
        // Mobile: the stacked-card fallback container + at least one card render.
        $resp->assertSee('d-md-none invoice-cards', false);
        $resp->assertSee('invoice-card', false);
    }

    public function test_mobile_card_surfaces_status_and_total(): void
    {
        $user = User::factory()->create();
        $this->makeInvoice([
            'total' => '4321.99',
            'status' => InvoiceStatus::Posted,
            'due_date' => now()->addDays(10),
        ]);

        $resp = $this->actingAs($user)->get(route('invoices.index'))->assertOk();

        // The two fields the bug reported as clipped off-screen on mobile.
        $resp->assertSee('$4,321.99');
        $resp->assertSee('Posted');
    }
}
