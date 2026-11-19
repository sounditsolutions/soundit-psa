<?php

namespace Tests\Feature\Prepay;

use App\Enums\InvoiceStatus;
use App\Enums\PersonType;
use App\Enums\PrepayTransactionSource;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractActivity;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Person;
use App\Models\PrepayTransaction;
use App\Models\Setting;
use App\Models\Sku;
use App\Models\User;
use App\Services\PrepayOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A dated promo price on a SKU, charged only on a portal prepaid-time purchase.
 *
 * The window below is 2026-11-27 00:00:00 to 2026-11-30 23:59:59 Pacific,
 * stored in UTC as 08:00:00Z on the 27th and 07:59:59Z on Dec 1. Both ends are
 * INCLUSIVE: the promo is in force at the start second and at the end second,
 * and not one second either side of them.
 *
 * Prices are synthetic: unit price 100.00, promo 80.00.
 */
class PortalPromoPriceTest extends TestCase
{
    use RefreshDatabase;

    private const START_UTC = '2026-11-27 08:00:00';

    private const END_UTC = '2026-12-01 07:59:59';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('portal_enabled', '1');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sku(array $promo = []): Sku
    {
        $sku = Sku::create([
            'name' => 'Example Prepaid Hour Block',
            'sku_code' => 'EX-PREPAID-'.uniqid(),
            'unit_price' => 100.00,
            'unit_cost' => 40.00,
            'prepaid_time_minutes' => 60,
            'is_taxable' => false,
            'is_active' => true,
        ]);

        // Written straight to the table, past any form validation: the price
        // read must stand on its own against whatever the row holds.
        DB::table('skus')->where('id', $sku->id)->update(array_merge([
            'portal_promo_price' => '80.00',
            'portal_promo_starts_at' => self::START_UTC,
            'portal_promo_ends_at' => self::END_UTC,
        ], $promo));

        return $sku->fresh();
    }

    private function contract(Sku $sku, array $overrides = []): Contract
    {
        $client = Client::create(['name' => 'Example Dental Co.']);

        return Contract::create(array_merge([
            'client_id' => $client->id,
            'name' => 'Example Managed Services',
            'type' => 'managed',
            'status' => 'active',
            'start_date' => '2026-01-01',
            'prepay_as_amount' => false,
            'prepay_total' => 0,
            'prepay_used' => 0,
            'prepay_balance' => 0,
            'portal_prepay_sku_id' => $sku->id,
        ], $overrides));
    }

    private function person(Contract $contract): Person
    {
        return Person::create([
            'client_id' => $contract->client_id,
            'person_type' => PersonType::User,
            'first_name' => 'Alex',
            'last_name' => 'Example',
            'email' => 'alex-'.uniqid().'@example.test',
            'is_active' => true,
            'portal_enabled' => true,
            'company_wide_access' => true,
        ]);
    }

    private function at(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
    }

    private function buy(Contract $contract, string $expected, int $qty = 2, array $extra = [])
    {
        return $this->actingAs($this->person($contract), 'portal')
            ->post(route('portal.prepaid.store', $contract), array_merge([
                'quantity' => $qty,
                'expected_unit_price' => $expected,
            ], $extra));
    }

    private function onlyLine(): InvoiceLine
    {
        $this->assertSame(1, InvoiceLine::count(), 'exactly one invoice line');

        return InvoiceLine::firstOrFail();
    }

    // ── Condition 5: the charged price is what is recorded ──

    public function test_purchase_in_the_window_charges_the_promo_on_the_line_and_the_record(): void
    {
        $contract = $this->contract($this->sku());
        $this->at('2026-11-28 18:00:00');

        $this->buy($contract, '80.00')->assertRedirect();

        $line = $this->onlyLine();
        $this->assertSame('Example Prepaid Hour Block', $line->description);
        $this->assertEquals(80.00, (float) $line->unit_price);
        $this->assertEquals(160.00, (float) $line->amount);
        $this->assertSame(120, (int) $line->prepaid_time_minutes);

        $invoice = Invoice::firstOrFail();
        $this->assertEquals(160.00, (float) $invoice->subtotal);
        $this->assertEquals(160.00, (float) $invoice->total);

        $activity = ContractActivity::where('action', 'portal_prepay_purchase')->firstOrFail();
        $this->assertEquals(80.00, (float) $activity->changes['unit_price']);
        $this->assertEquals(160.00, (float) $activity->changes['amount']);
    }

