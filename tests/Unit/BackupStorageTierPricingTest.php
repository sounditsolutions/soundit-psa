<?php

namespace Tests\Unit;

use App\Models\BackupStorageTier;
use App\Models\Sku;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Volume-pricing tier selection on Sku::priceForStorageGb(). Pure unit test:
 * the tier collection is hydrated in-memory (setRelation) so no database is
 * touched.
 */
class BackupStorageTierPricingTest extends TestCase
{
    /** @param array<int,array{up_to_gb:?int,unit_price:float|string}> $tiers */
    private function skuWithTiers(array $tiers): Sku
    {
        $sku = new Sku;
        $sku->setRelation('backupStorageTiers', new Collection(array_map(
            fn (array $t) => new BackupStorageTier($t),
            $tiers,
        )));

        return $sku;
    }

    public function test_no_tiers_returns_null_so_caller_falls_back_to_flat_price(): void
    {
        $sku = $this->skuWithTiers([]);

        $this->assertNull($sku->priceForStorageGb(500));
    }

    public function test_single_unbounded_tier_prices_any_quantity(): void
    {
        $sku = $this->skuWithTiers([
            ['up_to_gb' => null, 'unit_price' => '0.40'],
        ]);

        $this->assertSame(0.40, $sku->priceForStorageGb(1));
        $this->assertSame(0.40, $sku->priceForStorageGb(100_000));
    }

    public function test_selects_first_tier_whose_bound_covers_the_quantity(): void
    {
        $sku = $this->skuWithTiers([
            ['up_to_gb' => 100, 'unit_price' => '1.00'],
            ['up_to_gb' => 500, 'unit_price' => '0.80'],
            ['up_to_gb' => null, 'unit_price' => '0.60'],
        ]);

        $this->assertSame(1.00, $sku->priceForStorageGb(50));    // in first tier
        $this->assertSame(1.00, $sku->priceForStorageGb(100));   // boundary is inclusive
        $this->assertSame(0.80, $sku->priceForStorageGb(101));   // spills into second
        $this->assertSame(0.80, $sku->priceForStorageGb(500));   // boundary is inclusive
        $this->assertSame(0.60, $sku->priceForStorageGb(501));   // unbounded catch-all
        $this->assertSame(0.60, $sku->priceForStorageGb(9_999));
    }

    public function test_exceeding_all_bounded_tiers_without_catch_all_uses_top_rate(): void
    {
        $sku = $this->skuWithTiers([
            ['up_to_gb' => 100, 'unit_price' => '1.00'],
            ['up_to_gb' => 500, 'unit_price' => '0.80'],
        ]);

        // No unbounded tier: never drop back to an unpriced fallback once
        // tiers exist — bill the highest bounded rate.
        $this->assertSame(0.80, $sku->priceForStorageGb(10_000));
    }

    public function test_tier_order_does_not_matter(): void
    {
        $sku = $this->skuWithTiers([
            ['up_to_gb' => null, 'unit_price' => '0.60'],
            ['up_to_gb' => 500, 'unit_price' => '0.80'],
            ['up_to_gb' => 100, 'unit_price' => '1.00'],
        ]);

        $this->assertSame(1.00, $sku->priceForStorageGb(50));
        $this->assertSame(0.80, $sku->priceForStorageGb(300));
        $this->assertSame(0.60, $sku->priceForStorageGb(600));
    }
}
