<?php

namespace App\Support;

/**
 * Graduated ("tax-bracket") tier pricing math.
 *
 * A tier list prices a quantity in bands: e.g. the first 10 units at $X, the
 * next 20 at $Y, and everything above at $Z. Each tier is
 * `['up_to' => int|null, 'unit_price' => float]` where `up_to` is the
 * cumulative upper bound of that band. Tiers are ordered ascending and the
 * final tier is always unbounded — it absorbs every remaining unit.
 *
 * Pure and stateless so the billing math is trivially unit-testable without a
 * database. {@see \App\Services\BillingService} expands each consumed band into
 * its own invoice line so the invariant `amount = round(quantity × unit_price)`
 * holds for every line (Stripe/QBO push depend on it).
 *
 * ────────────────────────────────────────────────────────────────────────────
 * NOT the same as {@see \App\Models\Sku::priceForStorageGb()}, despite the
 * near-identical shape (both are ascending `up_to` + `unit_price` lists ending
 * in an unbounded band). That one is a VOLUME rate card: it picks the single
 * tier covering the measured quantity and bills the *whole* quantity at that
 * one rate. This one is GRADUATED: every band bills at its own rate. Same
 * numbers, different money. The two are deliberately kept apart — see
 * BillingService::priceLineSegments() for which wins when a line has both.
 * ────────────────────────────────────────────────────────────────────────────
 */
class TieredPricing
{
    /**
     * Sanitise and order a raw tier list (as submitted from a form or stored
     * JSON). Drops tiers without a numeric unit_price, coerces `up_to` to a
     * positive integer (or null = unbounded), sorts ascending with the
     * unbounded tier last, and forces the final tier to be unbounded.
     *
     * @param  array<int, mixed>  $tiers
     * @return array<int, array{up_to: int|null, unit_price: float}>
     */
    public static function normalize(array $tiers): array
    {
        $clean = [];

        foreach ($tiers as $tier) {
            if (! is_array($tier)) {
                continue;
            }

            $price = $tier['unit_price'] ?? null;
            if ($price === null || $price === '' || ! is_numeric($price)) {
                continue;
            }

            $upTo = $tier['up_to'] ?? null;
            if ($upTo === null || $upTo === '') {
                $upTo = null;
            } elseif (is_numeric($upTo) && (int) $upTo >= 1) {
                $upTo = (int) $upTo;
            } else {
                // A non-empty but invalid bound (0, negative, non-numeric) is
                // discarded — the tier is unusable.
                continue;
            }

            $clean[] = ['up_to' => $upTo, 'unit_price' => round((float) $price, 2)];
        }

        if (empty($clean)) {
            return [];
        }

        // Ascending by bound; unbounded tiers sink to the end.
        usort($clean, function (array $a, array $b): int {
            if ($a['up_to'] === $b['up_to']) {
                return 0;
            }
            if ($a['up_to'] === null) {
                return 1;
            }
            if ($b['up_to'] === null) {
                return -1;
            }

            return $a['up_to'] <=> $b['up_to'];
        });

        // The final band always extends to infinity — its stored bound (if any)
        // is meaningless as a ceiling.
        $clean[array_key_last($clean)]['up_to'] = null;

        return array_values($clean);
    }

    /**
     * Break an integer quantity into graduated segments.
     *
     * Returns one segment per band that consumed at least one unit. A
     * zero/negative quantity yields a single zero-unit segment priced at the
     * first tier (so callers can still emit a "record of coverage" line).
     * Returns an empty array when there are no valid tiers (caller should fall
     * back to flat pricing).
     *
     * @param  array<int, mixed>  $tiers
     * @return array<int, array{quantity: int, unit_price: float, from: int, to: int}>
     */
    public static function breakdown(array $tiers, int $quantity): array
    {
        $tiers = self::normalize($tiers);

        if (empty($tiers)) {
            return [];
        }

        if ($quantity <= 0) {
            return [[
                'quantity' => 0,
                'unit_price' => (float) $tiers[0]['unit_price'],
                'from' => 0,
                'to' => 0,
            ]];
        }

        $segments = [];
        $prev = 0; // cumulative units already allocated
        $lastIndex = array_key_last($tiers);

        foreach ($tiers as $i => $tier) {
            if ($prev >= $quantity) {
                break;
            }

            $unbounded = $i === $lastIndex || $tier['up_to'] === null;
            $top = $unbounded ? $quantity : min($quantity, (int) $tier['up_to']);

            $units = $top - $prev;
            if ($units > 0) {
                $segments[] = [
                    'quantity' => $units,
                    'unit_price' => (float) $tier['unit_price'],
                    'from' => $prev + 1,
                    'to' => $top,
                ];
                $prev = $top;
            }
        }

        // Defensive: if finite bounds failed to cover the quantity, the last
        // tier's price absorbs the remainder. (normalize() makes the last tier
        // unbounded, so this only triggers on pathological input.)
        if ($prev < $quantity) {
            $segments[] = [
                'quantity' => $quantity - $prev,
                'unit_price' => (float) $tiers[$lastIndex]['unit_price'],
                'from' => $prev + 1,
                'to' => $quantity,
            ];
        }

        return $segments;
    }

    /**
     * Total price for a quantity under graduated tiers.
     *
     * @param  array<int, mixed>  $tiers
     */
    public static function total(array $tiers, int $quantity): float
    {
        $sum = 0.0;

        foreach (self::breakdown($tiers, $quantity) as $segment) {
            $sum += $segment['quantity'] * $segment['unit_price'];
        }

        return round($sum, 2);
    }
}
