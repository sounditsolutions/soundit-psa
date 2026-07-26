<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Person;
use App\Models\Setting;
use App\Models\User;
use App\Services\Qbo\QboClientException;
use App\Services\Qbo\QboSyncService;
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

    public function test_void_actually_voids_an_uncollectible_stripe_invoice(): void
    {
        // uncollectible is NOT terminal in Stripe — it can still transition to
        // paid or void, and the /void endpoint accepts it. Treating it as
        // "already converged" (the round-1 bug) leaves the invoice payable.
        // https://docs.stripe.com/invoicing/overview
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_uncoll',
            'stripe_invoice_url' => 'https://invoice.stripe.com/i/pay_uncoll',
        ]);

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('getInvoice')->once()->with('in_uncoll')
            ->andReturn(['id' => 'in_uncoll', 'status' => 'uncollectible']);
        // Must actually call /void, and the response must confirm status=void.
        $stripe->shouldReceive('voidInvoice')->once()->with('in_uncoll')
            ->andReturn(['id' => 'in_uncoll', 'status' => 'void']);

        (new StripeSyncService($stripe))->voidInvoiceInStripe($invoice);

        $fresh = $invoice->fresh();
        $this->assertNull($fresh->stripe_invoice_url); // payment page killed
        $this->assertNotNull($fresh->stripe_synced_at);
        $this->assertNull($fresh->stripe_sync_error);
    }

    public function test_void_screams_on_missing_or_unknown_stripe_status(): void
    {
        // A malformed 2xx (StripeClient maps bad JSON to []) or an unexpected
        // status must NOT fall through to voiding-and-reporting-success. A
        // degraded read must SCREAM (CLAUDE.md), not fail open into false convergence.
        foreach ([[], ['id' => 'in_x'], ['id' => 'in_x', 'status' => 'future_state']] as $i => $payload) {
            $invoice = $this->makeInvoice([
                'stripe_invoice_id' => 'in_bad'.$i,
                'stripe_invoice_url' => 'https://invoice.stripe.com/i/pay_bad'.$i,
            ]);
            $stripe = \Mockery::mock(StripeClient::class);
            $stripe->shouldReceive('getInvoice')->once()->andReturn($payload);
            $stripe->shouldNotReceive('voidInvoice');

            try {
                (new StripeSyncService($stripe))->voidInvoiceInStripe($invoice);
                $this->fail('Expected a throw for payload index '.$i);
            } catch (StripeClientException $e) {
                // expected
            }

            $fresh = $invoice->fresh();
            $this->assertNotNull($fresh->stripe_sync_error, 'error not recorded for payload '.$i);
            $this->assertSame('https://invoice.stripe.com/i/pay_bad'.$i, $fresh->stripe_invoice_url, 'URL wrongly cleared for payload '.$i);
        }
    }

    public function test_void_screams_when_the_void_response_is_not_confirmed(): void
    {
        // The POST /void response must confirm status=void before we record
        // convergence and clear the URL. An empty/unconfirmed response must throw.
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_unconf',
            'stripe_invoice_url' => 'https://invoice.stripe.com/i/pay_unconf',
        ]);
        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('getInvoice')->once()->with('in_unconf')
            ->andReturn(['id' => 'in_unconf', 'status' => 'open']);
        $stripe->shouldReceive('voidInvoice')->once()->with('in_unconf')
            ->andReturn([]); // malformed / unconfirmed

        try {
            (new StripeSyncService($stripe))->voidInvoiceInStripe($invoice);
            $this->fail('Expected a throw when the void response is unconfirmed.');
        } catch (StripeClientException $e) {
            // expected
        }

        $fresh = $invoice->fresh();
        $this->assertNotNull($fresh->stripe_sync_error);
        // URL must be kept — we did NOT prove the page is dead.
        $this->assertSame('https://invoice.stripe.com/i/pay_unconf', $fresh->stripe_invoice_url);
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

        // The controller surfaces the service's per-cause message verbatim
        // (psa-bl36l MF6) rather than a hardcoded string, so an API-failure
        // reads honestly as "page may still be live — void it manually".
        $this->mock(StripeSyncService::class, function ($m) {
            $m->shouldReceive('voidInvoiceInStripe')->once()
                ->andThrow(new StripeClientException('the hosted payment page may still be live — void it manually in Stripe.'));
        });

        $user = User::factory()->create();
        $this->actingAs($user)
            ->post(route('invoices.void', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('warning');

        // Local void must stand even though the upstream propagation failed.
        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);

        // The warning must actually be VISIBLE — a flash key no view renders is a
        // silent failure, the very thing this fix exists to prevent.
        $this->actingAs($user)->get(route('invoices.show', $invoice))
            ->assertSee('void it manually in Stripe');
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

    // ── MF3: a linked-but-unconfigured Stripe invoice must SCREAM, not silently succeed ──

    public function test_void_route_records_error_and_warns_when_stripe_linked_but_unconfigured(): void
    {
        // No stripe_secret_key set → StripeConfig::isConfigured() is false. A
        // linked invoice (stripe_invoice_id present) must not become a clean
        // local success while the hosted page stays live.
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_nocfg',
            'stripe_invoice_url' => 'https://invoice.stripe.com/i/pay_nocfg',
            'status' => InvoiceStatus::Posted,
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('invoices.void', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('warning');

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Void, $fresh->status); // local void stands
        $this->assertNotNull($fresh->stripe_sync_error);        // durable divergence recorded
    }

    // ── MF4: a QBO failure must NOT suppress the Stripe void attempt ──

    public function test_void_route_attempts_stripe_even_when_qbo_void_fails(): void
    {
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x');
        $invoice = $this->makeInvoice([
            'qbo_invoice_id' => 'qbo_1',
            'stripe_invoice_id' => 'in_dual',
            'status' => InvoiceStatus::Posted,
        ]);

        $this->mock(QboSyncService::class, function ($m) {
            $m->shouldReceive('voidInvoiceInQbo')->once()
                ->andThrow(new QboClientException('qbo down'));
        });
        $this->mock(StripeSyncService::class, function ($m) {
            // The Stripe void MUST still be attempted despite the QBO failure.
            $m->shouldReceive('voidInvoiceInStripe')->once();
        });

        $this->actingAs(User::factory()->create())
            ->post(route('invoices.void', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('warning');

        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);
    }

    // ── MF5: payment affordances must never render on a locally-Void invoice ──

    public function test_is_client_payable_only_for_posted_and_synced(): void
    {
        $this->assertTrue(InvoiceStatus::Posted->isClientPayable());
        $this->assertTrue(InvoiceStatus::Synced->isClientPayable());
        $this->assertFalse(InvoiceStatus::Void->isClientPayable());
        $this->assertFalse(InvoiceStatus::Paid->isClientPayable());
        $this->assertFalse(InvoiceStatus::Draft->isClientPayable());
        $this->assertFalse(InvoiceStatus::PendingSync->isClientPayable());
    }

    public function test_staff_invoice_show_hides_payment_page_for_void_even_with_retained_url(): void
    {
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_void_url',
            'stripe_invoice_url' => 'https://invoice.stripe.com/i/pay_retained',
            'status' => InvoiceStatus::Void,
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertDontSee('Payment Page')   // the button
            ->assertDontSee('pay_retained');  // the live URL must not be handed out
    }

    public function test_portal_payment_status_poll_returns_no_url_for_a_void_invoice(): void
    {
        // The polling endpoint takes a route-bound invoice and gated the URL only
        // on stripe_invoice_url — so a just-ordered invoice that staff later void
        // (URL retained for audit) would still be served to the client. It must
        // gate on a payable status instead.
        Setting::setValue('portal_enabled', '1');
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Portal',
            'last_name' => 'User',
            'email' => 'poll-'.uniqid().'@example.test',
            'is_active' => true,
            'portal_enabled' => true,
            'company_wide_access' => true,
        ]);
        $invoice = $this->makeInvoice([
            'client_id' => $client->id,
            'stripe_invoice_id' => 'in_poll',
            'stripe_invoice_url' => 'https://invoice.stripe.com/i/pay_poll',
            'status' => InvoiceStatus::Void,
        ]);

        $this->actingAs($person, 'portal')
            ->getJson(route('portal.prepaid.payment-status', $invoice))
            ->assertOk()
            ->assertJsonPath('payment_url', null);
    }

    // ── MF6: the paid warning must say reconcile/refund (not "void manually"), rendered once ──

    public function test_void_route_paid_warning_says_reconcile_and_renders_once(): void
    {
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x');
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_paid_msg',
            'status' => InvoiceStatus::Posted,
        ]);

        $this->mock(StripeSyncService::class, function ($m) {
            $m->shouldReceive('voidInvoiceInStripe')->once()
                ->andThrow(new StripeClientException('Stripe invoice in_paid_msg is already paid and cannot be voided upstream — reconcile or refund it manually in Stripe.'));
        });

        $user = User::factory()->create();
        $this->actingAs($user)
            ->post(route('invoices.void', $invoice))
            ->assertSessionHas('warning', fn ($w) => str_contains($w, 'reconcile or refund')
                // must NOT carry the open/API-failure phrasing on a paid invoice
                && ! str_contains($w, 'payment page may still be live'));

        // The message must render exactly once (layout vs page-local dedup, MF6).
        $html = $this->actingAs($user)->get(route('invoices.show', $invoice))->getContent();
        $this->assertSame(1, substr_count($html, 'reconcile or refund'));
    }

    // ── MF7: bulk void must report the local void truthfully, not "0 voided, 1 failed" ──

    public function test_bulk_void_reports_local_void_plus_stripe_reconcile_failure(): void
    {
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x');
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_bulk',
            'invoice_number' => 'INV-BULK-VOID',
            'status' => InvoiceStatus::Posted,
        ]);

        $this->mock(StripeSyncService::class, function ($m) {
            $m->shouldReceive('voidInvoiceInStripe')->once()
                ->andThrow(new StripeClientException('boom'));
        });

        $this->actingAs(User::factory()->create())
            ->post(route('invoices.bulk-action'), ['action' => 'void', 'invoice_ids' => [$invoice->id]])
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHas('warning', fn ($w) => ! str_contains($w, '0 invoice(s) voided')
                && str_contains($w, '1 invoice(s) voided'));

        // The local void must have committed even though Stripe propagation failed.
        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);
    }

    // ── MF8: a stale in-flight status pull must not erase a void-propagation error ──

    public function test_status_pull_does_not_clear_a_void_propagation_error(): void
    {
        // voidInvoiceInStripe() recorded a durable stripe_sync_error; a status
        // pull that started before the void resolves afterward and its locked
        // write includes stripe_sync_error => null. recordStatusPullResult must
        // preserve the error on a Void row, not erase the only divergence signal.
        $invoice = $this->makeInvoice([
            'status' => InvoiceStatus::Void,
            'stripe_sync_error' => 'already paid — reconcile',
        ]);

        $wasVoid = $invoice->recordStatusPullResult([
            'total' => '999.00',
            'status' => InvoiceStatus::Paid,
            'stripe_synced_at' => now(),
            'stripe_sync_error' => null,
        ]);

        $fresh = $invoice->fresh();
        $this->assertTrue($wasVoid);
        $this->assertSame(InvoiceStatus::Void, $fresh->status);              // not re-inflated
        $this->assertSame('540.00', $fresh->total);                         // stale money write refused
        $this->assertSame('already paid — reconcile', $fresh->stripe_sync_error); // error preserved
    }
}
