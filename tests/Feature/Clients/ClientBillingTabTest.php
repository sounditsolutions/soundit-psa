<?php

namespace Tests\Feature\Clients;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceStatusChangeSource;
use App\Enums\PrepayTransactionSource;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceStatusChangeLog;
use App\Models\PrepayTransaction;
use App\Models\RecurringInvoiceProfile;
use App\Models\RecurringInvoiceProfileLine;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The read-only client Billing tab (card iNjqGETC), GET /clients/{client}/billing.
 *
 * All data is synthetic. BenjiPays is faked at the HTTP layer with
 * Http::preventStrayRequests(); fixtures follow the documented item schemas
 * already used by tests/Feature/Mcp/BenjiPaysReadToolsTest.php.
 */
class ClientBillingTabTest extends TestCase
{
    use RefreshDatabase;

    private const CUST = 'QBO-CUST-SYN-1';

    /** Billing route query ceiling at 60 open invoices; see test_query_count_does_not_grow_with_the_number_of_open_invoices. */
    private const QUERY_CEILING = 50;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        // Fixed clock: "today" is 2026-10-22 12:00 UTC.
        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00:00', 'UTC'));
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function client(array $attrs = []): Client
    {
        return Client::factory()->create(['is_active' => true] + $attrs);
    }

    private function invoice(Client $client, InvoiceStatus $status, string $total, array $attrs = []): Invoice
    {
        static $n = 1000;
        $n++;

        return Invoice::create(array_merge([
            'client_id' => $client->id, 'invoice_number' => 'INV-SYN-'.$n,
            'invoice_date' => '2026-10-01', 'due_date' => '2026-10-31',
            'subtotal' => $total, 'tax' => '0.00', 'total' => $total,
            'total_cost' => '0.00', 'margin' => $total, 'status' => $status,
        ], $attrs));
    }

    /** A QBO-pull status-change row carrying a partial balance, stamped $at (UTC). */
    private function qboLog(Invoice $invoice, string $balance, string $at): InvoiceStatusChangeLog
    {
        $log = InvoiceStatusChangeLog::create([
            'invoice_id' => $invoice->id, 'previous_status' => 'posted', 'new_status' => $invoice->status->value,
            'source' => InvoiceStatusChangeSource::QboPull, 'qbo_balance' => $balance,
        ]);
        $log->forceFill(['created_at' => Carbon::parse($at, 'UTC')])->save();

        return $log;
    }

    private function billing(Client $client): TestResponse
    {
        return $this->actingAs($this->user)->get(route('clients.billing', $client));
    }

    /** The rendered Billing tab body only (between its own markers). */
    private function tab(TestResponse $response): string
    {
        $html = (string) $response->getContent();
        $start = strpos($html, 'data-billing-tab>');
        $end = strpos($html, 'data-billing-tab-end');
        $this->assertNotFalse($start, 'Billing tab not rendered');
        $this->assertNotFalse($end, 'Billing tab end marker not rendered');

        return substr($html, $start, $end - $start);
    }

    private function figure(string $tab, string $name): string
    {
        $this->assertSame(1, preg_match('/data-figure="'.$name.'">([^<]*)</', $tab, $m), "figure $name missing");

        return trim($m[1]);
    }

    private function configureBenjiPays(): void
    {
        Setting::setEncrypted('benjipays_api_key', 'synthetic-bp-key-not-real');
    }

    /** @return array<string, mixed> */
    private static function page(array $items): array
    {
        return ['data' => $items, 'pagination' => [
            'total' => count($items), 'limit' => 25, 'offset' => 0,
            'hasMore' => false, 'nextOffset' => null, 'prevOffset' => null,
        ]];
    }

