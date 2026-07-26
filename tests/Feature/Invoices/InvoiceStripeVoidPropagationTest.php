<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Setting;
use App\Models\User;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeClientException;
use App\Services\Stripe\StripeSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Void must propagate to Stripe (psa-bl36l).
 *
 * The staff Void action applied InvoiceVoidService::void() locally and
 * propagated ONLY to QBO (voidInvoiceInQbo). A Stripe-linked invoice went
 * Void/$0 in Sound PSA while its Stripe hosted payment page stayed live and
 * payable, and syncInvoiceStatusFromStripe() early-returns on local Void
 * ("PSA wins for void") so nothing ever reconciled the divergence.
 *
 * The fix mirrors the blessed QBO void path: StripeSyncService::voidInvoiceInStripe()
 * reads the current Stripe invoice, voids an open invoice upstream, clears the
 * stored payment-page URL, records provenance, and surfaces a hard error
 * (rather than a silent all-clear) when the Stripe invoice cannot be voided —
 * e.g. it is already Paid.
 */
class InvoiceStripeVoidPropagationTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function makeInvoice(array $attrs = []): Invoice
    {
        $attrs['client_id'] ??= Client::factory()->create()->id;

        $invoice = Invoice::create(array_merge([
            'invoice_number' => 'INV-SVOID-'.str_pad((string) ++self::$seq, 4, '0', STR_PAD_LEFT),
            'invoice_date' => now()->subDays(5),
            'due_date' => now()->addDays(25),
            'subtotal' => '500.00',
            'tax' => '40.00',
            'total' => '540.00',
            'total_cost' => '200.00',
            'margin' => '300.00',
            'status' => InvoiceStatus::Synced,
        ], $attrs));

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Managed services',
            'quantity' => 5,
            'unit_price' => '100.00',
            'unit_cost' => '40.00',
            'amount' => '500.00',
            'cost_amount' => '200.00',
            'sort_order' => 0,
        ]);

        return $invoice->fresh();
    }

    // ── Service layer: voidInvoiceInStripe money-safety branches ──

    public function test_void_propagates_to_stripe_for_an_open_invoice_and_clears_the_payment_page(): void
    {
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_open',
            'stripe_invoice_url' => 'https://invoice.stripe.com/i/pay_open',
        ]);

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('getInvoice')->once()->with('in_open')
            ->andReturn(['id' => 'in_open', 'status' => 'open']);
        $stripe->shouldReceive('voidInvoice')->once()->with('in_open')
            ->andReturn(['id' => 'in_open', 'status' => 'void']);

        (new StripeSyncService($stripe))->voidInvoiceInStripe($invoice);

        $fresh = $invoice->fresh();
        $this->assertNotNull($fresh->stripe_synced_at);
        $this->assertNull($fresh->stripe_sync_error);
        // The live payment page must be gone — the show view renders it off this URL.
        $this->assertNull($fresh->stripe_invoice_url);
    }

    public function test_void_is_idempotent_when_stripe_invoice_already_void(): void
    {
        $invoice = $this->makeInvoice(['stripe_invoice_id' => 'in_already']);

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('getInvoice')->once()->with('in_already')
            ->andReturn(['id' => 'in_already', 'status' => 'void']);
        // Already void upstream — must NOT attempt another void.
        $stripe->shouldNotReceive('voidInvoice');

        (new StripeSyncService($stripe))->voidInvoiceInStripe($invoice);

        $fresh = $invoice->fresh();
        $this->assertNotNull($fresh->stripe_synced_at);
        $this->assertNull($fresh->stripe_sync_error);
    }

    public function test_void_is_idempotent_when_stripe_invoice_uncollectible(): void
    {
        $invoice = $this->makeInvoice(['stripe_invoice_id' => 'in_uncoll']);

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('getInvoice')->once()->with('in_uncoll')
            ->andReturn(['id' => 'in_uncoll', 'status' => 'uncollectible']);
        $stripe->shouldNotReceive('voidInvoice');

        (new StripeSyncService($stripe))->voidInvoiceInStripe($invoice);

        $this->assertNotNull($invoice->fresh()->stripe_synced_at);
    }

    public function test_void_refuses_and_screams_when_stripe_invoice_already_paid(): void
    {
        // The dangerous inverse ordering: the client paid on Stripe before staff
        // voided locally. Stripe cannot void a paid invoice. A silent success
        // here is exactly the false all-clear we must never emit — it must
        // surface a durable error and throw so the operator is told to reconcile.
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_paid',
            'stripe_invoice_url' => 'https://invoice.stripe.com/i/pay_paid',
        ]);

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('getInvoice')->once()->with('in_paid')
            ->andReturn(['id' => 'in_paid', 'status' => 'paid']);
        $stripe->shouldNotReceive('voidInvoice');

        try {
            (new StripeSyncService($stripe))->voidInvoiceInStripe($invoice);
            $this->fail('Expected a StripeClientException for a paid invoice.');
        } catch (StripeClientException $e) {
            // expected
        }

        $fresh = $invoice->fresh();
        $this->assertNotNull($fresh->stripe_sync_error);
        // A paid invoice's payment page is not "double-pay live" — keep the URL
        // as the record; do NOT pretend it was cleanly voided.
        $this->assertSame('https://invoice.stripe.com/i/pay_paid', $fresh->stripe_invoice_url);
    }

    public function test_void_is_a_noop_when_invoice_is_not_stripe_linked(): void
    {
        $invoice = $this->makeInvoice(); // no stripe_invoice_id

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldNotReceive('getInvoice');
        $stripe->shouldNotReceive('voidInvoice');

        (new StripeSyncService($stripe))->voidInvoiceInStripe($invoice);

        $this->assertTrue(true); // reaching here without a Mockery violation is the assertion
    }

    public function test_void_records_error_and_rethrows_when_stripe_get_fails(): void
    {
        $invoice = $this->makeInvoice(['stripe_invoice_id' => 'in_boom']);

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('getInvoice')->once()->with('in_boom')
            ->andThrow(new StripeClientException('network down'));
        $stripe->shouldNotReceive('voidInvoice');

        try {
            (new StripeSyncService($stripe))->voidInvoiceInStripe($invoice);
            $this->fail('Expected the StripeClientException to propagate.');
        } catch (StripeClientException $e) {
            $this->assertSame('network down', $e->getMessage());
        }

        $this->assertNotNull($invoice->fresh()->stripe_sync_error);
    }

    // ── Controller wiring: the staff Void action propagates to Stripe ──

    public function test_void_route_propagates_to_stripe_and_voids_locally(): void
    {
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x'); // StripeConfig::isConfigured() → true
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_route',
            'status' => InvoiceStatus::Posted,
        ]);

        $this->mock(StripeSyncService::class, function ($m) {
            $m->shouldReceive('voidInvoiceInStripe')->once();
        });

        $this->actingAs(User::factory()->create())
            ->post(route('invoices.void', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('success');

        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);
    }

    public function test_void_route_warns_when_stripe_void_fails_but_still_voids_locally(): void
    {
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x');
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_route_fail',
            'status' => InvoiceStatus::Posted,
        ]);

        $this->mock(StripeSyncService::class, function ($m) {
            $m->shouldReceive('voidInvoiceInStripe')->once()
                ->andThrow(new StripeClientException('already paid'));
        });

        $user = User::factory()->create();
        $this->actingAs($user)
            ->post(route('invoices.void', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('warning');

        // Local void must stand even though the upstream propagation failed.
        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);

        // The warning must actually be VISIBLE — a flash key the layout doesn't
        // render is a silent failure, the very thing this fix exists to prevent.
        $this->actingAs($user)->get(route('invoices.show', $invoice))
            ->assertSee('Stripe void failed');
    }

    // ── Facet (b): the "Refresh from Stripe" action must not lie ──

    public function test_refresh_from_stripe_on_a_void_invoice_reports_no_recheck(): void
    {
        // syncInvoiceStatusFromStripe() early-returns for a local Void (no vendor
        // request is made), yet the controller flashed "Invoice refreshed from
        // Stripe." — a success message for a no-op. It must be honest instead.
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_void_refresh',
            'status' => InvoiceStatus::Void,
        ]);

        $user = User::factory()->create();
        $this->actingAs($user)
            ->post(route('invoices.sync-stripe', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionMissing('success')
            ->assertSessionHas('info');

        // The honest message must render, not just live in the session.
        $this->actingAs($user)->get(route('invoices.show', $invoice))
            ->assertSee('Stripe was not re-checked');
    }
}
