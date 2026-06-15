<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceOverdueTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(array $attrs = []): Invoice
    {
        $client = Client::factory()->create();

        return Invoice::create(array_merge([
            'client_id' => $client->id,
            'invoice_number' => 'INV-TEST-'.rand(1000, 9999),
            'invoice_date' => now()->subDays(30),
            'due_date' => now()->subDays(10),
            'subtotal' => '100.00',
            'tax' => '0.00',
            'total' => '100.00',
            'status' => InvoiceStatus::Posted,
        ], $attrs));
    }

    // ── Model accessor ──

    public function test_posted_invoice_with_past_due_date_is_overdue(): void
    {
        $invoice = $this->makeInvoice([
            'status' => InvoiceStatus::Posted,
            'due_date' => now()->subDay(),
        ]);

        $this->assertTrue($invoice->isOverdue());
    }

    public function test_posted_invoice_due_in_future_is_not_overdue(): void
    {
        $invoice = $this->makeInvoice([
            'status' => InvoiceStatus::Posted,
            'due_date' => now()->addDay(),
        ]);

        $this->assertFalse($invoice->isOverdue());
    }

    public function test_paid_invoice_with_past_due_date_is_not_overdue(): void
    {
        $invoice = $this->makeInvoice([
            'status' => InvoiceStatus::Paid,
            'due_date' => now()->subDay(),
        ]);

        $this->assertFalse($invoice->isOverdue());
    }

    // ── Invoice list view ──

    public function test_invoices_list_shows_overdue_badge_for_past_due_posted_invoice(): void
    {
        $user = User::factory()->create();
        $invoice = $this->makeInvoice([
            'status' => InvoiceStatus::Posted,
            'due_date' => now()->subDay(),
        ]);

        $resp = $this->actingAs($user)->get(route('invoices.index'));

        $resp->assertOk();
        $resp->assertSee('Overdue');
    }

    public function test_invoices_list_shows_posted_for_future_due_posted_invoice(): void
    {
        $user = User::factory()->create();
        $invoice = $this->makeInvoice([
            'status' => InvoiceStatus::Posted,
            'due_date' => now()->addDay(),
        ]);

        $resp = $this->actingAs($user)->get(route('invoices.index'));

        $resp->assertOk();
        $resp->assertSee('Posted');
        $resp->assertDontSee('Overdue');
    }

    public function test_invoices_list_shows_paid_not_overdue_for_paid_invoice(): void
    {
        $user = User::factory()->create();
        $invoice = $this->makeInvoice([
            'status' => InvoiceStatus::Paid,
            'due_date' => now()->subDay(),
        ]);

        $resp = $this->actingAs($user)->get(route('invoices.index'));

        $resp->assertOk();
        $resp->assertSee('Paid');
        $resp->assertDontSee('Overdue');
    }
}
