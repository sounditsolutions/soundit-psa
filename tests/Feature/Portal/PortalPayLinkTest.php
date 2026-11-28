<?php

namespace Tests\Feature\Portal;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceStatusChangeSource;
use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Person;
use App\Models\Setting;
use App\Support\BenjiPaysConfig;
use App\Support\InvoiceStatusChangeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * One pay-link rule (App\Support\PortalPayLink) for the prepaid and shop
 * order confirmations (page and poll) and the portal invoice show and index
 * pages; the dashboard's Unpaid Invoices list is not covered. BenjiPays, then
 * the Stripe page, then the QuickBooks billing-portal page; nothing for a
 * non-payable invoice, and on the confirmations nothing for a partially paid
 * one. Also CSRF on every POST render and the escaped poll payment URL.
 *
 * Also the confirmations' "pay link is on its way" copy and its phone clause.
 *
 * Every outbound request is faked and strays are refused. Synthetic data only.
 */
class PortalPayLinkTest extends TestCase
{
    use RefreshDatabase;

    private const STRIPE_URL = 'https://invoice.stripe.com/i/acct_synthetic/test_paylink';

    private const BILLING_URL = 'https://billing.example.test';

    private const PHONE = '(555) 010-0100';

    private const PENDING_NO_PHONE = "Not there after a few minutes? Reply to any email from us and we'll send it to you.";

    private const PENDING_WITH_PHONE = "Not there after a few minutes? Reply to any email from us or call us at (555) 010-0100, and we'll send it to you.";

    private static int $seq = 0;

    private Client $client;

    private Person $person;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        Setting::setValue('portal_enabled', '1');
        Setting::setValue('portal_shop_enabled', '1');