    public function test_the_service_writes_the_price_it_is_given_not_the_skus_unit_price(): void
    {
        $sku = $this->sku();
        $contract = $this->contract($sku);
        $this->at('2026-11-28 18:00:00');

        app(PrepayOrderService::class)->createPrepayInvoice($contract, $sku, 1, $this->person($contract), 80.00);

        $this->assertEquals(80.00, (float) $this->onlyLine()->unit_price);
    }

    // ── Condition 1: the server decides the price ──

    public function test_a_form_opened_before_the_start_is_refused_after_the_start(): void
    {
        $contract = $this->contract($this->sku());
        $this->at(self::START_UTC);

        $this->buy($contract, '100.00')
            ->assertRedirect(route('portal.prepaid.form', $contract))
            ->assertSessionHas('error');

        $this->assertSame(0, Invoice::count(), 'not charged the other price');
    }

    public function test_a_form_opened_in_the_window_is_refused_after_the_end(): void
    {
        $contract = $this->contract($this->sku());
        $this->at('2026-12-01 08:00:00');

        $this->buy($contract, '80.00')
            ->assertRedirect(route('portal.prepaid.form', $contract))
            ->assertSessionHas('error');

        $this->assertSame(0, Invoice::count(), 'not charged the promo after the end');
    }

    public function test_a_price_posted_in_the_form_is_never_charged(): void
    {
        $contract = $this->contract($this->sku());
        $this->at('2026-11-28 18:00:00');

        $this->buy($contract, '80.00', 1, ['unit_price' => '1.00', 'price' => '1.00'])->assertRedirect();

        $this->assertEquals(80.00, (float) $this->onlyLine()->unit_price);
    }

    public function test_outside_the_window_the_unit_price_is_charged(): void
    {
        $contract = $this->contract($this->sku());
        $this->at('2026-11-20 18:00:00');

        $this->buy($contract, '100.00')->assertRedirect();

        $this->assertEquals(100.00, (float) $this->onlyLine()->unit_price);
    }

    public function test_the_purchase_form_shows_the_price_in_force_and_posts_it_back(): void
    {
        $contract = $this->contract($this->sku());
        $this->at('2026-11-28 18:00:00');

        $html = $this->actingAs($this->person($contract), 'portal')
            ->get(route('portal.prepaid.form', $contract))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="expected_unit_price" value="80.00"', $html);
        $this->assertStringContainsString('$80.00 until Nov 30, 2026 11:59:59 PM PST, then $100.00', $html);
    }

    public function test_the_purchase_form_outside_the_window_is_unchanged(): void
    {
        $contract = $this->contract($this->sku());
        $this->at('2026-11-20 18:00:00');

        $html = $this->actingAs($this->person($contract), 'portal')
            ->get(route('portal.prepaid.form', $contract))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="expected_unit_price" value="100.00"', $html);
        $this->assertStringNotContainsString('until', $html);
    }

    // ── Condition 3: both edges, to the second, compared in UTC ──

    public function test_edges_are_inclusive_to_the_second(): void
    {
        $sku = $this->sku();

        $cases = [
            '2026-11-27 07:59:59' => 100.00, // start - 1s
            '2026-11-27 08:00:00' => 80.00,  // start (inclusive)
            '2026-12-01 07:59:59' => 80.00,  // end (inclusive)
            '2026-12-01 08:00:00' => 100.00, // end + 1s
        ];

        foreach ($cases as $utc => $expected) {
            $this->at($utc);
            $this->assertEquals($expected, $sku->fresh()->portalPrepayUnitPrice(), "at {$utc}Z");
        }
    }

    public function test_a_purchase_one_second_after_the_end_is_charged_the_unit_price(): void
    {
        $contract = $this->contract($this->sku());
        $this->at('2026-12-01 08:00:00');

        $this->buy($contract, '100.00', 1)->assertRedirect();

        $this->assertEquals(100.00, (float) $this->onlyLine()->unit_price);
    }

