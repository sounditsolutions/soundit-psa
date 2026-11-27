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
 * The purchase page's hourly-rate anchor (Sku::portalHourlyAnchor()).
 *
 * The rate comes from THE active SKU in the block's category with
 * prepaid_time_minutes = 60. Zero matches, two or more, an inactive or
 * other-category hourly SKU, or a value not above the block price: no row.
 *
 * Synthetic prices: hourly 40.00; block 150 minutes at 80.00 (anchor 100.00);
 * promo 70.00. The value is computed in integer cents.
 *
 * The singular "hour" arm of the template is not reachable: a 60-minute
 * block in the category is itself a 60-minute match, so it either matches
 * only itself (value equal to its price: no anchor) or makes two matches.
 */
class PortalPrepayAnchorTest extends TestCase
{
    use RefreshDatabase;

    private const CATEGORY = 'Example Support';

    private const ANCHOR_ROW = '2.5 hours at our hourly rate ($40.00/h)';

    private Sku $block;

    private Contract $contract;

    private Person $person;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        Setting::setValue('portal_enabled', '1');
        Carbon::setTestNow(Carbon::parse('2026-12-15 18:00:00', 'UTC'));

        $this->block = $this->sku('Example 2.5 Hour Block', 80.00, 150);

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
            'portal_prepay_sku_id' => $this->block->id,
        ]);
        $this->person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Alex',
            'last_name' => 'Example',
            'email' => 'alex-anchor@example.test',
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

    private function sku(string $name, float $price, int $minutes, array $attrs = []): Sku
    {
        return Sku::create(array_merge([
            'name' => $name,
            'sku_code' => 'EX-'.uniqid(),
            'category' => self::CATEGORY,
            'unit_price' => $price,
            'unit_cost' => 10.00,
            'prepaid_time_minutes' => $minutes,
            'is_taxable' => false,
            'is_active' => true,
        ], $attrs));
    }

    private function hourly(array $attrs = []): Sku
    {
        return $this->sku('Example Hourly', 40.00, 60, $attrs);
    }

    private function promo(): void
    {
        DB::table('skus')->where('id', $this->block->id)->update([
            'portal_promo_price' => '70.00',
            'portal_promo_starts_at' => '2026-12-01 08:00:00',
            'portal_promo_ends_at' => '2027-01-01 07:59:59',
        ]);
    }

    private function page()
    {
        return $this->actingAs($this->person, 'portal')
            ->get(route('portal.prepaid.form', $this->contract))->assertOk();
    }

    public function test_one_matching_hourly_sku_shows_the_three_rows_with_a_promo(): void
    {
        $this->hourly();
        $this->promo();

        $html = $this->page()
            ->assertSeeInOrder([self::ANCHOR_ROW, '$100.00', 'Block price', '$80.00', 'Special price', '$70.00'])
            ->getContent();

        $this->assertSame(1, substr_count($html, 'at our hourly rate'));
    }

    public function test_without_a_promo_the_anchor_and_block_rows_show_and_no_special_row(): void
    {
        $this->hourly();

        $this->page()
            ->assertSeeInOrder([self::ANCHOR_ROW, '$100.00', 'Block price', '$80.00'])
            ->assertDontSee('Special price');
    }

    public function test_zero_matching_hourly_skus_show_no_anchor(): void
    {
        $this->promo();

        $this->page()->assertDontSee('at our hourly rate')->assertDontSee('Block price');
        $this->assertNull($this->block->fresh()->portalHourlyAnchor());
    }

    public function test_two_matching_hourly_skus_show_no_anchor(): void
    {
        $this->hourly();
        $this->sku('Example Labor', 45.00, 60);
        $this->promo();

        $this->page()->assertDontSee('at our hourly rate')->assertDontSee('Block price');
        $this->assertNull($this->block->fresh()->portalHourlyAnchor());
    }

    public function test_an_inactive_hourly_sku_is_not_a_match(): void
    {
        $this->hourly(['is_active' => false]);

        $this->page()->assertDontSee('at our hourly rate');
    }

    public function test_an_inactive_second_hourly_sku_does_not_break_a_single_match(): void
    {
        $this->hourly();
        $this->sku('Example Retired Hourly', 45.00, 60, ['is_active' => false]);

        $this->page()->assertSee(self::ANCHOR_ROW, false);
    }

    public function test_an_hourly_sku_in_another_category_is_not_a_match(): void
    {
        $this->hourly(['category' => 'Example Projects']);

        $this->page()->assertDontSee('at our hourly rate');
    }

    public function test_a_block_with_no_category_shows_no_anchor(): void
    {
        $this->block->update(['category' => null]);
        $this->hourly(['category' => null]);

        $this->page()->assertDontSee('at our hourly rate');
    }

    public function test_an_hourly_value_equal_to_the_block_price_shows_no_anchor(): void
    {
        $this->hourly(['unit_price' => 32.00]); // 2.5 x 32.00 = 80.00, the block price

        $this->page()->assertDontSee('at our hourly rate');
    }

    public function test_an_hourly_value_below_the_block_price_shows_no_anchor(): void
    {
        $this->hourly(['unit_price' => 30.00]); // 75.00 < 80.00

        $this->page()->assertDontSee('at our hourly rate');
    }

    public function test_a_value_one_cent_above_the_block_price_shows_the_anchor(): void
    {
        $this->block->update(['unit_price' => 99.99]);
        $this->hourly(); // 100.00 > 99.99

        $this->page()->assertSee(self::ANCHOR_ROW, false)->assertSee('$100.00');
    }

    public function test_only_a_60_minute_sku_is_the_hourly_rate_not_other_timed_skus(): void
    {
        // A 30-minute SKU and a second block share the category; neither is
        // the hourly rate, so the single 60-minute SKU still is.
        $this->sku('Example Half Hour', 25.00, 30);
        $this->sku('Example 5 Hour Block', 150.00, 300);
        $this->hourly();

        $this->page()->assertSee(self::ANCHOR_ROW, false)->assertSee('$100.00');
    }

    public function test_a_blank_or_whitespace_category_shows_no_anchor(): void
    {
        $hourly = $this->hourly();

        foreach (['', '  '] as $blank) {
            // Block and hourly SKU share the same blank category, so only the
            // blank-category guard stands between them and a match.
            $this->block->update(['category' => $blank]);
            $hourly->update(['category' => $blank]);
            $this->assertSame($blank, $this->block->fresh()->category, 'fixture keeps the blank category');

            $this->page()->assertDontSee('at our hourly rate')->assertDontSee('Block price');
            $this->assertNull($this->block->fresh()->portalHourlyAnchor(), 'category '.json_encode($blank));
        }
    }

    public function test_during_a_promo_a_value_between_the_promo_and_block_price_shows_no_anchor(): void
    {
        // 2.5 x 30.00 = 75.00: above the 70.00 promo, at or below the 80.00
        // block price. The guard compares against the block price.
        $this->hourly(['unit_price' => 30.00]);
        $this->promo();

        $this->page()
            ->assertSee('<strong>$70.00</strong>', false) // the promo is in force
            ->assertDontSee('at our hourly rate')->assertDontSee('Block price');
        $this->assertNull($this->block->fresh()->portalHourlyAnchor());
    }

    public function test_fractional_hours_pin_the_hours_and_value_text(): void
    {
        // 100 minutes = 1.67 hours; 4000 cents x 100 / 60 = 6666.67, rounded
        // once to 6667 cents.
        $this->block->update(['unit_price' => 60.00, 'prepaid_time_minutes' => 100]);
        $this->hourly();

        $this->page()
            ->assertSeeInOrder(['1.67 hours at our hourly rate ($40.00/h)', '$66.67', 'Block price', '$60.00'], false);
        $this->assertSame(
            ['rate' => 40.0, 'hours' => 1.67, 'value' => 66.67],
            $this->block->fresh()->portalHourlyAnchor(),
        );
    }

    public function test_the_value_is_rounded_to_the_cent_once(): void
    {
        // 1999 cents x 150 / 60 = 4997.5, rounded once to 4998 cents.
        $this->block->update(['unit_price' => 45.00]);
        $this->hourly(['unit_price' => 19.99]);

        $this->page()
            ->assertSeeInOrder(['2.5 hours at our hourly rate ($19.99/h)', '$49.98', 'Block price', '$45.00'], false);
        $this->assertSame(
            ['rate' => 19.99, 'hours' => 2.5, 'value' => 49.98],
            $this->block->fresh()->portalHourlyAnchor(),
        );
    }

    public function test_a_soft_deleted_hourly_sku_is_not_a_match(): void
    {
        $this->hourly()->delete();

        $this->page()->assertDontSee('at our hourly rate');
    }
}