    /** @return array<string, mixed> PaymentMethodListItem (synthetic). */
    private static function pm(array $o = []): array
    {
        return array_merge([
            'id' => 'pm-syn-1', 'customerId' => self::CUST, 'paymentType' => 'cc', 'cardBrand' => 'Visa',
            'maskedPan' => '****4242', 'expiryMonth' => '08', 'expiryYear' => '2028',
            'autoPayEnabled' => true, 'autoPayPriority' => 1, 'benjiSurchargeEnabled' => false, 'declineCount' => 0,
        ], $o);
    }

    /** @return array<string, mixed> TransactionListItem (synthetic). */
    private static function txn(array $o = []): array
    {
        return array_merge([
            'id' => 'txn-syn-1', 'customerId' => self::CUST, 'type' => 'payment', 'status' => 'approved',
            'approved' => true, 'amount' => 1225.0, 'currency' => 'USD', 'paymentType' => 'cc',
            'details' => ['maskedPan' => '****4242', 'account' => null],
            'transactionDate' => '2026-10-21T17:04:11.000Z', 'invoiceNumber' => 'INV-SYN-PAID', 'invoiceId' => '9001',
            'settlementDate' => null, 'settlementEligible' => true, 'settlementStatus' => 'pending',
        ], $o);
    }

    private function fakeBenjiPaysOk(): void
    {
        Http::fake([
            'https://api.benjipays.com/v2/payment-methods*' => Http::response(self::page([
                self::pm(), self::pm(['id' => 'pm-syn-2', 'paymentType' => 'bank', 'cardBrand' => null, 'maskedPan' => 'XXXX6789', 'autoPayEnabled' => false]),
            ])),
            'https://api.benjipays.com/v2/transactions*' => Http::response(self::page([self::txn()])),
        ]);
    }

    // ── Route and authorization ─────────────────────────────────────────────

    public function test_staff_user_gets_200_and_the_billing_tab_is_active(): void
    {
        $client = $this->client();

        $response = $this->billing($client);

        $response->assertOk();
        $response->assertSee('<button class="nav-link active" type="button">Billing</button>', false);
        $this->tab($response);
    }

    public function test_route_sits_in_the_same_middleware_as_invoices_index(): void
    {
        $billing = app('router')->getRoutes()->getByName('clients.billing');
        $invoices = app('router')->getRoutes()->getByName('invoices.index');

        $this->assertNotNull($billing);
        $this->assertSame('GET', $billing->methods()[0]);
        $this->assertSame($invoices->gatherMiddleware(), $billing->gatherMiddleware());
    }

    public function test_a_user_who_cannot_see_invoices_gets_the_same_outcome_on_both_routes(): void
    {
        $client = $this->client();

        // Only authentication gates invoices.index; a guest is the user who cannot see it.
        $invoices = $this->get(route('invoices.index'));
        $billing = $this->get(route('clients.billing', $client));

        $invoices->assertRedirect(route('login'));
        $billing->assertRedirect(route('login'));
        $this->assertSame($invoices->getStatusCode(), $billing->getStatusCode());
    }

    public function test_other_tabs_link_to_billing_and_do_not_build_it(): void
    {
        $client = $this->client();
        $this->invoice($client, InvoiceStatus::Posted, '100.00');

        $response = $this->actingAs($this->user)->get(route('clients.show', $client));

        $response->assertOk();
        $response->assertSee('href="'.route('clients.billing', $client).'"', false);
        $response->assertDontSee('data-billing-tab', false);
    }

    // ── Totals ──────────────────────────────────────────────────────────────