    public function test_a_purchase_at_the_start_second_is_charged_the_promo(): void
    {
        $contract = $this->contract($this->sku());
        $this->at(self::START_UTC);

        $this->buy($contract, '80.00', 1)->assertRedirect();

        $this->assertEquals(80.00, (float) $this->onlyLine()->unit_price);
    }

    // ── Condition 2: fail safe to the unit price, even for a bad row in the table ──

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badRows(): array
    {
        return [
            'price empty' => [['portal_promo_price' => null]],
            'start empty' => [['portal_promo_starts_at' => null]],
            'end empty' => [['portal_promo_ends_at' => null]],
            'end equals start' => [['portal_promo_ends_at' => self::START_UTC]],
            'end before start' => [['portal_promo_starts_at' => '2026-11-29 08:00:00', 'portal_promo_ends_at' => '2026-11-28 08:00:00']],
            'price zero' => [['portal_promo_price' => '0.00']],
            'price negative' => [['portal_promo_price' => '-5.00']],
            'price equals unit price' => [['portal_promo_price' => '100.00']],
            'price above unit price' => [['portal_promo_price' => '120.00']],
        ];
    }

    /**
     * @param  array<string, mixed>  $bad
     */
    #[DataProvider('badRows')]
    public function test_a_bad_promo_row_is_ignored_and_the_unit_price_charged(array $bad): void
    {
        $contract = $this->contract($this->sku($bad));
        $this->at('2026-11-28 18:00:00');

        $this->assertEquals(100.00, $contract->portalPrepaySku->portalPrepayUnitPrice());

        $this->buy($contract, '100.00', 1)->assertRedirect();
        $this->assertEquals(100.00, (float) $this->onlyLine()->unit_price);
    }

    // ── Condition 4: no other reader of unit_price sees the promo ──

    public function test_the_staff_invoice_form_offers_the_unit_price_during_a_promo(): void
    {
        $this->sku();
        $this->at('2026-11-28 18:00:00');

        $html = $this->actingAs(User::factory()->create())
            ->get(route('invoices.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('"price":"100.00"', $html);
        $this->assertStringNotContainsString('"price":"80.00"', $html);
    }

    public function test_auto_top_up_charges_the_unit_price_during_a_promo(): void
    {
        $sku = $this->sku();
        $contract = $this->contract($sku);
        $this->at('2026-11-28 18:00:00');

        app(PrepayOrderService::class)->createAutoTopUpInvoice($contract, $sku, 1);

        $this->assertEquals(100.00, (float) $this->onlyLine()->unit_price);
    }

    public function test_the_sku_unit_price_itself_is_unchanged_during_a_promo(): void
    {
        $sku = $this->sku();
        $this->at('2026-11-28 18:00:00');

        $this->assertEquals(100.00, (float) $sku->fresh()->unit_price);
        $this->assertEquals(80.00, $sku->fresh()->portalPrepayUnitPrice());
    }

    // ── Condition 6: ordinary units ──

    public function test_units_bought_at_the_promo_are_ordinary_units(): void
    {
        $contract = $this->contract($this->sku());

        $this->at('2026-11-28 18:00:00');
        $this->buy($contract, '80.00', 2)->assertRedirect();
        $this->at('2026-12-02 18:00:00');
        $this->buy($contract, '100.00', 1)->assertRedirect();

        $this->assertSame(2, Invoice::count(), 'two purchases');

        // Paid is a staff (or sync) act; the deposit attributes it to a staff user.
        $this->actingAs(User::factory()->create());
        foreach (Invoice::orderBy('id')->get() as $invoice) {
            $invoice->update(['status' => InvoiceStatus::Paid]);
        }

        $deposits = PrepayTransaction::where('contract_id', $contract->id)
            ->where('source', PrepayTransactionSource::InvoiceDeposit)
            ->orderBy('id')->get();

        $this->assertCount(2, $deposits);
        $this->assertEquals(2.0, (float) $deposits[0]->hours);
        $this->assertNull($deposits[0]->expiry_date, 'a promo lot carries no expiry');
        $this->assertNull($deposits[1]->expiry_date);
        $this->assertSame(1, Contract::count(), 'no separate balance');
        $this->assertEquals(3.0, (float) $contract->fresh()->prepay_balance);
    }
}
