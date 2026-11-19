<?php

namespace Tests\Feature\Sku;

use App\Models\Setting;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The SKU form's portal promo price: three optional fields, entered and shown
 * in Pacific (America/Los_Angeles) and stored in UTC. The form refuses a
 * partial or inconsistent promo rather than saving one the price read would
 * ignore. Prices are synthetic (unit price 100.00, promo 80.00).
 */
class SkuPortalPromoFormTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Example Prepaid Hour Block',
            'sku_code' => 'EX-PH-1',
            'unit_price' => '100.00',
            'unit_cost' => '40.00',
            'prepaid_time_minutes' => '60',
            'is_taxable' => '1',
            'is_active' => '1',
        ], $overrides);
    }

    private function promo(array $overrides = []): array
    {
        return $this->payload(array_merge([
            'portal_promo_price' => '80.00',
            'portal_promo_starts_at' => '2026-11-27T00:00:00',
            'portal_promo_ends_at' => '2026-11-30T23:59:59',
        ], $overrides));
    }

    public function test_pacific_midnight_on_nov_27_is_stored_as_0800_utc(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('skus.store'), $this->promo())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $sku = Sku::where('sku_code', 'EX-PH-1')->firstOrFail();
        $raw = \DB::table('skus')->where('id', $sku->id)->first();

        $this->assertSame('2026-11-27 08:00:00', Carbon::parse($raw->portal_promo_starts_at)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-01 07:59:59', Carbon::parse($raw->portal_promo_ends_at)->format('Y-m-d H:i:s'));
        $this->assertEquals(80.00, (float) $sku->portal_promo_price);
        $this->assertSame('UTC', $sku->portal_promo_starts_at->getTimezone()->getName());
    }

    public function test_the_window_is_pacific_whatever_the_display_timezone_setting(): void
    {
        Setting::setValue('app_timezone', 'America/New_York');

        $this->actingAs(User::factory()->create())
            ->post(route('skus.store'), $this->promo())
            ->assertSessionHasNoErrors();

        $sku = Sku::where('sku_code', 'EX-PH-1')->firstOrFail();
        $this->assertSame('2026-11-27 08:00:00', $sku->portal_promo_starts_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_the_edit_form_shows_the_window_back_in_pacific(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('skus.store'), $this->promo())->assertSessionHasNoErrors();
        $sku = Sku::where('sku_code', 'EX-PH-1')->firstOrFail();

        Carbon::setTestNow(Carbon::parse('2026-11-20 18:00:00', 'UTC'));
        $html = $this->actingAs($user)->get(route('skus.edit', $sku))->assertOk()->getContent();

        $this->assertStringContainsString('value="2026-11-27T00:00:00"', $html);
        $this->assertStringContainsString('value="2026-11-30T23:59:59"', $html);
        $this->assertStringContainsString('Not in force', $html);
    }

    public function test_update_can_set_and_then_clear_the_promo(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('skus.store'), $this->payload())->assertSessionHasNoErrors();
        $sku = Sku::where('sku_code', 'EX-PH-1')->firstOrFail();
        $this->assertNull($sku->portal_promo_price);

        $this->actingAs($user)->patch(route('skus.update', $sku), $this->promo())->assertSessionHasNoErrors();
        $this->assertEquals(80.00, (float) $sku->fresh()->portal_promo_price);

        $this->actingAs($user)->patch(route('skus.update', $sku), $this->promo([
            'portal_promo_price' => '',
            'portal_promo_starts_at' => '',
            'portal_promo_ends_at' => '',
        ]))->assertSessionHasNoErrors();

        $fresh = $sku->fresh();
        $this->assertNull($fresh->portal_promo_price);
        $this->assertNull($fresh->portal_promo_starts_at);
        $this->assertNull($fresh->portal_promo_ends_at);
    }

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function refused(): array
    {
        return [
            'price only' => [['portal_promo_starts_at' => '', 'portal_promo_ends_at' => ''], 'portal_promo_price'],
            'no price' => [['portal_promo_price' => ''], 'portal_promo_price'],
            'no end' => [['portal_promo_ends_at' => ''], 'portal_promo_price'],
            'end equals start' => [['portal_promo_ends_at' => '2026-11-27T00:00:00'], 'portal_promo_ends_at'],
            'end before start' => [['portal_promo_ends_at' => '2026-11-26T23:59:59'], 'portal_promo_ends_at'],
            'price zero' => [['portal_promo_price' => '0'], 'portal_promo_price'],
            'price equals unit price' => [['portal_promo_price' => '100.00'], 'portal_promo_price'],
            'price above unit price' => [['portal_promo_price' => '120.00'], 'portal_promo_price'],
        ];
    }

    /**
     * @param  array<string, string>  $bad
     */
    #[DataProvider('refused')]
    public function test_the_form_refuses_a_promo_the_price_read_would_ignore(array $bad, string $field): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('skus.store'), $this->promo($bad))
            ->assertSessionHasErrors($field);

        $this->assertSame(0, Sku::count());
    }

    /**
     * @param  array<string, string>  $bad
     */
    #[DataProvider('refused')]
    public function test_update_refuses_a_promo_the_price_read_would_ignore(array $bad, string $field): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('skus.store'), $this->payload())->assertSessionHasNoErrors();
        $sku = Sku::where('sku_code', 'EX-PH-1')->firstOrFail();

        $this->actingAs($user)
            ->patch(route('skus.update', $sku), $this->promo($bad))
            ->assertSessionHasErrors($field);

        $this->assertNull($sku->fresh()->portal_promo_price);
    }
}