    public function test_outstanding_is_partial_plus_full_and_excludes_void_draft_and_deleted(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22 12:00:00', 'UTC'));
        $client = $this->client();

        $partial = $this->invoice($client, InvoiceStatus::Synced, '2450.00');
        $this->qboLog($partial, '1225.00', '2026-10-21 06:00:00');
        $this->invoice($client, InvoiceStatus::Posted, '1907.50');
        $void = $this->invoice($client, InvoiceStatus::Posted, '95.00');
        $void->update(['status' => InvoiceStatus::Void]);
        $this->invoice($client, InvoiceStatus::Draft, '310.00');
        $this->invoice($client, InvoiceStatus::Posted, '5000.00')->delete();
        $this->invoice($client, InvoiceStatus::Paid, '700.00');

        $tab = $this->tab($this->billing($client)->assertOk());

        $this->assertSame('$3,132.50', $this->figure($tab, 'outstanding'));
        $this->assertStringContainsString('2 open invoices; includes 1 part-paid invoice (QBO as of Oct 21, 06:00)', $tab);
        $this->assertStringContainsString('QBO, Oct 21', $tab);
        $this->assertStringContainsString('Part-paid', $tab);
        // The soft-deleted invoice is not even listed.
        $this->assertStringNotContainsString('5,000.00', $tab);
        // All 5 are listed (2 open, then draft, void, paid), so the header names all 5, not "25".
        $this->assertStringContainsString('Open first, then newest · all 5', $tab);
        $this->assertStringNotContainsString('newest 25', $tab);
    }

    public function test_as_of_date_is_the_latest_partial_and_is_labelled_in_the_app_timezone(): void
    {
        Setting::setValue('app_timezone', 'America/Los_Angeles');
        $client = $this->client();
        $a = $this->invoice($client, InvoiceStatus::Posted, '100.00');
        $this->qboLog($a, '40.00', '2026-10-20 10:00:00');
        $b = $this->invoice($client, InvoiceStatus::Synced, '200.00');
        // 03:30 UTC on Oct 21 is 20:30 on Oct 20 in Los Angeles.
        $this->qboLog($b, '50.00', '2026-10-21 03:30:00');

        $tab = $this->tab($this->billing($client)->assertOk());

        $this->assertSame('$90.00', $this->figure($tab, 'outstanding'));
        $this->assertStringContainsString('includes 2 part-paid invoices (QBO as of Oct 20, 20:30)', $tab);
    }

    public function test_a_qbo_balance_not_below_the_total_is_not_a_partial(): void
    {
        $client = $this->client();
        $inv = $this->invoice($client, InvoiceStatus::Posted, '100.00');
        $this->qboLog($inv, '100.00', '2026-10-21 06:00:00');

        $tab = $this->tab($this->billing($client)->assertOk());

        $this->assertSame('$100.00', $this->figure($tab, 'outstanding'));
        $this->assertStringNotContainsString('part-paid', $tab);
    }

    public function test_outstanding_sums_without_float_drift(): void
    {
        $client = $this->client();
        // 0.10 added 30 times is 2.9999999999999996 in floats; bcadd gives 3.00.
        for ($i = 0; $i < 30; $i++) {
            $this->invoice($client, InvoiceStatus::Posted, '0.10', ['due_date' => '2026-12-31']);
        }
        $inv = $this->invoice($client, InvoiceStatus::Posted, '1000000.01');
        $this->qboLog($inv, '999999.99', '2026-10-21 06:00:00');

        $tab = $this->tab($this->billing($client)->assertOk());

        $this->assertSame('$1,000,002.99', $this->figure($tab, 'outstanding'));

        // The rendered figure rounds to 2 places and so hides float drift; the
        // summed value itself must be the exact decimal string.
        $other = $this->client();
        for ($i = 0; $i < 30; $i++) {
            $this->invoice($other, InvoiceStatus::Posted, '0.10', ['due_date' => '2026-12-31']);
        }
        $summary = app(\App\Services\ClientBillingSummary::class)->build($other);
        $this->assertSame('3.00', $summary['invoices']['outstanding']);
    }

    // ── Overdue ─────────────────────────────────────────────────────────────

