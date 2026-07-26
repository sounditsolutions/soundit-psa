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
use App\Services\InvoiceVoidService;
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
 *
 * R5 makes push/send LINEARIZABLE with void: InvoiceVoidService, the push's
 * result write (Invoice::recordPushResult), and the standalone send
 * authorization (StripeSyncService::sendInvoiceEmail) all lock the invoice
 * row, so a void either lands before a push/send boundary (compensation /
 * refusal — never an email) or after it (the void reads the recorded Stripe id
 * and kills the upstream page itself). Confirmed compensation is convergence,
 * not a durable divergence error (MF3); bulk void carries per-cause
 * reconciliation guidance (MF2); both portal poll endpoints go terminal on a
 * live Posted→Void transition (MF4).
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
            // Non-taxable so the Stripe push takes the amount path (no SKU needed)
            // — keeps the push-path tests focused on void-safety, not SKU resolution.
            'is_taxable' => false,
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

    public function test_void_screams_on_missing_unknown_or_mismatched_stripe_get(): void
    {
        // A missing status, an unexpected status, OR a response identifying a
        // DIFFERENT invoice must NOT fall through to voiding-and-reporting-success.
        // A degraded read must SCREAM (CLAUDE.md), not fail open (MF2 + R2B GET identity).
        $cases = [
            'empty' => [],                                            // no id, no status
            'no_status' => ['id' => '__SELF__'],                      // right invoice, no status
            'unknown_status' => ['id' => '__SELF__', 'status' => 'wat'], // right invoice, unknown status
            'wrong_id' => ['id' => 'in_someone_else', 'status' => 'open'], // different invoice
        ];

        foreach ($cases as $name => $payload) {
            $invoice = $this->makeInvoice([
                'stripe_invoice_id' => 'in_bad_'.$name,
                'stripe_invoice_url' => 'https://invoice.stripe.com/i/pay_'.$name,
            ]);
            if (($payload['id'] ?? null) === '__SELF__') {
                $payload['id'] = $invoice->stripe_invoice_id;
            }

            $stripe = \Mockery::mock(StripeClient::class);
            $stripe->shouldReceive('getInvoice')->once()->andReturn($payload);
            $stripe->shouldNotReceive('voidInvoice');

            try {
                (new StripeSyncService($stripe))->voidInvoiceInStripe($invoice);
                $this->fail('Expected a throw for case '.$name);
            } catch (StripeClientException $e) {
                // expected
            }

            $fresh = $invoice->fresh();
            $this->assertNotNull($fresh->stripe_sync_error, 'error not recorded for '.$name);
            $this->assertSame('https://invoice.stripe.com/i/pay_'.$name, $fresh->stripe_invoice_url, 'URL wrongly cleared for '.$name);
        }
    }

    public function test_void_screams_when_the_void_response_is_for_a_different_invoice(): void
    {
        // R2B — a /void response identifying ANOTHER invoice (or none) is not proof
        // the target was voided; it must not clear the URL or record convergence.
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_me',
            'stripe_invoice_url' => 'https://invoice.stripe.com/i/pay_me',
        ]);
        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('getInvoice')->once()->with('in_me')
            ->andReturn(['id' => 'in_me', 'status' => 'open']);
        $stripe->shouldReceive('voidInvoice')->once()->with('in_me')
            ->andReturn(['id' => 'in_someone_else', 'status' => 'void']);

        try {
            (new StripeSyncService($stripe))->voidInvoiceInStripe($invoice);
            $this->fail('Expected a throw when the void response is for a different invoice.');
        } catch (StripeClientException $e) {
            // expected
        }

        $fresh = $invoice->fresh();
        $this->assertNotNull($fresh->stripe_sync_error);
        $this->assertSame('https://invoice.stripe.com/i/pay_me', $fresh->stripe_invoice_url);
    }

    public function test_stripe_client_screams_on_a_scalar_json_body(): void
    {
        // R2C — a valid-JSON scalar 2xx (true/1/"ok") must become a
        // StripeClientException, not a TypeError that bypasses every catch and
        // leaves a locally-voided invoice with no recorded sync error.
        foreach (['true', '1', '"ok"'] as $body) {
            $mock = new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(200, [], $body)]);
            $http = new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create($mock)]);
            $client = new StripeClient(['secret_key' => 'sk_test'], $http);

            try {
                $client->getInvoice('in_scalar');
                $this->fail('Expected a StripeClientException for scalar body '.$body);
            } catch (StripeClientException $e) {
                $this->assertStringContainsString('non-object', $e->getMessage());
            }
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

    public function test_bulk_void_paid_stripe_failure_carries_reconcile_refund_guidance(): void
    {
        // R4 MF2: bulk must preserve the PER-CAUSE action, not flatten every
        // Stripe failure into "void these manually … the payment page may still
        // be live" — impossible advice for a PAID Stripe invoice, and it
        // contradicts the durable sync error on the same invoice. Single-path
        // copy is pinned by test_void_route_paid_warning_says_reconcile_and_renders_once;
        // this pins bulk to the same cause text so the two cannot drift.
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x');
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_bulk_paid',
            'invoice_number' => 'INV-BULK-PAID',
            'status' => InvoiceStatus::Posted,
        ]);

        $this->mock(StripeSyncService::class, function ($m) {
            $m->shouldReceive('voidInvoiceInStripe')->once()
                ->andThrow(new StripeClientException('Stripe invoice in_bulk_paid is already paid and cannot be voided upstream — reconcile or refund it manually in Stripe.'));
        });

        $this->actingAs(User::factory()->create())
            ->post(route('invoices.bulk-action'), ['action' => 'void', 'invoice_ids' => [$invoice->id]])
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHas('warning', fn ($w) => str_contains($w, '1 invoice(s) voided')
                && str_contains($w, 'INV-BULK-PAID')                       // names the invoice
                && str_contains($w, 'reconcile or refund')                 // the correct action for paid
                && ! str_contains($w, 'payment page may still be live')    // wrong action for paid
                && ! str_contains($w, 'void these manually'));             // the old blanket copy

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

    // ── R2A: the QBO arm + awaitingSync of the portal poll must also gate on payability ──

    public function test_portal_payment_status_poll_returns_no_url_for_a_void_qbo_invoice(): void
    {
        // Round 2 caught that only the Stripe arm was gated; a Void invoice with a
        // qbo_invoice_id + configured billing URL still returned a pay URL.
        Setting::setValue('portal_enabled', '1');
        Setting::setValue('portal_billing_url', 'https://billing.example.test');
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Portal', 'last_name' => 'User',
            'email' => 'pollqbo-'.uniqid().'@example.test',
            'is_active' => true, 'portal_enabled' => true, 'company_wide_access' => true,
        ]);
        $invoice = $this->makeInvoice([
            'client_id' => $client->id,
            'qbo_invoice_id' => 'qbo_void',
            'status' => InvoiceStatus::Void,
        ]);

        $this->actingAs($person, 'portal')
            ->getJson(route('portal.prepaid.payment-status', $invoice))
            ->assertOk()
            ->assertJsonPath('payment_url', null);
    }

    // ── R2D: a Void portal confirmation must show a truthful terminal state ──

    public function test_portal_prepaid_confirmation_is_terminal_for_a_void_invoice(): void
    {
        Setting::setValue('portal_enabled', '1');
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Portal', 'last_name' => 'User',
            'email' => 'conf-'.uniqid().'@example.test',
            'is_active' => true, 'portal_enabled' => true, 'company_wide_access' => true,
        ]);
        $invoice = $this->makeInvoice([
            'client_id' => $client->id,
            'stripe_invoice_id' => 'in_conf',
            'stripe_invoice_url' => 'https://invoice.stripe.com/i/pay_conf',
            'status' => InvoiceStatus::Void,
        ]);

        $this->actingAs($person, 'portal')
            ->get(route('portal.prepaid.confirmation', $invoice))
            ->assertOk()
            ->assertDontSee('payment link shortly')   // no false "payment coming"
            ->assertDontSee('pay_conf')               // no live Stripe pay URL
            ->assertDontSee('View Invoice')           // dead link (Void 404s in portal) suppressed
            ->assertSee('not payable');               // truthful terminal state
    }

    // ── R4/A.3: portal label/badge must not call a Void invoice "Unpaid" ──

    public function test_void_portal_label_and_badge_are_not_unpaid(): void
    {
        $this->assertSame('Void', InvoiceStatus::Void->portalLabel());
        $this->assertNotSame('Unpaid', InvoiceStatus::Void->portalLabel());
        $this->assertSame('bg-secondary', InvoiceStatus::Void->portalBadgeClass());
        // Posted/Paid unchanged.
        $this->assertSame('Unpaid', InvoiceStatus::Posted->portalLabel());
        $this->assertSame('Paid', InvoiceStatus::Paid->portalLabel());
    }

    // ── R4/A.1: the poll must signal not-payable so the JS can stop + go terminal ──

    public function test_poll_signals_not_client_payable_for_a_void_invoice(): void
    {
        Setting::setValue('portal_enabled', '1');
        $client = Client::factory()->create();
        $person = $this->portalPerson($client);
        $invoice = $this->makeInvoice([
            'client_id' => $client->id,
            'stripe_invoice_id' => 'in_pollsig',
            'stripe_invoice_url' => 'https://invoice.stripe.com/i/pay_pollsig',
            'status' => InvoiceStatus::Void,
        ]);

        $this->actingAs($person, 'portal')
            ->getJson(route('portal.prepaid.payment-status', $invoice))
            ->assertOk()
            ->assertJsonPath('payment_url', null)
            ->assertJsonPath('client_payable', false)
            ->assertJsonPath('status_label', 'Void');
    }

    // ── R4 MF4: LIVE Posted→Void poll transition, in BOTH portal order flows ──

    public function test_shop_poll_goes_terminal_when_a_posted_order_is_voided_mid_poll(): void
    {
        // The sequence the confirmation JS actually lives through: the order is
        // Posted and awaiting its payment link (the poll keeps running), then
        // staff void it between polls. The next poll must stop the loop —
        // payability false, truthful label, and never a pay URL.
        Setting::setValue('portal_enabled', '1');
        $client = Client::factory()->create();
        $person = $this->portalPerson($client);
        $invoice = $this->makeInvoice([
            'client_id' => $client->id,
            'status' => InvoiceStatus::Posted,
        ]);

        // First poll while Posted: still payable — the JS keeps polling.
        $this->actingAs($person, 'portal')
            ->getJson(route('portal.shop.payment-status', $invoice))
            ->assertOk()
            ->assertJsonPath('client_payable', true)
            ->assertJsonPath('payment_url', null);

        // Staff void lands between polls.
        app(InvoiceVoidService::class)->void($invoice->fresh());

        // Next poll: terminal.
        $this->actingAs($person, 'portal')
            ->getJson(route('portal.shop.payment-status', $invoice))
            ->assertOk()
            ->assertJsonPath('client_payable', false)
            ->assertJsonPath('status_label', 'Void')
            ->assertJsonPath('payment_url', null);
    }

    public function test_prepaid_poll_goes_terminal_when_a_posted_order_is_voided_mid_poll(): void
    {
        Setting::setValue('portal_enabled', '1');
        $client = Client::factory()->create();
        $person = $this->portalPerson($client);
        $invoice = $this->makeInvoice([
            'client_id' => $client->id,
            'status' => InvoiceStatus::Posted,
        ]);

        $this->actingAs($person, 'portal')
            ->getJson(route('portal.prepaid.payment-status', $invoice))
            ->assertOk()
            ->assertJsonPath('client_payable', true)
            ->assertJsonPath('payment_url', null);

        app(InvoiceVoidService::class)->void($invoice->fresh());

        $this->actingAs($person, 'portal')
            ->getJson(route('portal.prepaid.payment-status', $invoice))
            ->assertOk()
            ->assertJsonPath('client_payable', false)
            ->assertJsonPath('status_label', 'Void')
            ->assertJsonPath('payment_url', null);
    }

    // ── R4/B: push/send fresh-locked-status gate (prove the push+send paths) ──

    public function test_push_refuses_for_an_already_void_invoice_no_stripe_call(): void
    {
        // Replay vector: a captured "Push & Email" POST replayed after a void
        // must not mint+email a live hosted invoice. R6: the refusal is
        // WRITE-FREE — refusing changed nothing upstream, and a durable
        // refusal message would replace the per-cause provenance the void
        // path owns (here: paid → reconcile/refund, the operator's only
        // remaining instruction for money already collected upstream).
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_x']);
        $invoice = $this->makeInvoice([
            'client_id' => $client->id,
            'status' => InvoiceStatus::Void,
            'stripe_invoice_id' => 'in_owned',
            'stripe_sync_error' => 'Stripe invoice in_owned is already paid and cannot be voided upstream — reconcile or refund it manually in Stripe.',
        ]);

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldNotReceive('createInvoice');
        $stripe->shouldNotReceive('sendInvoice');

        try {
            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice, true);
            $this->fail('Expected a refusal for an already-void invoice.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('voided', strtolower($e->getMessage()));
        }

        $fresh = $invoice->fresh();
        $this->assertStringContainsString('reconcile or refund', $fresh->stripe_sync_error);
        $this->assertStringNotContainsString('was not pushed', $fresh->stripe_sync_error);
    }

    public function test_push_void_refusal_leaves_a_converged_void_rows_error_null(): void
    {
        // The other half of the write-free refusal (R6): a Void row whose
        // propagation PROVED convergence carries error=null — writing a
        // refusal message onto it would mint a false "Stripe may not reflect
        // this void yet" alarm out of a replayed POST that did nothing.
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_x']);
        $invoice = $this->makeInvoice([
            'client_id' => $client->id,
            'status' => InvoiceStatus::Void,
            'stripe_invoice_id' => 'in_conv',
            'stripe_sync_error' => null,
        ]);

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldNotReceive('createInvoice');

        try {
            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice);
            $this->fail('Expected a refusal for an already-void invoice.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('voided', strtolower($e->getMessage()));
        }

        $this->assertNull($invoice->fresh()->stripe_sync_error);
    }

    public function test_push_compensates_and_does_not_email_when_void_commits_mid_flight(): void
    {
        // The dangerous race: a push started before Void completes after it — here
        // the void commits before the LOCKED result boundary (recordPushResult),
        // so the boundary sees Void. The just-created Stripe invoice must be
        // voided upstream (compensation), the client must NOT be emailed, and no
        // payable URL may be stored. With compensation CONFIRMED (matching id +
        // status=void) the upstream is exactly as converged as a normal void
        // propagation, so NO durable divergence error may persist (R4 MF3): the
        // aborted push surfaces ONCE via the thrown message (the flash), while
        // the invoice page truthfully shows convergence.
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_x']);
        $invoice = $this->makeInvoice(['client_id' => $client->id, 'status' => InvoiceStatus::Posted]);

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('createInvoice')->once()->andReturn(['id' => 'in_mid']);
        $stripe->shouldReceive('createInvoiceItem')->andReturn([]);
        $stripe->shouldReceive('finalizeInvoice')->once()->with('in_mid')
            ->andReturnUsing(function () use ($invoice) {
                // A concurrent staff Void commits mid-round-trip — the REAL void
                // service on a fresh DB copy, exactly like another process
                // (zeroes money, snapshots pre-void amounts).
                app(InvoiceVoidService::class)->void(Invoice::findOrFail($invoice->id));

                return ['id' => 'in_mid', 'status' => 'open', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/pay_mid', 'tax' => 4000, 'total' => 54000];
            });
        // Compensation: the new invoice is voided upstream (matching id/status).
        $stripe->shouldReceive('voidInvoice')->once()->with('in_mid')
            ->andReturn(['id' => 'in_mid', 'status' => 'void']);
        // The client must NOT be emailed.
        $stripe->shouldNotReceive('sendInvoice');

        try {
            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice, true);
            $this->fail('Expected a loud failure after mid-flight void compensation.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('voided upstream to compensate', $e->getMessage());
            $this->assertStringContainsString('NOT emailed', $e->getMessage());
        }

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Void, $fresh->status);
        $this->assertSame('0.00', $fresh->total);                 // stays zeroed — no re-inflation
        $this->assertNull($fresh->stripe_invoice_url);            // no live pay page stored
        $this->assertSame('in_mid', $fresh->stripe_invoice_id);   // kept for reconciliation
        $this->assertNull($fresh->stripe_sync_error);             // MF3: confirmed compensation is NOT a divergence
        $this->assertNotNull($fresh->stripe_synced_at);

        // Operator view: converged, not a permanent false alarm (R4 MF3).
        $this->actingAs(User::factory()->create())
            ->get(route('invoices.show', $invoice))
            ->assertSee('Stripe shows this invoice as voided')
            ->assertDontSee('may not reflect this void yet');
    }

    public function test_push_compensation_unconfirmed_records_a_loud_durable_error(): void
    {
        // Same race, but the compensating void CANNOT be confirmed (the response
        // identifies a different invoice). The page may still be live — that IS
        // a divergence, so the durable error must persist and the operator view
        // must keep alarming (MF3 reserves silence for CONFIRMED compensation).
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_x']);
        $invoice = $this->makeInvoice(['client_id' => $client->id, 'status' => InvoiceStatus::Posted]);

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('createInvoice')->once()->andReturn(['id' => 'in_unc']);
        $stripe->shouldReceive('createInvoiceItem')->andReturn([]);
        $stripe->shouldReceive('finalizeInvoice')->once()->with('in_unc')
            ->andReturnUsing(function () use ($invoice) {
                app(InvoiceVoidService::class)->void(Invoice::findOrFail($invoice->id));

                return ['id' => 'in_unc', 'status' => 'open', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/pay_unc', 'tax' => 4000, 'total' => 54000];
            });
        $stripe->shouldReceive('voidInvoice')->once()->with('in_unc')
            ->andReturn(['id' => 'in_someone_else', 'status' => 'void']); // NOT proof for in_unc
        $stripe->shouldNotReceive('sendInvoice');

        try {
            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice, true);
            $this->fail('Expected a loud failure after unconfirmed compensation.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('could NOT be confirmed voided', $e->getMessage());
        }

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Void, $fresh->status);
        $this->assertNull($fresh->stripe_invoice_url);
        $this->assertSame('in_unc', $fresh->stripe_invoice_id);
        $this->assertNotNull($fresh->stripe_sync_error);          // durable divergence
        $this->assertStringContainsString('could NOT be confirmed voided', $fresh->stripe_sync_error);

        // Operator view: the divergence alarm must persist.
        $this->actingAs(User::factory()->create())
            ->get(route('invoices.show', $invoice))
            ->assertSee('may not reflect this void yet')
            ->assertSee('could NOT be confirmed voided')
            ->assertDontSee('Stripe shows this invoice as voided');
    }

    public function test_record_push_result_nulls_url_and_keeps_error_on_void(): void
    {
        // Residual-race backstop: even if a push reaches recordPushResult with a
        // hosted URL and error=null while the locked row is Void, the URL must not
        // be stored and an existing divergence error must not be cleared.
        $invoice = $this->makeInvoice([
            'status' => InvoiceStatus::Void,
            'stripe_sync_error' => 'divergence: already paid',
        ]);

        $invoice->recordPushResult([
            'stripe_invoice_id' => 'in_rp',
            'stripe_invoice_url' => 'https://invoice.stripe.com/i/pay_rp',
            'total' => '999.00',
            'stripe_synced_at' => now(),
            'stripe_sync_error' => null,
        ]);

        $fresh = $invoice->fresh();
        $this->assertSame('in_rp', $fresh->stripe_invoice_id);              // id recorded
        $this->assertNull($fresh->stripe_invoice_url);                     // URL NOT stored
        $this->assertSame('divergence: already paid', $fresh->stripe_sync_error); // error preserved
        $this->assertSame('540.00', $fresh->total);                        // money not re-inflated
    }

    // ── R5: the locked boundary makes push/send LINEARIZABLE with void ──

    public function test_a_void_landing_after_the_push_boundary_sees_the_recorded_id_and_kills_the_upstream_page(): void
    {
        // The residual window: void commits AFTER the locked result boundary,
        // while the email is in flight. Linearizably the push simply happened
        // first — and the window is SAFE because the boundary persisted the
        // Stripe id BEFORE any email could go out, so the void (whose own
        // locked read sees that id) propagates upstream and kills the page
        // itself. Nothing is silent: no lost id, no stale clobber of the
        // committed Void, no surviving payable URL.
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x');
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_x']);
        $invoice = $this->makeInvoice(['client_id' => $client->id, 'status' => InvoiceStatus::Posted]);
        $staff = User::factory()->create();

        $stripe = \Mockery::mock(StripeClient::class);
        // The staff void route resolves StripeSyncService from the container —
        // bind the same mock so its propagation is observable here.
        $this->app->instance(StripeClient::class, $stripe);

        $stripe->shouldReceive('createInvoice')->once()->andReturn(['id' => 'in_after']);
        $stripe->shouldReceive('createInvoiceItem')->andReturn([]);
        $stripe->shouldReceive('finalizeInvoice')->once()->with('in_after')
            ->andReturn(['id' => 'in_after', 'status' => 'open', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/pay_after', 'tax' => 4000, 'total' => 54000]);
        $stripe->shouldReceive('sendInvoice')->once()->with('in_after')
            ->andReturnUsing(function () use ($invoice, $staff) {
                // The load-bearing ordering: by the time any email can be in
                // flight, the id is already durable.
                $this->assertSame('in_after', $invoice->fresh()->stripe_invoice_id);

                // Staff void lands NOW — the full route: local void + upstream
                // propagation (it must see the freshly-recorded id).
                $this->actingAs($staff)->post(route('invoices.void', $invoice))->assertRedirect();

                return [];
            });
        // The void's OWN propagation kills the just-created upstream invoice.
        $stripe->shouldReceive('getInvoice')->once()->with('in_after')
            ->andReturn(['id' => 'in_after', 'status' => 'open']);
        $stripe->shouldReceive('voidInvoice')->once()->with('in_after')
            ->andReturn(['id' => 'in_after', 'status' => 'void']);

        // The push itself succeeds — at its boundary the invoice was live.
        (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice, true);

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Void, $fresh->status);   // void not clobbered post-boundary
        $this->assertSame('0.00', $fresh->total);                 // stays zeroed
        $this->assertNull($fresh->stripe_invoice_url);            // upstream page killed by the void
        $this->assertSame('in_after', $fresh->stripe_invoice_id);
        $this->assertNull($fresh->stripe_sync_error);             // confirmed propagation — converged
    }

    public function test_push_email_failure_after_boundary_records_pushed_but_not_emailed(): void
    {
        // The push result is committed BEFORE the email attempt, so an email
        // failure must not orphan the created Stripe invoice (the pre-R5 flow
        // recorded nothing on a send failure) and must say exactly what
        // happened: pushed, not emailed.
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_x']);
        $invoice = $this->makeInvoice(['client_id' => $client->id, 'status' => InvoiceStatus::Posted]);

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('createInvoice')->once()->andReturn(['id' => 'in_mailfail']);
        $stripe->shouldReceive('createInvoiceItem')->andReturn([]);
        $stripe->shouldReceive('finalizeInvoice')->once()->with('in_mailfail')
            ->andReturn(['id' => 'in_mailfail', 'status' => 'open', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/pay_mailfail', 'tax' => 4000, 'total' => 54000]);
        $stripe->shouldReceive('sendInvoice')->once()->with('in_mailfail')
            ->andThrow(new StripeClientException('smtp exploded'));

        try {
            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice, true);
            $this->fail('Expected the email failure to surface.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('pushed to Stripe but the email could not be sent', $e->getMessage());
        }

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Synced, $fresh->status);                    // push committed
        $this->assertSame('in_mailfail', $fresh->stripe_invoice_id);                 // not orphaned
        $this->assertSame('https://invoice.stripe.com/i/pay_mailfail', $fresh->stripe_invoice_url); // live invoice, URL legitimate
        $this->assertStringContainsString('email could not be sent', $fresh->stripe_sync_error);
    }

    public function test_send_authorization_reads_the_db_under_lock_not_the_callers_model(): void
    {
        // The R4 defect class: an unlocked check-then-act. The send authorization
        // now happens at a LOCKED read inside the service, so a caller holding a
        // stale in-memory model (still Synced) whose ROW is already Void must be
        // refused with no vendor call — and the refusal must NOT clobber the
        // per-cause divergence error a void propagation recorded.
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_stale',
            'stripe_invoice_url' => 'https://invoice.stripe.com/i/pay_stale',
            'status' => InvoiceStatus::Synced,
        ]);
        $stale = Invoice::findOrFail($invoice->id); // in-memory: Synced
        Invoice::where('id', $invoice->id)->update([ // another process voids + records divergence
            'status' => InvoiceStatus::Void->value,
            'stripe_sync_error' => 'already paid — reconcile or refund',
        ]);

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldNotReceive('sendInvoice');

        try {
            (new StripeSyncService($stripe))->sendInvoiceEmail($stale);
            $this->fail('Expected a refusal for a voided row despite the stale model.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('it was not emailed', $e->getMessage());
            // R6 (product lane): the refusal surfaces the DURABLE per-cause
            // action. A paid upstream invoice CANNOT be voided, so blanket
            // "void invoice … in Stripe" advice would contradict the recorded
            // reconcile/refund instruction on the very same page.
            $this->assertStringContainsString('already paid — reconcile or refund', $e->getMessage());
            $this->assertStringNotContainsString('void invoice in_stale in Stripe', $e->getMessage());
        }

        $this->assertSame('already paid — reconcile or refund', $invoice->fresh()->stripe_sync_error);
    }

    public function test_send_refusal_for_a_converged_void_gives_no_manual_void_advice(): void
    {
        // R6 (product lane): when the void propagation PROVED convergence
        // (error=null) there is no divergence to act on — the refusal says
        // only that the invoice was not emailed, prescribing nothing.
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_convsend',
            'status' => InvoiceStatus::Void,
            'stripe_sync_error' => null,
        ]);

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldNotReceive('sendInvoice');

        try {
            (new StripeSyncService($stripe))->sendInvoiceEmail($invoice);
            $this->fail('Expected a refusal for a voided invoice.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('it was not emailed', $e->getMessage());
            $this->assertStringNotContainsString('may still be live', $e->getMessage());
            $this->assertStringNotContainsString('void invoice', $e->getMessage());
        }

        $this->assertNull($invoice->fresh()->stripe_sync_error);
    }

    public function test_a_void_landing_after_the_send_authorization_reconciles_via_its_own_propagation(): void
    {
        // Send-path residual window: authorization (locked) sees a live invoice,
        // then a void lands while the vendor send is in flight. The id has been
        // durable since the original push, so the void's propagation kills the
        // upstream page — the emailed link goes dead, nothing is silent.
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x');
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_sendwin',
            'stripe_invoice_url' => 'https://invoice.stripe.com/i/pay_sendwin',
            'status' => InvoiceStatus::Synced,
        ]);
        $staff = User::factory()->create();

        $stripe = \Mockery::mock(StripeClient::class);
        $this->app->instance(StripeClient::class, $stripe);

        $stripe->shouldReceive('sendInvoice')->once()->with('in_sendwin')
            ->andReturnUsing(function () use ($invoice, $staff) {
                $this->actingAs($staff)->post(route('invoices.void', $invoice))->assertRedirect();

                return [];
            });
        $stripe->shouldReceive('getInvoice')->once()->with('in_sendwin')
            ->andReturn(['id' => 'in_sendwin', 'status' => 'open']);
        $stripe->shouldReceive('voidInvoice')->once()->with('in_sendwin')
            ->andReturn(['id' => 'in_sendwin', 'status' => 'void']);

        // The send succeeds — at its locked boundary the invoice was live.
        (new StripeSyncService($stripe))->sendInvoiceEmail($invoice);

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Void, $fresh->status);
        $this->assertNull($fresh->stripe_invoice_url);   // page killed by the void's propagation
        $this->assertNull($fresh->stripe_sync_error);    // confirmed — converged
    }

    public function test_send_route_delegates_to_the_service_send_boundary(): void
    {
        // The controller must not carry its own (unlockable) status check — the
        // service's locked boundary is the single authorization seam.
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_del',
            'status' => InvoiceStatus::Synced,
        ]);

        $this->mock(StripeSyncService::class, function ($m) {
            $m->shouldReceive('sendInvoiceEmail')->once();
        });

        $this->actingAs(User::factory()->create())
            ->post(route('invoices.send-stripe', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('success');
    }

    public function test_void_service_hydrates_current_backend_ids_from_the_locked_row(): void
    {
        // "InvoiceVoidService must participate in the same invoice-first
        // lock/order and return/use current backend identifiers" (R4 security).
        // A push boundary that committed while the caller's model was stale must
        // be visible to the void flow — otherwise the caller would conclude
        // "not Stripe-linked", skip upstream propagation entirely, and leave the
        // freshly-minted hosted page live.
        $invoice = $this->makeInvoice(['status' => InvoiceStatus::Posted]); // no Stripe id yet
        $stale = Invoice::findOrFail($invoice->id);
        Invoice::where('id', $invoice->id)->update([ // a push boundary commits meanwhile
            'stripe_invoice_id' => 'in_hydrate',
            'stripe_invoice_url' => 'https://invoice.stripe.com/i/pay_hydrate',
            'status' => InvoiceStatus::Synced->value,
        ]);

        app(InvoiceVoidService::class)->void($stale);

        // The caller's model now carries the CURRENT identifiers for propagation.
        $this->assertSame('in_hydrate', $stale->stripe_invoice_id);
        $this->assertSame(InvoiceStatus::Void, $stale->status);
        $this->assertSame('0.00', $stale->fresh()->total);
    }

    public function test_send_from_stripe_route_refuses_for_a_void_invoice(): void
    {
        $invoice = $this->makeInvoice([
            'stripe_invoice_id' => 'in_send',
            'stripe_invoice_url' => 'https://invoice.stripe.com/i/pay_send',
            'status' => InvoiceStatus::Void,
            'stripe_sync_error' => 'Stripe invoice in_send is already paid and cannot be voided upstream — reconcile or refund it manually in Stripe.',
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('invoices.send-stripe', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            // R6 (product lane): the flash carries the durable per-cause action
            // — a paid invoice cannot be voided, so the refusal must not hand
            // the operator blanket "void it in Stripe" advice.
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'it was not emailed')
                && str_contains($message, 'reconcile or refund')
                && ! str_contains($message, 'void invoice in_send in Stripe'));
    }

    // ── R6: the REMAINING error-writers join the locked/status-aware protocol ──
    //
    // R5 left three provenance writers acting on stale state: the
    // post-boundary email-failure catch, the create/finalize failure catch,
    // and compensation's unconditional clear. Each could replace (or erase)
    // the per-cause divergence truth a concurrent Void propagation recorded —
    // the operator's only durable instruction for money already collected
    // upstream. R6 routes them through the same locked/column-scoped
    // discipline as recordPushResult, and recordPushResult itself refuses to
    // re-point the row at a second concurrent push's duplicate invoice.

    public function test_send_failure_after_a_confirmed_concurrent_void_keeps_convergence_clean(): void
    {
        // Push & Email commits the result boundary; a staff Void then FULLY
        // CONVERGES during the send window (upstream void confirmed for the
        // recorded id, error cleared); the in-flight email subsequently fails
        // (Stripe refuses to email a voided invoice). The late email-failure
        // settlement must re-read the LOCKED current row: on Void it writes
        // NOTHING — proven convergence stays clean — and the thrown message
        // reports the one-time send outcome without prescribing "Email to
        // Client" (that control is absent on Void, and emailing a voided
        // invoice is never advice).
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x');
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_x']);
        $invoice = $this->makeInvoice(['client_id' => $client->id, 'status' => InvoiceStatus::Posted]);
        $staff = User::factory()->create();

        $stripe = \Mockery::mock(StripeClient::class);
        $this->app->instance(StripeClient::class, $stripe);

        $stripe->shouldReceive('createInvoice')->once()->andReturn(['id' => 'in_lateok']);
        $stripe->shouldReceive('createInvoiceItem')->andReturn([]);
        $stripe->shouldReceive('finalizeInvoice')->once()->with('in_lateok')
            ->andReturn(['id' => 'in_lateok', 'status' => 'open', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/pay_lateok', 'tax' => 4000, 'total' => 54000]);
        $stripe->shouldReceive('sendInvoice')->once()->with('in_lateok')
            ->andReturnUsing(function () use ($invoice, $staff) {
                // Staff Void lands while the email is in flight — the FULL
                // route: local void + upstream propagation, which confirms.
                $this->actingAs($staff)->post(route('invoices.void', $invoice))->assertRedirect();

                throw new StripeClientException('cannot send a voided invoice');
            });
        // The void's own propagation proves upstream void for the recorded id.
        $stripe->shouldReceive('getInvoice')->once()->with('in_lateok')
            ->andReturn(['id' => 'in_lateok', 'status' => 'open']);
        $stripe->shouldReceive('voidInvoice')->once()->with('in_lateok')
            ->andReturn(['id' => 'in_lateok', 'status' => 'void']);

        try {
            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice, true);
            $this->fail('Expected the send failure to surface.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('no email went out', $e->getMessage());
            $this->assertStringNotContainsString('Email to Client', $e->getMessage());
        }

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Void, $fresh->status);
        $this->assertSame('in_lateok', $fresh->stripe_invoice_id);
        $this->assertNull($fresh->stripe_invoice_url);
        // The load-bearing assertion: proven convergence is NOT overwritten
        // with stale "use Email to Client to retry" guidance.
        $this->assertNull($fresh->stripe_sync_error);

        // Operator view: converged — no false "may not reflect this void yet".
        $this->actingAs($staff)
            ->get(route('invoices.show', $invoice))
            ->assertSee('Stripe shows this invoice as voided')
            ->assertDontSee('may not reflect this void yet')
            ->assertDontSee('email could not be sent');
    }

    public function test_send_failure_after_a_failed_void_propagation_preserves_the_paid_divergence(): void
    {
        // The worse ordering (security lane R5 MF1): the concurrent Void's
        // propagation finds the upstream invoice already PAID — it records the
        // load-bearing "reconcile or refund" divergence — and THEN the
        // in-flight send fails. The email-failure settlement must not replace
        // that instruction with the less important email error, and must not
        // recommend emailing the client again.
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x');
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_x']);
        $invoice = $this->makeInvoice(['client_id' => $client->id, 'status' => InvoiceStatus::Posted]);
        $staff = User::factory()->create();

        $stripe = \Mockery::mock(StripeClient::class);
        $this->app->instance(StripeClient::class, $stripe);

        $stripe->shouldReceive('createInvoice')->once()->andReturn(['id' => 'in_latepaid']);
        $stripe->shouldReceive('createInvoiceItem')->andReturn([]);
        $stripe->shouldReceive('finalizeInvoice')->once()->with('in_latepaid')
            ->andReturn(['id' => 'in_latepaid', 'status' => 'open', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/pay_latepaid', 'tax' => 4000, 'total' => 54000]);
        $stripe->shouldReceive('sendInvoice')->once()->with('in_latepaid')
            ->andReturnUsing(function () use ($invoice, $staff) {
                $this->actingAs($staff)->post(route('invoices.void', $invoice))->assertRedirect();

                throw new StripeClientException('response lost');
            });
        // The void propagation finds the upstream invoice PAID: it records
        // reconcile/refund and throws — no upstream void is attempted.
        $stripe->shouldReceive('getInvoice')->once()->with('in_latepaid')
            ->andReturn(['id' => 'in_latepaid', 'status' => 'paid']);
        $stripe->shouldNotReceive('voidInvoice');

        try {
            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice, true);
            $this->fail('Expected the send failure to surface.');
        } catch (StripeClientException $e) {
            $this->assertStringNotContainsString('Email to Client', $e->getMessage());
            // The one-time outcome carries the durable per-cause action along.
            $this->assertStringContainsString('reconcile or refund', $e->getMessage());
        }

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Void, $fresh->status);
        $this->assertStringContainsString('already paid', $fresh->stripe_sync_error);
        $this->assertStringContainsString('reconcile or refund', $fresh->stripe_sync_error);
        $this->assertStringNotContainsString('email could not be sent', $fresh->stripe_sync_error);

        // Operator view keeps alarming with the PAID instruction, not a
        // retry-email suggestion whose control is absent on a Void invoice.
        $this->actingAs($staff)
            ->get(route('invoices.show', $invoice))
            ->assertSee('reconcile or refund')
            ->assertDontSee('email could not be sent');
    }

    public function test_push_email_failure_on_a_live_row_still_records_the_retry_guidance(): void
    {
        // Companion to the two tests above: while the locked current row is
        // STILL LIVE, "pushed but not emailed + retry" is the newest truth and
        // must keep being recorded (the R5 behavior test_push_email_failure_
        // after_boundary_records_pushed_but_not_emailed pins the message; this
        // pins that R6's status-guard did not silence the live path).
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_x']);
        $invoice = $this->makeInvoice(['client_id' => $client->id, 'status' => InvoiceStatus::Posted]);

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('createInvoice')->once()->andReturn(['id' => 'in_liveml']);
        $stripe->shouldReceive('createInvoiceItem')->andReturn([]);
        $stripe->shouldReceive('finalizeInvoice')->once()->with('in_liveml')
            ->andReturn(['id' => 'in_liveml', 'status' => 'open', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/pay_liveml', 'tax' => 4000, 'total' => 54000]);
        $stripe->shouldReceive('sendInvoice')->once()->with('in_liveml')
            ->andThrow(new StripeClientException('smtp exploded'));

        try {
            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice, true);
            $this->fail('Expected the email failure to surface.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('use "Email to Client"', $e->getMessage());
        }

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Synced, $fresh->status);
        $this->assertStringContainsString('email could not be sent', $fresh->stripe_sync_error);
    }

    public function test_push_refuses_when_the_invoice_is_already_linked_to_a_stripe_invoice(): void
    {
        // Locked admission (R6, security lane): pushing an already-linked
        // invoice would CREATE A DUPLICATE upstream invoice — a second payable
        // page for money the row already tracks under the recorded id. Refused
        // before any vendor call, write-free.
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_x']);
        $invoice = $this->makeInvoice([
            'client_id' => $client->id,
            'status' => InvoiceStatus::Synced,
            'stripe_invoice_id' => 'in_linked',
            'stripe_invoice_url' => 'https://invoice.stripe.com/i/pay_linked',
        ]);

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldNotReceive('createInvoice');
        $stripe->shouldNotReceive('sendInvoice');

        try {
            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice, true);
            $this->fail('Expected a refusal for an already-linked invoice.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('already linked', $e->getMessage());
            $this->assertStringContainsString('duplicate', $e->getMessage());
        }

        $fresh = $invoice->fresh();
        $this->assertSame('in_linked', $fresh->stripe_invoice_id);
        $this->assertNull($fresh->stripe_sync_error); // refusal is write-free
        $this->assertSame(InvoiceStatus::Synced, $fresh->status);
    }

    public function test_a_concurrent_second_push_compensates_its_duplicate_and_cannot_hide_the_first_ids_paid_divergence(): void
    {
        // The security lane's R5 repro, end to end: two pushes admitted before
        // either records. Push A's locked boundary records in_first; a staff
        // Void then finds in_first already PAID upstream and records the
        // reconcile/refund divergence. Push B's boundary must see the foreign
        // id, write NOTHING (the row's link and its paid alarm belong to A's
        // chain), void ONLY its own duplicate upstream, and never email —
        // compensation proof for in_dup can never erase in_first's alarm.
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x');
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_x']);
        $invoice = $this->makeInvoice(['client_id' => $client->id, 'status' => InvoiceStatus::Posted]);
        $staff = User::factory()->create();

        $stripe = \Mockery::mock(StripeClient::class);
        $this->app->instance(StripeClient::class, $stripe);

        $stripe->shouldReceive('createInvoice')->once()->andReturn(['id' => 'in_dup']);
        $stripe->shouldReceive('createInvoiceItem')->andReturn([]);
        $stripe->shouldReceive('finalizeInvoice')->once()->with('in_dup')
            ->andReturnUsing(function () use ($invoice, $staff) {
                // Concurrent push A's boundary commits FIRST with its own id —
                // a separate model instance, exactly like another process.
                Invoice::findOrFail($invoice->id)->recordPushResult([
                    'stripe_invoice_id' => 'in_first',
                    'stripe_invoice_url' => 'https://invoice.stripe.com/i/pay_first',
                    'tax' => '40.00',
                    'total' => '540.00',
                    'stripe_synced_at' => now(),
                    'stripe_sync_error' => null,
                ]);

                // Staff Void runs the FULL route; its propagation finds
                // in_first already PAID → durable reconcile/refund divergence.
                $this->actingAs($staff)->post(route('invoices.void', $invoice))->assertRedirect();

                return ['id' => 'in_dup', 'status' => 'open', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/pay_dup', 'tax' => 4000, 'total' => 54000];
            });
        $stripe->shouldReceive('getInvoice')->once()->with('in_first')
            ->andReturn(['id' => 'in_first', 'status' => 'paid']);
        // B compensates ONLY its own duplicate — never A's recorded invoice.
        $stripe->shouldReceive('voidInvoice')->once()->with('in_dup')
            ->andReturn(['id' => 'in_dup', 'status' => 'void']);
        $stripe->shouldNotReceive('sendInvoice');

        try {
            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice, true);
            $this->fail('Expected the duplicate push to abort loudly.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('duplicate', strtolower($e->getMessage()));
            $this->assertStringContainsString('in_dup', $e->getMessage());
            $this->assertStringContainsString('NOT emailed', $e->getMessage());
        }

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Void, $fresh->status);
        $this->assertSame('0.00', $fresh->total);
        // The row's link is NOT re-pointed at the loser's duplicate…
        $this->assertSame('in_first', $fresh->stripe_invoice_id);
        // …and the paid divergence SURVIVES the duplicate's confirmed
        // compensation — the exact erasure R5's security lane demonstrated.
        $this->assertStringContainsString('already paid', $fresh->stripe_sync_error);
        $this->assertStringContainsString('reconcile or refund', $fresh->stripe_sync_error);
        $this->assertStringNotContainsString('in_dup', $fresh->stripe_sync_error);

        $this->actingAs($staff)
            ->get(route('invoices.show', $invoice))
            ->assertSee('reconcile or refund');
    }

    public function test_unconfirmed_duplicate_compensation_appends_its_alarm_without_erasing_the_paid_divergence(): void
    {
        // Same two-push race, but the duplicate's compensating void CANNOT be
        // confirmed — in_dup may still be live and payable with no row pointing
        // at it. That must scream durably, but two alarms are two truths: the
        // duplicate's alarm is APPENDED, never replacing in_first's paid
        // instruction.
        Setting::setEncrypted('stripe_secret_key', 'sk_test_x');
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_x']);
        $invoice = $this->makeInvoice(['client_id' => $client->id, 'status' => InvoiceStatus::Posted]);
        $staff = User::factory()->create();

        $stripe = \Mockery::mock(StripeClient::class);
        $this->app->instance(StripeClient::class, $stripe);

        $stripe->shouldReceive('createInvoice')->once()->andReturn(['id' => 'in_dup2']);
        $stripe->shouldReceive('createInvoiceItem')->andReturn([]);
        $stripe->shouldReceive('finalizeInvoice')->once()->with('in_dup2')
            ->andReturnUsing(function () use ($invoice, $staff) {
                Invoice::findOrFail($invoice->id)->recordPushResult([
                    'stripe_invoice_id' => 'in_first2',
                    'stripe_invoice_url' => 'https://invoice.stripe.com/i/pay_first2',
                    'tax' => '40.00',
                    'total' => '540.00',
                    'stripe_synced_at' => now(),
                    'stripe_sync_error' => null,
                ]);
                $this->actingAs($staff)->post(route('invoices.void', $invoice))->assertRedirect();

                return ['id' => 'in_dup2', 'status' => 'open', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/pay_dup2', 'tax' => 4000, 'total' => 54000];
            });
        $stripe->shouldReceive('getInvoice')->once()->with('in_first2')
            ->andReturn(['id' => 'in_first2', 'status' => 'paid']);
        $stripe->shouldReceive('voidInvoice')->once()->with('in_dup2')
            ->andReturn(['id' => 'in_other', 'status' => 'void']); // NOT proof for in_dup2
        $stripe->shouldNotReceive('sendInvoice');

        try {
            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice, true);
            $this->fail('Expected the unconfirmed duplicate compensation to abort loudly.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('in_dup2', $e->getMessage());
            $this->assertStringContainsString('could NOT be confirmed voided', $e->getMessage());
        }

        $fresh = $invoice->fresh();
        $this->assertSame('in_first2', $fresh->stripe_invoice_id);
        // BOTH truths durable: the paid instruction for the recorded id…
        $this->assertStringContainsString('already paid', $fresh->stripe_sync_error);
        $this->assertStringContainsString('reconcile or refund', $fresh->stripe_sync_error);
        // …and the possibly-live duplicate's own alarm.
        $this->assertStringContainsString('in_dup2', $fresh->stripe_sync_error);
        $this->assertStringContainsString('could NOT be confirmed voided', $fresh->stripe_sync_error);
    }

    public function test_push_vendor_failure_after_a_concurrent_void_does_not_write_over_the_void_rows_provenance(): void
    {
        // The create/finalize catch is an error-writer too (R6): a void that
        // commits while the round-trip is in flight owns the row's provenance.
        // Here the void found nothing to propagate (no id recorded yet) and
        // left the row clean — the failed push must not mint a false alarm on
        // it (nothing payable was created; finalize failed).
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_x']);
        $invoice = $this->makeInvoice(['client_id' => $client->id, 'status' => InvoiceStatus::Posted]);

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('createInvoice')->once()->andReturn(['id' => 'in_boom']);
        $stripe->shouldReceive('createInvoiceItem')->andReturn([]);
        $stripe->shouldReceive('finalizeInvoice')->once()->with('in_boom')
            ->andReturnUsing(function () use ($invoice) {
                app(InvoiceVoidService::class)->void(Invoice::findOrFail($invoice->id));

                throw new StripeClientException('finalize exploded');
            });
        $stripe->shouldNotReceive('sendInvoice');

        try {
            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice, true);
            $this->fail('Expected the finalize failure to surface.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('finalize exploded', $e->getMessage());
        }

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Void, $fresh->status);
        $this->assertNull($fresh->stripe_sync_error);
    }

    public function test_push_vendor_failure_on_a_live_row_still_records_the_error(): void
    {
        // Baseline for the guarded catch: on a still-live row the failure IS
        // the newest truth and must keep being recorded durably.
        $client = Client::factory()->create(['stripe_customer_id' => 'cus_x']);
        $invoice = $this->makeInvoice(['client_id' => $client->id, 'status' => InvoiceStatus::Posted]);

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('createInvoice')->once()->andThrow(new StripeClientException('create exploded'));

        try {
            (new StripeSyncService($stripe))->pushInvoiceToStripe($invoice);
            $this->fail('Expected the create failure to surface.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('create exploded', $e->getMessage());
        }

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Posted, $fresh->status);
        $this->assertStringContainsString('create exploded', $fresh->stripe_sync_error);
    }

    public function test_status_pull_vendor_failure_after_a_concurrent_void_preserves_the_void_paths_provenance(): void
    {
        // Same class on the pull path: the GET fails only after a concurrent
        // void committed and its propagation recorded the per-cause truth. The
        // pull's catch must not replace it with a transport complaint.
        $invoice = $this->makeInvoice([
            'status' => InvoiceStatus::Synced,
            'stripe_invoice_id' => 'in_pullfail',
        ]);

        $stripe = \Mockery::mock(StripeClient::class);
        $stripe->shouldReceive('getInvoice')->once()->with('in_pullfail')
            ->andReturnUsing(function () use ($invoice) {
                Invoice::where('id', $invoice->id)->update([
                    'status' => InvoiceStatus::Void->value,
                    'stripe_sync_error' => 'already paid — reconcile or refund',
                ]);

                throw new StripeClientException('gateway timeout');
            });

        try {
            (new StripeSyncService($stripe))->syncInvoiceStatusFromStripe($invoice);
            $this->fail('Expected the pull failure to surface.');
        } catch (StripeClientException $e) {
            $this->assertStringContainsString('gateway timeout', $e->getMessage());
        }

        $this->assertSame('already paid — reconcile or refund', $invoice->fresh()->stripe_sync_error);
    }

    // ── R6: the Void banner renders each linked provider independently ──

    public function test_void_banner_does_not_claim_qbo_convergence_when_its_void_failed(): void
    {
        // Product lane R5 MF3: dual-linked invoice, QBO void FAILED while
        // Stripe confirmed. The banner must not say "QuickBooks shows this
        // invoice as $0.00" merely because qbo_invoice_id exists, and must not
        // drop the proven Stripe state because QBO rendered first.
        $invoice = $this->makeInvoice([]);
        app(InvoiceVoidService::class)->void($invoice);
        Invoice::where('id', $invoice->id)->update([
            'qbo_invoice_id' => '9001',
            'qbo_sync_error' => 'QBO void failed: connection reset — void invoice #9001 manually in QuickBooks.',
            'stripe_invoice_id' => 'in_dual',
            'stripe_sync_error' => null,
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('invoices.show', $invoice))
            ->assertDontSee('QuickBooks shows this invoice as $0.00')
            ->assertSee('QuickBooks may not reflect this void yet')
            ->assertSee('Stripe shows this invoice as voided');
    }

    public function test_void_banner_states_each_providers_own_truth_when_stripe_diverged(): void
    {
        // The mirror case: QBO converged, Stripe diverged. Both providers'
        // states are rendered, each conditioned on its OWN sync error.
        $invoice = $this->makeInvoice([]);
        app(InvoiceVoidService::class)->void($invoice);
        Invoice::where('id', $invoice->id)->update([
            'qbo_invoice_id' => '9002',
            'qbo_sync_error' => null,
            'stripe_invoice_id' => 'in_dual2',
            'stripe_sync_error' => 'Stripe invoice in_dual2 is already paid and cannot be voided upstream — reconcile or refund it manually in Stripe.',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('invoices.show', $invoice))
            ->assertSee('QuickBooks shows this invoice as $0.00')
            ->assertSee('Stripe may not reflect this void yet')
            ->assertDontSee('Stripe shows this invoice as voided');
    }

    private function portalPerson(Client $client): Person
    {
        return Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Portal', 'last_name' => 'User',
            'email' => 'r4-'.uniqid().'@example.test',
            'is_active' => true, 'portal_enabled' => true, 'company_wide_access' => true,
        ]);
    }
}
