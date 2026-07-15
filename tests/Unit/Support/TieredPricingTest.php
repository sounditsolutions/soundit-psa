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

    // ── Money: rounding and cent-level exactness ──

    /**
     * Sub-dollar rates are where a graduated split can lose a cent. Each band
     * is priced and rounded independently, so the bands must still sum to the
     * exact expected total — not "close to" it.
     */
    public function test_fractional_rates_sum_to_the_exact_cent_across_bands(): void
    {
        // First 3 @ $0.07 = $0.21, next 4 @ $0.03 = $0.12, last 3 @ $0.01 = $0.03.
        $tiers = [
            ['up_to' => 3, 'unit_price' => 0.07],
            ['up_to' => 7, 'unit_price' => 0.03],
            ['up_to' => null, 'unit_price' => 0.01],
        ];

        $segments = TieredPricing::breakdown($tiers, 10);

        $this->assertSame([3, 4, 3], array_column($segments, 'quantity'));
        $this->assertSame(0.36, TieredPricing::total($tiers, 10));

        // Sum the per-band amounts exactly as BillingService emits them.
        $summed = 0.0;
        foreach ($segments as $segment) {
            $summed += round($segment['quantity'] * $segment['unit_price'], 2);
        }
        $this->assertSame(0.36, round($summed, 2));
    }

    /**
     * A rate that is not exactly representable in binary floating point
     * (0.1, 0.7) must not drift when multiplied out band by band.
     */
    public function test_binary_unrepresentable_rates_do_not_drift(): void
    {
        $tiers = [
            ['up_to' => 7, 'unit_price' => 0.7],
            ['up_to' => null, 'unit_price' => 0.1],
        ];

        // 7 × 0.70 = 4.90, then 3 × 0.10 = 0.30 => 5.20 exactly.
        $this->assertSame(5.20, TieredPricing::total($tiers, 10));

        $segments = TieredPricing::breakdown($tiers, 10);
        $this->assertSame(4.90, round($segments[0]['quantity'] * $segments[0]['unit_price'], 2));
        $this->assertSame(0.30, round($segments[1]['quantity'] * $segments[1]['unit_price'], 2));
    }

    /**
     * Rates are rounded to whole cents on normalize, because an invoice line's
     * unit_price is decimal(*,2) and Stripe bills in integer cents — a 3-dp
     * rate that survived to the push would be silently re-rounded downstream,
     * so we pin it here instead.
     */
    public function test_normalize_rounds_rates_to_whole_cents(): void
    {
        $normalized = TieredPricing::normalize([
            ['up_to' => 10, 'unit_price' => 1.005],
            ['up_to' => null, 'unit_price' => 0.4499],
        ]);

        $this->assertSame(1.01, $normalized[0]['unit_price']);
        $this->assertSame(0.45, $normalized[1]['unit_price']);

        // And the priced bands use the cent-exact rate, not the raw input.
        $this->assertSame(10 * 1.01 + 5 * 0.45, TieredPricing::total($normalized, 15));
    }

    /**
     * Whatever the bands, every unit is billed exactly once: the segment
     * quantities must partition the quantity with no gap and no double-count.
     */
    public function test_segments_partition_the_quantity_exactly(): void
    {
        foreach ([1, 9, 10, 11, 29, 30, 31, 100] as $quantity) {
            $segments = TieredPricing::breakdown($this->threeTier, $quantity);

            $this->assertSame(
                $quantity,
                array_sum(array_column($segments, 'quantity')),
                "Bands did not partition a quantity of {$quantity}",
            );

            // Bands are contiguous: each starts where the previous ended.
            $expectedFrom = 1;
            foreach ($segments as $segment) {
                $this->assertSame($expectedFrom, $segment['from']);
                $expectedFrom = $segment['to'] + 1;
            }
            $this->assertSame($quantity + 1, $expectedFrom);
        }
    }

    /**
     * total() must equal the sum of the bands breakdown() reports — they are
     * two views of the same money and BillingService relies on both.
     */
    public function test_total_agrees_with_the_sum_of_its_bands(): void
    {
        foreach ([0, 1, 10, 25, 30, 31, 250] as $quantity) {
            $summed = 0.0;
            foreach (TieredPricing::breakdown($this->threeTier, $quantity) as $segment) {
                $summed += round($segment['quantity'] * $segment['unit_price'], 2);
            }

            $this->assertSame(
                TieredPricing::total($this->threeTier, $quantity),
                round($summed, 2),
                "total() disagreed with its own bands at a quantity of {$quantity}",
            );
        }
    }

    /** One unit is the smallest billable quantity and lands in the first band. */
    public function test_single_unit_prices_at_the_first_band(): void
    {
        $segments = TieredPricing::breakdown($this->threeTier, 1);

        $this->assertCount(1, $segments);
        $this->assertSame(1, $segments[0]['quantity']);
        $this->assertSame(10.0, $segments[0]['unit_price']);
        $this->assertSame(10.0, TieredPricing::total($this->threeTier, 1));
    }

    /** Exactly on the middle bound: the third band must not open. */
    public function test_quantity_exactly_on_the_middle_bound_does_not_open_the_next_band(): void
    {
        $segments = TieredPricing::breakdown($this->threeTier, 30);

        $this->assertCount(2, $segments);
        $this->assertSame([10, 20], array_column($segments, 'quantity'));
        // 10 × $10 + 20 × $8 = $260. One unit more would add a single $5 unit.
        $this->assertSame(260.0, TieredPricing::total($this->threeTier, 30));
        $this->assertSame(265.0, TieredPricing::total($this->threeTier, 31));
    }

    /** A negative quantity is treated as zero, never as a credit. */
    public function test_negative_quantity_never_produces_a_credit(): void
    {
        $segments = TieredPricing::breakdown($this->threeTier, -5);

        $this->assertCount(1, $segments);
        $this->assertSame(0, $segments[0]['quantity']);
        $this->assertSame(0.0, TieredPricing::total($this->threeTier, -5));
    }
}