        $this->client = Client::create(['name' => 'Example Dental Co.', 'qbo_customer_id' => 'qbo-cust-1']);
        $this->person = Person::create([
            'client_id' => $this->client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Alex',
            'last_name' => 'Example',
            'email' => 'alex-paylink@example.test',
            'is_active' => true,
            'portal_enabled' => true,
            'company_wide_access' => true,
        ]);
    }

    private function invoice(array $attrs = []): Invoice
    {
        return Invoice::create(array_merge([
            'client_id' => $this->client->id,
            'invoice_number' => 'INV-PL-'.str_pad((string) ++self::$seq, 4, '0', STR_PAD_LEFT),
            'invoice_date' => now()->subDay(),
            'due_date' => now()->addDays(20),
            'subtotal' => '250.00',
            'tax' => '0.00',
            'total' => '250.00',
            'total_cost' => '100.00',
            'margin' => '150.00',
            'status' => InvoiceStatus::Posted,
            'notes' => 'Prepaid time purchase via client portal by Alex Example',
        ], $attrs));
    }

    private function shopInvoice(array $attrs = []): Invoice
    {
        return $this->invoice(array_merge(['notes' => 'Product order via client portal by Alex Example'], $attrs));
    }

    private function qboPayUrl(Invoice $invoice): string
    {
        return self::BILLING_URL.'/portal/pay/?invoiceNumber='.urlencode($invoice->invoice_number).'&transactionAmount=250.00';
    }

    private function enableBenjiPays(): void
    {
        Setting::setEncrypted('benjipays_api_key', 'synthetic-bp-key-never-real');
        Setting::setValue(BenjiPaysConfig::PAY_ONLINE_SETTING, '1');
    }

    /** QuickBooks reports 100.00 of 250.00 still owed (the #1173 pull path). */
    private function partial(Invoice $invoice): Invoice
    {
        $invoice->update(['status' => InvoiceStatus::Paid]);
        $invoice->statusChangeContext = new InvoiceStatusChangeContext(
            source: InvoiceStatusChangeSource::QboPull,
            reason: 'QuickBooks reports a balance.',
            qboBalance: 100.00,
        );
        $invoice->update(['status' => InvoiceStatus::Posted]);
        $this->assertNotNull($invoice->fresh()->qboPartialBalanceLog(), 'fixture is partially paid');

        return $invoice->fresh();
    }

    private function page(string $route, Invoice $invoice)
    {
        return $this->actingAs($this->person, 'portal')->get(route($route, $invoice));
    }

    private function index()
    {
        return $this->actingAs($this->person, 'portal')->get(route('portal.invoices.index'));
    }

    private function pageRoutes(): array
    {
        return ['portal.invoices.show', 'portal.prepaid.confirmation'];
    }

    // ── Pay-link source, the same on every surface ──

    public function test_a_qbo_billed_invoice_offers_the_billing_portal_link_on_the_invoice_pages(): void
    {
        Setting::setValue('portal_billing_url', self::BILLING_URL);
        $invoice = $this->invoice(['qbo_invoice_id' => '4242']);
        $url = e($this->qboPayUrl($invoice));

        $this->page('portal.invoices.show', $invoice)->assertOk()
            ->assertSee('Pay Online')->assertSee($url, false);
        $this->index()->assertOk()
            ->assertSee('Pay Online')->assertSee($url, false);
    }

    public function test_the_confirmations_and_polls_offer_the_same_billing_portal_link(): void
    {
        Setting::setValue('portal_billing_url', self::BILLING_URL);
        $prepaid = $this->invoice(['qbo_invoice_id' => '4243']);
        $shop = $this->shopInvoice(['qbo_invoice_id' => '4244']);

        $this->page('portal.prepaid.confirmation', $prepaid)->assertOk()
            ->assertSee('Pay Now')->assertSee(e($this->qboPayUrl($prepaid)), false);
        $this->page('portal.shop.confirmation', $shop)->assertOk()
            ->assertSee('Pay Now')->assertSee(e($this->qboPayUrl($shop)), false);

        $this->actingAs($this->person, 'portal')->getJson(route('portal.prepaid.payment-status', $prepaid))
            ->assertJsonPath('payment_url', $this->qboPayUrl($prepaid))
            ->assertJsonPath('payment_method', 'get');
        $this->actingAs($this->person, 'portal')->getJson(route('portal.shop.payment-status', $shop))
            ->assertJsonPath('payment_url', $this->qboPayUrl($shop))
            ->assertJsonPath('payment_method', 'get');
    }

    public function test_stripe_wins_over_the_billing_portal_on_every_surface(): void
    {
        Setting::setValue('portal_billing_url', self::BILLING_URL);
        $invoice = $this->invoice(['qbo_invoice_id' => '4245', 'stripe_invoice_url' => self::STRIPE_URL]);
        $shop = $this->shopInvoice(['qbo_invoice_id' => '4246', 'stripe_invoice_url' => self::STRIPE_URL]);

        foreach ($this->pageRoutes() as $route) {
            $this->page($route, $invoice)->assertOk()
                ->assertSee(self::STRIPE_URL, false)->assertDontSee('/portal/pay/', false);
        }
        $this->page('portal.shop.confirmation', $shop)->assertOk()
            ->assertSee(self::STRIPE_URL, false)->assertDontSee('/portal/pay/', false);
        $this->index()->assertSee(self::STRIPE_URL, false)->assertDontSee('/portal/pay/', false);
        $this->actingAs($this->person, 'portal')->getJson(route('portal.prepaid.payment-status', $invoice))
            ->assertJsonPath('payment_url', self::STRIPE_URL);
    }

    public function test_benjipays_wins_and_stays_a_post_on_every_surface(): void
    {
        $this->enableBenjiPays();
        Setting::setValue('portal_billing_url', self::BILLING_URL);
        $invoice = $this->invoice(['qbo_invoice_id' => '4247', 'stripe_invoice_url' => self::STRIPE_URL]);
        $shop = $this->shopInvoice(['qbo_invoice_id' => '4248', 'stripe_invoice_url' => self::STRIPE_URL]);
        $action = 'method="POST" action="'.route('portal.invoices.pay-online', $invoice).'"';

        foreach ($this->pageRoutes() as $route) {
            $this->page($route, $invoice)->assertOk()
                ->assertSee($action, false)
                ->assertDontSee(self::STRIPE_URL, false)
                ->assertDontSee('/portal/pay/', false);
        }
        $this->page('portal.shop.confirmation', $shop)->assertOk()
            ->assertSee('method="POST" action="'.route('portal.invoices.pay-online', $shop).'"', false)
            ->assertDontSee(self::STRIPE_URL, false);
        $this->index()->assertSee($action, false)->assertDontSee(self::STRIPE_URL, false);

        $this->actingAs($this->person, 'portal')->getJson(route('portal.prepaid.payment-status', $invoice))
            ->assertJsonPath('payment_url', route('portal.invoices.pay-online', $invoice))
            ->assertJsonPath('payment_method', 'post');
        $this->actingAs($this->person, 'portal')->getJson(route('portal.shop.payment-status', $shop))
            ->assertJsonPath('payment_method', 'post');
        Http::assertNothingSent();
    }

    public function test_no_source_offers_no_pay_link_anywhere(): void
    {
        // QBO id but no billing URL configured, no Stripe page, BenjiPays off.
        $invoice = $this->invoice(['qbo_invoice_id' => '4249']);

        $this->page('portal.invoices.show', $invoice)->assertOk()->assertDontSee('Pay Online');
        $this->index()->assertOk()->assertDontSee('Pay Online');
        $this->actingAs($this->person, 'portal')->getJson(route('portal.prepaid.payment-status', $invoice))
            ->assertJsonPath('payment_url', null);
    }

    public function test_a_paid_or_void_invoice_offers_no_pay_link_from_any_source(): void
    {
        $this->enableBenjiPays();
        Setting::setValue('portal_billing_url', self::BILLING_URL);
        $paid = $this->invoice(['qbo_invoice_id' => '4250', 'stripe_invoice_url' => self::STRIPE_URL, 'status' => InvoiceStatus::Paid]);

        $this->page('portal.invoices.show', $paid)->assertOk()
            ->assertDontSee('Pay Online')->assertDontSee(self::STRIPE_URL, false)->assertDontSee('/portal/pay/', false);
        $this->index()->assertDontSee('Pay Online');

        $void = $this->invoice(['qbo_invoice_id' => '4251', 'stripe_invoice_url' => self::STRIPE_URL, 'status' => InvoiceStatus::Void]);
        $this->page('portal.prepaid.confirmation', $void)->assertOk()
            ->assertDontSee('Pay Now')->assertDontSee('/portal/pay/', false)->assertSee('not payable');
        $this->actingAs($this->person, 'portal')->getJson(route('portal.prepaid.payment-status', $void))
            ->assertJsonPath('payment_url', null);
    }

    // ── #1173: the full-amount caveat and the BenjiPays guard are kept ──

    public function test_a_partially_paid_qbo_invoice_is_not_offered_the_full_total_billing_link(): void
    {
        Setting::setValue('portal_billing_url', self::BILLING_URL);
        $invoice = $this->partial($this->invoice(['qbo_invoice_id' => '4252']));

        $this->page('portal.invoices.show', $invoice)->assertOk()
            ->assertSee('Partially paid')
            ->assertDontSee('Pay Online')
            ->assertDontSee('/portal/pay/', false);
        $this->index()->assertDontSee('/portal/pay/', false);
        $this->page('portal.prepaid.confirmation', $invoice)->assertOk()
            ->assertDontSee('/portal/pay/', false)
            ->assertSee('Partially paid')
            ->assertDontSee('Your pay link is on its way');
        $this->actingAs($this->person, 'portal')->getJson(route('portal.prepaid.payment-status', $invoice))
            ->assertJsonPath('payment_url', null);
    }

    public function test_a_partially_paid_stripe_invoice_keeps_the_full_amount_caveat_beside_its_link(): void
    {
        $invoice = $this->partial($this->invoice(['qbo_invoice_id' => '4253', 'stripe_invoice_url' => self::STRIPE_URL]));

        $this->page('portal.invoices.show', $invoice)->assertOk()
            ->assertSee(self::STRIPE_URL, false)
            ->assertSee('Paying online will charge the full $250.00');
    }

    public function test_a_partially_paid_benjipays_invoice_posts_and_drops_the_caveat(): void
    {
        $this->enableBenjiPays();
        $invoice = $this->partial($this->invoice(['qbo_invoice_id' => '4254', 'stripe_invoice_url' => self::STRIPE_URL]));

        $this->page('portal.invoices.show', $invoice)->assertOk()
            ->assertSee('method="POST" action="'.route('portal.invoices.pay-online', $invoice).'"', false)
            ->assertDontSee('Paying online will charge the full');
    }

    // ── #1173 on the confirmations: no Pay Now at the full total ──

    public function test_a_partially_paid_stripe_invoice_offers_no_pay_now_on_the_confirmations_or_polls(): void
    {
        $prepaid = $this->partial($this->invoice(['qbo_invoice_id' => '4255', 'stripe_invoice_url' => self::STRIPE_URL]));
        $shop = $this->partial($this->shopInvoice(['qbo_invoice_id' => '4256', 'stripe_invoice_url' => self::STRIPE_URL]));

        foreach (['portal.prepaid.confirmation' => $prepaid, 'portal.shop.confirmation' => $shop] as $route => $invoice) {
            $this->page($route, $invoice)->assertOk()
                ->assertDontSee('Pay Now')
                ->assertDontSee(self::STRIPE_URL, false)
                ->assertDontSee('Your pay link is on its way')
                ->assertSee('Partially paid — $100.00');
        }
        foreach (['portal.prepaid.payment-status' => $prepaid, 'portal.shop.payment-status' => $shop] as $route => $invoice) {
            $this->actingAs($this->person, 'portal')->getJson(route($route, $invoice))
                ->assertJsonPath('payment_url', null);
        }

        // The invoice page keeps its link with the full-amount caveat beside it.
        $this->page('portal.invoices.show', $prepaid)->assertOk()
            ->assertSee(self::STRIPE_URL, false)
            ->assertSee('Paying online will charge the full $250.00');
    }

    public function test_a_partially_paid_benjipays_invoice_offers_no_pay_now_on_the_confirmations_or_polls(): void
    {
        $this->enableBenjiPays();
        $prepaid = $this->partial($this->invoice(['qbo_invoice_id' => '4257']));
        $shop = $this->partial($this->shopInvoice(['qbo_invoice_id' => '4258']));

        foreach (['portal.prepaid.confirmation' => $prepaid, 'portal.shop.confirmation' => $shop] as $route => $invoice) {
            $this->page($route, $invoice)->assertOk()
                ->assertDontSee('Pay Now')
                ->assertDontSee(route('portal.invoices.pay-online', $invoice), false)
                ->assertDontSee('Your pay link is on its way')
                ->assertSee('Partially paid — $100.00');
        }
        foreach (['portal.prepaid.payment-status' => $prepaid, 'portal.shop.payment-status' => $shop] as $route => $invoice) {
            $this->actingAs($this->person, 'portal')->getJson(route($route, $invoice))
                ->assertJsonPath('payment_url', null);
        }

        // The invoice page keeps the BenjiPays POST.
        $this->page('portal.invoices.show', $prepaid)->assertOk()
            ->assertSee('method="POST" action="'.route('portal.invoices.pay-online', $prepaid).'"', false);
        Http::assertNothingSent();
    }

    public function test_a_partially_paid_qbo_invoice_with_no_pay_source_shows_the_balance_note_not_pay_pending(): void
    {
        // QBO id, no billing URL, no Stripe page, BenjiPays off; the client has a
        // QBO customer id, so an unpaid invoice here would be "on its way".
        $prepaid = $this->partial($this->invoice(['qbo_invoice_id' => '4262']));
        $shop = $this->partial($this->shopInvoice(['qbo_invoice_id' => '4263']));

        foreach (['portal.prepaid.confirmation' => $prepaid, 'portal.shop.confirmation' => $shop] as $route => $invoice) {
            $this->page($route, $invoice)->assertOk()
                ->assertDontSee('Your pay link is on its way')
                ->assertDontSee('payment-status', false)   // no poll on the page
                ->assertDontSee('Pay Now')
                ->assertSee('Partially paid — $100.00');
        }
        foreach (['portal.prepaid.payment-status' => $prepaid, 'portal.shop.payment-status' => $shop] as $route => $invoice) {
            $this->actingAs($this->person, 'portal')->getJson(route($route, $invoice))
                ->assertJsonPath('payment_url', null);
        }
    }

    // ── CSRF and escaping on the POST and link renders ──

    /** The rendered form opens with the page's own CSRF token as its first field. */
    private function assertPostFormCarriesToken(string $html, string $action): void
    {
        $this->assertSame(1, preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $meta), 'page has a csrf meta token');
        $this->assertMatchesRegularExpression(
            '#<form method="POST" action="'.preg_quote($action, '#').'" class="d-inline">\s*<input type="hidden" name="_token" value="'.preg_quote($meta[1], '#').'"#',
            $html,
        );
    }

    public function test_the_pay_now_post_on_the_confirmations_carries_the_csrf_token(): void
    {
        $this->enableBenjiPays();
        $prepaid = $this->invoice(['qbo_invoice_id' => '4259']);
        $shop = $this->shopInvoice(['qbo_invoice_id' => '4260']);

        foreach (['portal.prepaid.confirmation' => $prepaid, 'portal.shop.confirmation' => $shop] as $route => $invoice) {
            $html = $this->page($route, $invoice)->assertOk()->assertSee('Pay Now')->getContent();
            $this->assertPostFormCarriesToken($html, route('portal.invoices.pay-online', $invoice));
        }
        Http::assertNothingSent();
    }

    public function test_the_pay_online_post_on_the_invoice_page_carries_the_csrf_token(): void
    {
        $this->enableBenjiPays();
        $invoice = $this->invoice(['qbo_invoice_id' => '4261']);

        $html = $this->page('portal.invoices.show', $invoice)->assertOk()->assertSee('Pay Online')->getContent();
        $this->assertPostFormCarriesToken($html, route('portal.invoices.pay-online', $invoice));
        Http::assertNothingSent();
    }

    public function test_both_poll_scripts_post_with_the_csrf_token_and_escape_the_payment_url(): void
    {
        // No link yet and a billing backend on the client: the poll is on the page.
        foreach (['portal.prepaid.confirmation' => $this->invoice(), 'portal.shop.confirmation' => $this->shopInvoice()] as $route => $invoice) {
            $this->page($route, $invoice)->assertOk()
                ->assertSee('payment-status', false)
                ->assertSeeInOrder([
                    "if (data.payment_method === 'post') {",
                    "const token = document.querySelector('meta[name=\"csrf-token\"]').getAttribute('content');",
                    "return '<form method=\"POST\" action=\"' + esc(data.payment_url) + '\" class=\"d-inline\">' +",
                    "'<input type=\"hidden\" name=\"_token\" value=\"' + esc(token) + '\">' +",
                    "return '<a href=\"' + esc(data.payment_url) + '\" target=\"_blank\"",
                ], false);
        }
    }

    public function test_both_poll_scripts_define_esc_as_the_full_html_attribute_escape(): void
    {
        $body = "return String(s).replace(/&/g, '&amp;').replace(/\"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');";

        foreach (['portal.prepaid.confirmation' => $this->invoice(), 'portal.shop.confirmation' => $this->shopInvoice()] as $route => $invoice) {
            $html = $this->page($route, $invoice)->assertOk()->assertSee('payment-status', false)->getContent();
            $this->assertSame(1, preg_match_all('/function esc\(s\) \{\s*([^}]*?)\s*\}/', $html, $m), $route.': one esc() definition');
            $this->assertSame($body, $m[1][0], $route.': esc() body');
        }
    }

    // ── Pay-pending copy and the phone clause ──

    public function test_the_pending_state_reads_the_mocked_copy_without_a_phone(): void
    {
        foreach ([$this->invoice(), $this->shopInvoice()] as $i => $invoice) {
            $route = $i === 0 ? 'portal.prepaid.confirmation' : 'portal.shop.confirmation';
            $this->page($route, $invoice)->assertOk()
                ->assertSee('Your pay link is on its way')
                ->assertSee('It usually appears right here within a minute. It will also be on this invoice under', false)
                ->assertSee(self::PENDING_NO_PHONE, false)
                ->assertDontSee('call us at')
                ->assertDontSee('Preparing payment link')
                ->assertDontSee('payment link shortly');
        }
    }

    public function test_the_pending_state_offers_the_phone_when_it_is_set(): void
    {
        Setting::setValue('portal_support_phone', self::PHONE);

        foreach ([$this->invoice(), $this->shopInvoice()] as $i => $invoice) {
            $route = $i === 0 ? 'portal.prepaid.confirmation' : 'portal.shop.confirmation';
            $this->page($route, $invoice)->assertOk()
                ->assertSee(self::PENDING_WITH_PHONE, false)
                ->assertDontSee(self::PENDING_NO_PHONE, false);
        }
    }

    public function test_a_client_with_no_billing_backend_sees_the_same_pending_copy_and_no_poll(): void
    {
        $this->client->update(['qbo_customer_id' => null]);
        $invoice = $this->invoice();

        $this->page('portal.prepaid.confirmation', $invoice)->assertOk()
            ->assertSee(self::PENDING_NO_PHONE, false)
            ->assertDontSee('payment-status', false)
            ->assertDontSee('payment link shortly');
    }

    public function test_the_poll_script_never_writes_the_old_wording(): void
    {
        foreach ([$this->invoice(), $this->shopInvoice()] as $i => $invoice) {
            $route = $i === 0 ? 'portal.prepaid.confirmation' : 'portal.shop.confirmation';
            $this->page($route, $invoice)->assertOk()
                ->assertSee('payment-status', false)        // the poll is on the page
                ->assertDontSee('payment link shortly')
                ->assertDontSee('Preparing payment link');
        }
    }
}
