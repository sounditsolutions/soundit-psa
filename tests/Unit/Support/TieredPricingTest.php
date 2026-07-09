<?php

namespace Tests\Unit\Support;

use App\Support\TieredPricing;
use Tests\TestCase;

class TieredPricingTest extends TestCase
{
    /** The canonical example from the feature request: first 10 @ $10, then $8. */
    private array $twoTier = [
        ['up_to' => 10, 'unit_price' => 10.0],
        ['up_to' => 30, 'unit_price' => 8.0],
    ];

    /** Three explicit bands: first 10 @ $10, next 20 @ $8, rest @ $5. */
    private array $threeTier = [
        ['up_to' => 10, 'unit_price' => 10.0],
        ['up_to' => 30, 'unit_price' => 8.0],
        ['up_to' => null, 'unit_price' => 5.0],
    ];

    public function test_quantity_within_first_band_uses_first_price_only(): void
    {
        $segments = TieredPricing::breakdown($this->twoTier, 5);

        $this->assertCount(1, $segments);
        $this->assertSame(5, $segments[0]['quantity']);
        $this->assertSame(10.0, $segments[0]['unit_price']);
        $this->assertSame(1, $segments[0]['from']);
        $this->assertSame(5, $segments[0]['to']);
        $this->assertSame(50.0, TieredPricing::total($this->twoTier, 5));
    }

    public function test_quantity_at_band_boundary_does_not_spill_over(): void
    {
        $segments = TieredPricing::breakdown($this->twoTier, 10);

        $this->assertCount(1, $segments);
        $this->assertSame(10, $segments[0]['quantity']);
        $this->assertSame(100.0, TieredPricing::total($this->twoTier, 10));
    }

    public function test_quantity_spanning_two_bands_splits_graduated(): void
    {
        // 25 units: first 10 @ $10 = $100, next 15 @ $8 = $120 => $220.
        $segments = TieredPricing::breakdown($this->twoTier, 25);

        $this->assertCount(2, $segments);
        $this->assertSame(10, $segments[0]['quantity']);
        $this->assertSame(10.0, $segments[0]['unit_price']);
        $this->assertSame(15, $segments[1]['quantity']);
        $this->assertSame(8.0, $segments[1]['unit_price']);
        $this->assertSame(11, $segments[1]['from']);
        $this->assertSame(25, $segments[1]['to']);
        $this->assertSame(220.0, TieredPricing::total($this->twoTier, 25));
    }

    public function test_final_tier_is_unbounded_even_with_a_stored_ceiling(): void
    {
        // The second tier's up_to (30) is ignored as a ceiling — the last band
        // absorbs everything above 10. 40 units: 10 @ $10 + 30 @ $8 = $340.
        $segments = TieredPricing::breakdown($this->twoTier, 40);

        $this->assertCount(2, $segments);
        $this->assertSame(30, $segments[1]['quantity']);
        $this->assertSame(40, $segments[1]['to']);
        $this->assertSame(340.0, TieredPricing::total($this->twoTier, 40));
    }

    public function test_three_bands_split_across_all_tiers(): void
    {
        // 40 units: 10 @ $10 + 20 @ $8 + 10 @ $5 = $310.
        $segments = TieredPricing::breakdown($this->threeTier, 40);

        $this->assertCount(3, $segments);
        $this->assertSame([10, 20, 10], array_column($segments, 'quantity'));
        $this->assertSame([10.0, 8.0, 5.0], array_column($segments, 'unit_price'));
        $this->assertSame(310.0, TieredPricing::total($this->threeTier, 40));
    }

    public function test_zero_quantity_yields_a_single_zero_segment(): void
    {
        $segments = TieredPricing::breakdown($this->threeTier, 0);

        $this->assertCount(1, $segments);
        $this->assertSame(0, $segments[0]['quantity']);
        $this->assertSame(10.0, $segments[0]['unit_price']);
        $this->assertSame(0.0, TieredPricing::total($this->threeTier, 0));
    }

    public function test_empty_tiers_return_no_segments(): void
    {
        $this->assertSame([], TieredPricing::breakdown([], 25));
        $this->assertSame(0.0, TieredPricing::total([], 25));
    }

    public function test_normalize_sorts_ascending_and_sinks_unbounded_tier_last(): void
    {
        $normalized = TieredPricing::normalize([
            ['up_to' => null, 'unit_price' => 5],
            ['up_to' => 30, 'unit_price' => 8],
            ['up_to' => 10, 'unit_price' => 10],
        ]);

        $this->assertSame(
            [
                ['up_to' => 10, 'unit_price' => 10.0],
                ['up_to' => 30, 'unit_price' => 8.0],
                ['up_to' => null, 'unit_price' => 5.0],
            ],
            $normalized,
        );
    }

    public function test_normalize_drops_tiers_without_a_numeric_price(): void
    {
        $normalized = TieredPricing::normalize([
            ['up_to' => 10, 'unit_price' => ''],
            ['up_to' => 20, 'unit_price' => 'abc'],
            ['up_to' => 30, 'unit_price' => 7.5],
        ]);

        $this->assertCount(1, $normalized);
        $this->assertSame(7.5, $normalized[0]['unit_price']);
        // Sole surviving tier is forced unbounded.
        $this->assertNull($normalized[0]['up_to']);
    }

    public function test_normalize_discards_non_positive_bounds(): void
    {
        $normalized = TieredPricing::normalize([
            ['up_to' => 0, 'unit_price' => 10],
            ['up_to' => -5, 'unit_price' => 9],
            ['up_to' => 15, 'unit_price' => 8],
        ]);

        $this->assertCount(1, $normalized);
        $this->assertSame(8.0, $normalized[0]['unit_price']);
    }

    public function test_single_finite_tier_absorbs_all_units(): void
    {
        // One band capped at 10, but the last band is always unbounded, so 25
        // units all price at $10 => $250.
        $tiers = [['up_to' => 10, 'unit_price' => 10.0]];

        $this->assertSame(250.0, TieredPricing::total($tiers, 25));
    }
}