    public function test_overdue_counts_past_due_open_invoices_only_with_the_same_balance_rule(): void
    {
        $client = $this->client();
        $past = $this->invoice($client, InvoiceStatus::Synced, '1180.00', ['due_date' => '2026-09-30']);
        $this->qboLog($past, '180.00', '2026-10-21 06:00:00');
        $this->invoice($client, InvoiceStatus::Posted, '500.00', ['due_date' => '2026-10-21']);
        $this->invoice($client, InvoiceStatus::Posted, '2450.00', ['due_date' => '2026-10-31']);
        $this->invoice($client, InvoiceStatus::Posted, '77.00', ['due_date' => '2026-10-22']);
        $this->invoice($client, InvoiceStatus::Draft, '999.00', ['due_date' => '2026-09-01']);

        $tab = $this->tab($this->billing($client)->assertOk());

        $this->assertSame('$680.00', $this->figure($tab, 'overdue'));
        $this->assertStringContainsString('2 invoices past due', $tab);
        $this->assertSame('$3,207.00', $this->figure($tab, 'outstanding'));
    }

    // ── Empty client ────────────────────────────────────────────────────────

    public function test_empty_client_renders_the_empty_state_lines(): void
    {
        $client = $this->client();

        $tab = $this->tab($this->billing($client)->assertOk());

        $this->assertSame('$0.00', $this->figure($tab, 'outstanding'));
        $this->assertSame('$0.00', $this->figure($tab, 'overdue'));
        $this->assertSame('$0.00', $this->figure($tab, 'monthly'));
        foreach (['No open invoices', 'No active contracts', 'No prepay contract',
            'No invoices for this client yet.', 'No active contracts.', 'This client has no prepay contract.'] as $line) {
            $this->assertStringContainsString($line, $tab);
        }
        // No QuickBooks customer id: a "not linked" line, not a hidden section, and nothing is called.
        $this->assertStringNotContainsString('data-section="benjipays"', $tab);
        $this->assertStringContainsString("BenjiPays isn't linked for this client.", $tab);
        Http::assertNothingSent();
    }

    // ── Contracts, recurring and prepay ─────────────────────────────────────

    private function contract(Client $client, string $name, array $attrs = []): Contract
    {
        return Contract::create(array_merge([
            'client_id' => $client->id, 'name' => $name, 'type' => 'managed',
            'status' => 'active', 'start_date' => '2026-01-01',
        ], $attrs));
    }

