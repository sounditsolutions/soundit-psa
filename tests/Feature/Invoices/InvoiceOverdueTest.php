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

    // ── Display helpers ──

    public function test_display_status_label_reports_overdue_for_past_due_posted_invoice(): void
    {
        $invoice = $this->makeInvoice([
            'status' => InvoiceStatus::Posted,
            'due_date' => now()->subDay(),
        ]);

        $this->assertSame('Overdue', $invoice->displayStatusLabel());
        $this->assertSame('bg-danger', $invoice->displayStatusBadgeClass());
    }

    public function test_display_status_label_falls_back_to_status_when_not_overdue(): void
    {
        $invoice = $this->makeInvoice([
            'status' => InvoiceStatus::Posted,
            'due_date' => now()->addDay(),
        ]);

        $this->assertSame('Posted', $invoice->displayStatusLabel());
        $this->assertSame(InvoiceStatus::Posted->badgeClass(), $invoice->displayStatusBadgeClass());
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
        // Assert the badge specifically — "Overdue" also appears as a filter option.
        $resp->assertSee('bg-danger">Overdue', false);
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
        // The overdue *badge* must not render; "Overdue" still exists as a filter option.
        $resp->assertDontSee('bg-danger">Overdue', false);
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
        // The overdue *badge* must not render; "Overdue" still exists as a filter option.
        $resp->assertDontSee('bg-danger">Overdue', false);
    }

    // ── Invoice list status filter ──

    public function test_status_filter_overdue_returns_only_past_due_posted_invoices(): void
    {
        $user = User::factory()->create();
        $this->makeInvoice(['invoice_number' => 'INV-ODUE', 'status' => InvoiceStatus::Posted, 'due_date' => now()->subDays(5)]);
        $this->makeInvoice(['invoice_number' => 'INV-FUT', 'status' => InvoiceStatus::Posted, 'due_date' => now()->addDays(5)]);
        $this->makeInvoice(['invoice_number' => 'INV-PDPAID', 'status' => InvoiceStatus::Paid, 'due_date' => now()->subDays(5)]);
        $this->makeInvoice(['invoice_number' => 'INV-PDSYNC', 'status' => InvoiceStatus::Synced, 'due_date' => now()->subDays(5)]);

        $resp = $this->actingAs($user)->get(route('invoices.index', ['status' => 'overdue']));

        $resp->assertOk();
        $resp->assertSee('INV-ODUE');
        $resp->assertDontSee('INV-FUT');    // posted but not yet due
        $resp->assertDontSee('INV-PDPAID'); // paid — never overdue
        $resp->assertDontSee('INV-PDSYNC'); // synced — matches the Overdue badge, which is Posted-only
    }

    public function test_status_filter_outstanding_returns_posted_and_synced_only(): void
    {
        $user = User::factory()->create();
        $this->makeInvoice(['invoice_number' => 'INV-OUTPOST', 'status' => InvoiceStatus::Posted, 'due_date' => now()->addDays(5)]);
        $this->makeInvoice(['invoice_number' => 'INV-OUTSYNC', 'status' => InvoiceStatus::Synced, 'due_date' => now()->addDays(5)]);
        $this->makeInvoice(['invoice_number' => 'INV-OUTPAID', 'status' => InvoiceStatus::Paid]);
        $this->makeInvoice(['invoice_number' => 'INV-OUTDRAFT', 'status' => InvoiceStatus::Draft]);
        $this->makeInvoice(['invoice_number' => 'INV-OUTVOID', 'status' => InvoiceStatus::Void]);

        $resp = $this->actingAs($user)->get(route('invoices.index', ['status' => 'outstanding']));

        $resp->assertOk();
        $resp->assertSee('INV-OUTPOST');
        $resp->assertSee('INV-OUTSYNC');
        $resp->assertDontSee('INV-OUTPAID');
        $resp->assertDontSee('INV-OUTDRAFT');
        $resp->assertDontSee('INV-OUTVOID');
    }

    public function test_status_dropdown_exposes_derived_overdue_and_outstanding_filters(): void
    {
        $user = User::factory()->create();

        $resp = $this->actingAs($user)->get(route('invoices.index'));

        $resp->assertOk();
        // Both derived filters are now selectable from the visible controls.
        $resp->assertSee('value="overdue"', false);
        $resp->assertSee('value="outstanding"', false);
        $resp->assertSee('Overdue');
        $resp->assertSee('Outstanding');
    }

    public function test_status_dropdown_marks_active_derived_filter_selected(): void
    {
        $user = User::factory()->create();

        $resp = $this->actingAs($user)->get(route('invoices.index', ['status' => 'overdue']));

        $resp->assertOk();
        // The active filter is reflected in the dropdown (not silently shown as "All Statuses").
        $resp->assertSee('value="overdue" selected', false);
    }

    public function test_overdue_scope_matches_is_overdue_accessor(): void
    {
        $overdue = $this->makeInvoice(['status' => InvoiceStatus::Posted, 'due_date' => now()->subDay()]);
        $future = $this->makeInvoice(['status' => InvoiceStatus::Posted, 'due_date' => now()->addDay()]);
        $paid = $this->makeInvoice(['status' => InvoiceStatus::Paid, 'due_date' => now()->subDay()]);

        $ids = Invoice::overdue()->pluck('id');

        $this->assertTrue($ids->contains($overdue->id));
        $this->assertFalse($ids->contains($future->id));
        $this->assertFalse($ids->contains($paid->id));
        // Scope and accessor agree on the same invoice.
        $this->assertSame($overdue->isOverdue(), $ids->contains($overdue->id));
    }

    // ── Invoice detail view (regression: detail must not disagree with the list) ──

    public function test_invoice_detail_shows_overdue_for_past_due_posted_invoice(): void
    {
        $user = User::factory()->create();
        $invoice = $this->makeInvoice([
            'status' => InvoiceStatus::Posted,
            'due_date' => now()->subDay(),
        ]);

        $resp = $this->actingAs($user)->get(route('invoices.show', $invoice));

        $resp->assertOk();
        $resp->assertSee('Overdue');
    }

    public function test_invoice_detail_shows_posted_for_future_due_posted_invoice(): void
    {
        $user = User::factory()->create();
        $invoice = $this->makeInvoice([
            'status' => InvoiceStatus::Posted,
            'due_date' => now()->addDay(),
        ]);

        $resp = $this->actingAs($user)->get(route('invoices.show', $invoice));

        $resp->assertOk();
        $resp->assertSee('Posted');
        $resp->assertDontSee('Overdue');
    }

    public function test_invoice_detail_shows_paid_not_overdue_for_paid_invoice(): void
    {
        $user = User::factory()->create();
        $invoice = $this->makeInvoice([
            'status' => InvoiceStatus::Paid,
            'due_date' => now()->subDay(),
        ]);

        $resp = $this->actingAs($user)->get(route('invoices.show', $invoice));

        $resp->assertOk();
        $resp->assertDontSee('Overdue');
    }
}
