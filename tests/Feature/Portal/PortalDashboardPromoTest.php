<?php

namespace Tests\Feature\Portal;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Support Hours card's promo line: shown only while a portal-prepay SKU
 * has a promo in force, by the same inclusive window Sku::portalPrepayUnitPrice
 * charges by. Hours and prices come from the SKU.
 *
 * Window: 2026-12-01 08:00:00Z to 2027-01-01 07:59:59Z (Dec 1 00:00:00 to
 * Dec 31 23:59:59 Pacific). Synthetic prices: block 120.00, promo 90.00,
 * 90 minutes.
 */
class PortalDashboardPromoTest extends TestCase
{
    use RefreshDatabase;

    private const START_UTC = '2026-12-01 08:00:00';

    private const END_UTC = '2027-01-01 07:59:59';

    private Contract $contract;

    private Sku $sku;

    private Person $person;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        Setting::setValue('portal_enabled', '1');

        $this->sku = Sku::create([
            'name' => 'Example 1.5 Hour Block',
            'sku_code' => 'EX-BLOCK-90',
            'unit_price' => 120.00,
            'unit_cost' => 50.00,
            'prepaid_time_minutes' => 90,
            'is_taxable' => false,
            'is_active' => true,
        ]);
        DB::table('skus')->where('id', $this->sku->id)->update([
            'portal_promo_price' => '90.00',
            'portal_promo_starts_at' => self::START_UTC,
            'portal_promo_ends_at' => self::END_UTC,
        ]);

        $client = Client::create(['name' => 'Example Dental Co.']);
        $this->contract = Contract::create([
            'client_id' => $client->id,
            'name' => 'Example Prepaid Support',
            'type' => 'managed',
            'status' => 'active',
            'start_date' => '2026-01-01',
            'prepay_as_amount' => false,
            'prepay_total' => 0,
            'prepay_used' => 0,
            'prepay_balance' => 1.5,
            'portal_prepay_sku_id' => $this->sku->id,
        ]);
        $this->person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Alex',
            'last_name' => 'Example',
            'email' => 'alex-dash-promo@example.test',
            'is_active' => true,
            'portal_enabled' => true,
            'company_wide_access' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function dashboardAt(string $utc)
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));

        return $this->actingAs($this->person, 'portal')->get(route('portal.dashboard'))->assertOk();
    }

    public function test_a_promo_in_force_shows_the_line_from_the_sku_and_links_to_the_purchase_page(): void
    {
        $this->dashboardAt('2026-12-15 18:00:00')
            ->assertSee('Special')
            ->assertSee('1.5 hours for $90.00')
            ->assertSee('(normally $120.00)')
            ->assertSee('until Dec 31, 2026 11:59 PM PST.')
            ->assertSee('See the special')
            ->assertSee('<a href="'.route('portal.prepaid.form', $this->contract).'" class="fw-semibold">See the special', false);
    }

    public function test_no_promo_shows_no_line(): void
    {
        DB::table('skus')->where('id', $this->sku->id)->update([
            'portal_promo_price' => null, 'portal_promo_starts_at' => null, 'portal_promo_ends_at' => null,
        ]);

        $this->dashboardAt('2026-12-15 18:00:00')
            ->assertSee('Support Hours')
            ->assertDontSee('See the special')
            ->assertDontSee('normally');
    }

    public function test_the_line_shows_at_the_first_and_last_second_of_the_window(): void
    {
        $this->dashboardAt(self::START_UTC)->assertSee('See the special');
        $this->dashboardAt(self::END_UTC)->assertSee('See the special');
    }

    public function test_the_line_is_gone_one_second_either_side_of_the_window(): void
    {
        $this->dashboardAt('2026-12-01 07:59:59')->assertDontSee('See the special');
        $this->dashboardAt('2027-01-01 08:00:00')->assertDontSee('See the special');
    }

    public function test_the_line_agrees_with_the_price_the_purchase_charges_at_each_edge(): void
    {
        foreach (['2026-12-01 07:59:59', self::START_UTC, self::END_UTC, '2027-01-01 08:00:00'] as $t) {
            $at = Carbon::parse($t, 'UTC');
            $charged = $this->sku->fresh()->portalPrepayUnitPrice($at);
            $shown = str_contains($this->dashboardAt($t)->getContent(), 'See the special');
            $this->assertSame($charged === 90.0, $shown, "line and charge disagree at {$t}");
        }
    }

    public function test_an_invalid_promo_shows_no_line(): void
    {
        // A promo at or above the block price is ignored by the price read.
        DB::table('skus')->where('id', $this->sku->id)->update(['portal_promo_price' => '120.00']);

        $this->dashboardAt('2026-12-15 18:00:00')->assertDontSee('See the special');
    }

    public function test_an_inactive_sku_shows_no_line(): void
    {
        $this->sku->update(['is_active' => false]);

        $this->dashboardAt('2026-12-15 18:00:00')->assertDontSee('See the special');
    }

    public function test_a_contract_not_purchasable_in_the_portal_shows_no_line(): void
    {
        $this->contract->update(['portal_prepay_sku_id' => null]);

        $this->dashboardAt('2026-12-15 18:00:00')->assertDontSee('See the special');
    }

    public function test_two_contracts_on_the_sku_link_to_the_chooser(): void
    {
        $this->contract->replicate()->fill(['name' => 'Example Second Agreement'])->save();

        $html = $this->dashboardAt('2026-12-15 18:00:00')->getContent();

        $this->assertSame(1, substr_count($html, 'See the special'), 'one line per SKU, not per contract');
        $this->assertStringContainsString(
            '<a href="'.route('portal.prepaid.select').'" class="fw-semibold">See the special', $html);
    }
}