    private function profile(Contract $contract, string $period, string $unitPrice, string $qty, string $next): RecurringInvoiceProfile
    {
        $profile = RecurringInvoiceProfile::create([
            'contract_id' => $contract->id, 'name' => $contract->name.' recurring', 'is_active' => true,
            'billing_period' => $period, 'billing_day' => 1, 'payment_terms_days' => 30, 'next_run_date' => $next,
        ]);
        RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id, 'description' => 'Synthetic service', 'unit_price' => $unitPrice,
            'quantity_type' => 'fixed', 'fixed_quantity' => $qty, 'is_taxable' => false, 'sort_order' => 0,
        ]);

        return $profile;
    }

    public function test_monthly_recurring_uses_the_profile_preview_normalised_by_period(): void
    {
        $client = $this->client();
        $this->profile($this->contract($client, 'SYN-MSP-01'), 'monthly', '105.00', '20', '2026-11-01');
        $this->profile($this->contract($client, 'SYN-BK-02'), 'quarterly', '1050.00', '1', '2026-12-01');
        $this->contract($client, 'SYN-OLD-03', ['status' => 'cancelled']);
        $this->contract($client, 'SYN-NOPROF-04');

        $tab = $this->tab($this->billing($client)->assertOk());

        // 105 x 20 = 2,100.00 monthly; 1,050 quarterly = 350.00 monthly.
        $this->assertSame('$2,450.00', $this->figure($tab, 'monthly'));
        $this->assertStringContainsString('$2,100.00', $tab);
        $this->assertStringContainsString('$350.00', $tab);
        $this->assertStringContainsString('Nov 1, 2026', $tab);
        $this->assertStringContainsString('Dec 1, 2026', $tab);
        $this->assertStringContainsString('2 active contracts with recurring billing', $tab);
        $this->assertStringContainsString('SYN-NOPROF-04', $tab);
        $this->assertStringNotContainsString('SYN-OLD-03', $tab);
    }

    public function test_prepay_shows_the_balance_and_the_ten_newest_entries_without_writing(): void
    {
        $client = $this->client();
        $c = $this->contract($client, 'SYN-PP-01', [
            'prepay_as_amount' => false, 'prepay_total' => 20, 'prepay_used' => 13.75, 'prepay_balance' => 6.25,
        ]);
        for ($d = 1; $d <= 12; $d++) {
            PrepayTransaction::create([
                'contract_id' => $c->id, 'source' => PrepayTransactionSource::TicketTime,
                'date' => sprintf('2026-10-%02d', $d), 'hours' => -0.25, 'description' => sprintf('Synthetic entry %02d', $d),
            ]);
        }
        $before = PrepayTransaction::count();
        $balanceBefore = $c->fresh()->prepay_balance;

        $tab = $this->tab($this->billing($client)->assertOk());

        $this->assertStringContainsString('6.25 hrs', $tab);
        $this->assertStringContainsString('Hours prepay, contract SYN-PP-01', $tab);
        $this->assertStringContainsString('Synthetic entry 12', $tab);
        $this->assertStringContainsString('Synthetic entry 03', $tab);
        $this->assertStringNotContainsString('Synthetic entry 02', $tab);
        $this->assertStringNotContainsString('Synthetic entry 01', $tab);
        $this->assertSame(10, substr_count($tab, 'Synthetic entry'));
        $this->assertStringContainsString(route('contracts.show', $c).'#prepay-history', $tab);
        $this->assertSame($before, PrepayTransaction::count());
        $this->assertSame($balanceBefore, $c->fresh()->prepay_balance);
    }

    // ── Invoice list ────────────────────────────────────────────────────────

    public function test_a_profile_that_fails_to_price_is_left_out_without_dropping_its_priced_siblings(): void
    {
        $client = $this->client();
        $mixed = $this->contract($client, 'SYN-MIX-01');
        $this->profile($mixed, 'monthly', '105.00', '20', '2026-11-01');
        $bad = $this->profile($mixed, 'quarterly', '1050.00', '1', '2026-12-01');
        $badOnly = $this->profile($this->contract($client, 'SYN-BAD-02'), 'monthly', '50.00', '1', '2026-11-01');
        $badIds = [$bad->id, $badOnly->id];
        $this->partialMock(\App\Services\BillingService::class, function (\Mockery\MockInterface $mock) use ($badIds) {
            $mock->shouldReceive('previewInvoice')->andReturnUsing(function (RecurringInvoiceProfile $p) use ($badIds) {
                if (in_array($p->id, $badIds)) {
                    throw new \RuntimeException('synthetic preview failure');
                }

                return ['subtotal' => 2100.0];
            });
        });

        $tab = $this->tab($this->billing($client)->assertOk());

        // The priced monthly profile still counts; the two failing profiles are left out and counted.
        $this->assertSame('$2,100.00', $this->figure($tab, 'monthly'));
        $this->assertStringContainsString('1 active contract with recurring billing; 2 recurring profiles could not be priced and are not included', $tab);
        $this->assertStringContainsString('1 profile could not be priced, not included', $tab);
        $this->assertSame(1, substr_count($tab, 'Could not be priced'));
    }

    public function test_open_invoices_come_first_then_the_newest_25_others_and_a_view_all_link(): void
    {
        $client = $this->client();
        for ($i = 1; $i <= 30; $i++) {
            $this->invoice($client, InvoiceStatus::Paid, '10.00', [
                'invoice_number' => sprintf('INV-SYN-P%02d', $i), 'invoice_date' => sprintf('2026-08-%02d', $i),
            ]);
        }
        $this->invoice($client, InvoiceStatus::Posted, '20.00', ['invoice_number' => 'INV-SYN-OPEN', 'invoice_date' => '2026-01-01']);

        $tab = $this->tab($this->billing($client)->assertOk());

        $this->assertStringContainsString('Open first, then the newest 25 of 31', $tab);
        $this->assertStringNotContainsString('newest · all 31', $tab);
        $this->assertLessThan(strpos($tab, 'INV-SYN-P30'), strpos($tab, 'INV-SYN-OPEN'));
        $this->assertStringContainsString('INV-SYN-P06', $tab);
        $this->assertStringNotContainsString('INV-SYN-P05', $tab);
        $this->assertSame(26, substr_count($tab, 'data-invoice-row='));
        $this->assertStringContainsString('href="'.route('invoices.index', ['client_id' => $client->id]).'">View all 31 invoices', $tab);
    }

    public function test_header_says_all_n_when_every_invoice_is_listed_even_above_25(): void
    {
        // 25 others is exactly the limit, so nothing is cut: 30 invoices, all 30 rows shown.
        $client = $this->client();
        for ($i = 1; $i <= 25; $i++) {
            $this->invoice($client, InvoiceStatus::Paid, '10.00', ['invoice_date' => sprintf('2026-08-%02d', $i)]);
        }
        for ($i = 1; $i <= 5; $i++) {
            $this->invoice($client, InvoiceStatus::Posted, '20.00');
        }

        $tab = $this->tab($this->billing($client)->assertOk());

        $this->assertSame(30, substr_count($tab, 'data-invoice-row='));
        $this->assertStringContainsString('Open first, then newest · all 30', $tab);
        $this->assertStringNotContainsString('newest 25 of', $tab);
    }

    public function test_view_all_target_filters_invoices_index_to_this_client(): void
    {
        $client = $this->client();
        $other = $this->client();
        $this->invoice($client, InvoiceStatus::Posted, '10.00', ['invoice_number' => 'INV-SYN-MINE']);
        $this->invoice($other, InvoiceStatus::Posted, '10.00', ['invoice_number' => 'INV-SYN-THEIRS']);

        $response = $this->actingAs($this->user)->get(route('invoices.index', ['client_id' => $client->id]));

        $response->assertOk();
        $response->assertSee('INV-SYN-MINE');
        $response->assertDontSee('INV-SYN-THEIRS');
    }

    // ── N+1 ─────────────────────────────────────────────────────────────────

    /** Queries the billing route issues for a client with $open payable invoices, each with a QBO log. */
    private function billingQueryCount(int $open): array
    {
        $client = $this->client();
        for ($i = 0; $i < $open; $i++) {
            $inv = $this->invoice($client, $i % 2 ? InvoiceStatus::Synced : InvoiceStatus::Posted, '100.00');
            $this->qboLog($inv, '40.00', '2026-10-21 06:00:00');
        }
        for ($i = 0; $i < 30; $i++) {
            $this->invoice($client, InvoiceStatus::Paid, '10.00');
        }
        $this->actingAs($this->user)->get(route('clients.billing', $client))->assertOk(); // warm caches
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($this->user)->get(route('clients.billing', $client));
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $response->assertOk();

        return [$count, $this->tab($response)];
    }

    public function test_query_count_does_not_grow_with_the_number_of_open_invoices(): void
    {
        [$count5] = $this->billingQueryCount(5);
        [$count60, $tab] = $this->billingQueryCount(60);

        $this->assertStringContainsString('includes 60 part-paid invoices', $tab);
        $this->assertSame('$2,400.00', $this->figure($tab, 'outstanding'));
        // Measured 2026-10-22: 41 queries at 60 open invoices (the whole show page,
        // not only the tab). A per-invoice query adds 60 and breaks both asserts.
        $this->assertSame($count5, $count60, 'billing query count grew with the number of open invoices');
        $this->assertLessThanOrEqual(self::QUERY_CEILING, $count60);
    }

    // ── BenjiPays ───────────────────────────────────────────────────────────

    public function test_benjipays_success_shows_methods_payments_and_the_cache_time(): void
    {
        Setting::setValue('app_timezone', 'America/Los_Angeles');
        $this->configureBenjiPays();
        $this->fakeBenjiPaysOk();
        $client = $this->client(['qbo_customer_id' => self::CUST]);

        $tab = $this->tab($this->billing($client)->assertOk());

        // Read at 12:00 UTC = 05:00 in Los Angeles (PDT).
        $this->assertStringContainsString('Read-only, cached 05:00', $tab);
        $this->assertStringContainsString('Visa', $tab);
        $this->assertStringContainsString('ending 4242', $tab);
        $this->assertStringContainsString('Bank account', $tab);
        $this->assertStringContainsString('ending 6789', $tab);
        $this->assertStringContainsString('INV-SYN-PAID', $tab);
        $this->assertStringContainsString('$1,225.00', $tab);
        $this->assertStringNotContainsString('Unavailable', $tab);
        $this->assertStringNotContainsString("isn't linked", $tab);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() !== 'GET');
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/v2/transactions') && str_contains($r->url(), 'limit=5'));
    }

    public function test_benjipays_read_is_cached_per_client(): void
    {
        $this->configureBenjiPays();
        $this->fakeBenjiPaysOk();
        $client = $this->client(['qbo_customer_id' => self::CUST]);

        $this->billing($client)->assertOk();
        $this->travel(5)->minutes();
        $tab = $this->tab($this->billing($client)->assertOk());

        Http::assertSentCount(2);
        $this->assertStringContainsString('Read-only, cached 12:00', $tab);
    }

    public function test_benjipays_timeout_renders_unavailable_and_the_rest_of_the_page(): void
    {
        $this->configureBenjiPays();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        Log::spy();
        $client = $this->client(['qbo_customer_id' => self::CUST]);
        $this->invoice($client, InvoiceStatus::Posted, '1907.50');

        $response = $this->billing($client);

        $response->assertOk();
        $tab = $this->tab($response);
        $this->assertStringContainsString('Unavailable', $tab);
        $this->assertStringContainsString('BenjiPays could not be read just now, so payment methods and payments are not shown.', $tab);
        $this->assertStringNotContainsString('Read-only, cached', $tab);
        $this->assertStringNotContainsString('cURL', $tab);
        $this->assertStringNotContainsString('timed out', $tab);
        // The rest of the page is present.
        $this->assertSame('$1,907.50', $this->figure($tab, 'outstanding'));
        $this->assertStringContainsString('Contracts and recurring', $tab);
        $this->assertStringContainsString('Prepay', $tab);
        // One attempt, no inline retry: the second read is skipped once the first failed.
        Http::assertSentCount(0);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($msg, $ctx) => $msg === '[ClientBilling] BenjiPays read unavailable'
            && $ctx['exception'] === \App\Services\BenjiPays\BenjiPaysException::class
            && ! str_contains(json_encode($ctx), 'cURL'));
    }

    public function test_benjipays_http_error_and_bad_shape_render_unavailable_not_500(): void
    {
        $this->configureBenjiPays();
        Http::fake([
            'https://api.benjipays.com/v2/payment-methods*' => Http::response(['data' => 'not-a-list', 'pagination' => []]),
            '*' => Http::response(['message' => 'VENDOR-SENTINEL-TEXT'], 503),
        ]);
        $client = $this->client(['qbo_customer_id' => self::CUST]);

        $tab = $this->tab($this->billing($client)->assertOk());

        $this->assertStringContainsString('Unavailable', $tab);
        $this->assertStringNotContainsString('VENDOR-SENTINEL-TEXT', $tab);
    }

    public function test_benjipays_not_configured_shows_not_linked_and_sends_zero_requests(): void
    {
        $client = $this->client(['qbo_customer_id' => self::CUST]);

        $tab = $this->tab($this->billing($client)->assertOk());

        $this->assertStringNotContainsString('data-section="benjipays"', $tab);
        $this->assertSame(1, substr_count($tab, 'data-section="benjipays-unlinked"'));
        $this->assertStringContainsString("BenjiPays isn't linked for this client.", $tab);
        $this->assertStringNotContainsString('Unavailable', $tab);
        Http::assertSentCount(0);
    }

    public function test_benjipays_configured_but_no_qbo_customer_id_shows_not_linked_and_sends_zero_requests(): void
    {
        $this->configureBenjiPays();
        $client = $this->client(['qbo_customer_id' => null]);

        $tab = $this->tab($this->billing($client)->assertOk());

        $this->assertStringNotContainsString('data-section="benjipays"', $tab);
        $this->assertStringContainsString("BenjiPays isn't linked for this client.", $tab);
        Http::assertSentCount(0);
    }

    // ── Read-only ───────────────────────────────────────────────────────────

    public function test_benjipays_lists_approved_payments_only(): void
    {
        $this->configureBenjiPays();
        Http::fake([
            'https://api.benjipays.com/v2/payment-methods*' => Http::response(self::page([self::pm()])),
            'https://api.benjipays.com/v2/transactions*' => Http::response(self::page([
                self::txn(),
                self::txn(['id' => 'txn-syn-2', 'status' => 'declined', 'approved' => false, 'amount' => 333.0, 'invoiceNumber' => 'INV-SYN-DECLINED']),
                self::txn(['id' => 'txn-syn-3', 'type' => 'refund', 'amount' => 444.0, 'invoiceNumber' => 'INV-SYN-REFUND']),
                self::txn(['id' => 'txn-syn-4', 'status' => 'approved_void_pending', 'amount' => 555.0, 'invoiceNumber' => 'INV-SYN-VOIDING']),
            ])),
        ]);
        $client = $this->client(['qbo_customer_id' => self::CUST]);

        $tab = $this->tab($this->billing($client)->assertOk());

        $this->assertStringContainsString('INV-SYN-PAID', $tab);
        $this->assertStringContainsString('$1,225.00', $tab);
        foreach (['INV-SYN-DECLINED', 'INV-SYN-REFUND', 'INV-SYN-VOIDING', '$333.00', '$444.00', '$555.00'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $tab);
        }
        $this->assertStringContainsString('Payments with approved status only; declined and void-pending payments and refund or void entries are not listed. A later refund or void of a listed payment is not checked here.', $tab);
        $this->assertStringNotContainsString('voided and refunded transactions are not listed', $tab);
    }

    public function test_the_billing_tab_contains_no_write_form(): void
    {
        $this->configureBenjiPays();
        $this->fakeBenjiPaysOk();
        $client = $this->client(['qbo_customer_id' => self::CUST]);
        $inv = $this->invoice($client, InvoiceStatus::Posted, '100.00');
        $this->qboLog($inv, '40.00', '2026-10-21 06:00:00');
        $c = $this->contract($client, 'SYN-PP-01', ['prepay_as_amount' => false, 'prepay_balance' => 1]);
        PrepayTransaction::create(['contract_id' => $c->id, 'source' => PrepayTransactionSource::ManualCredit, 'date' => '2026-10-01', 'hours' => 1]);

        $tab = $this->tab($this->billing($client)->assertOk());

        $this->assertStringContainsString('data-section="benjipays"', $tab);
        $this->assertDoesNotMatchRegularExpression('/<form\b/i', $tab);
        $this->assertDoesNotMatchRegularExpression('/name="_method"/i', $tab);
        $this->assertDoesNotMatchRegularExpression('/<button\b[^>]*type="submit"/i', $tab);
        $this->assertDoesNotMatchRegularExpression('/formmethod|formaction/i', $tab);
    }
}
