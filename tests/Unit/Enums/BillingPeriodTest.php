<?php

namespace Tests\Unit\Enums;

use App\Enums\BillingPeriod;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BillingPeriodTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cases_are_ordered_by_ascending_cadence(): void
    {
        $values = array_map(fn (BillingPeriod $p) => $p->value, BillingPeriod::cases());

        // Order matters: BillingPeriod::cases() drives the billing-period
        // dropdowns, so weekly -> annually reads shortest to longest.
        $this->assertSame(
            ['weekly', 'monthly', 'bimonthly', 'quarterly', 'semiannual', 'annually'],
            $values,
        );
    }

    public function test_every_case_has_a_human_label(): void
    {
        foreach (BillingPeriod::cases() as $period) {
            $this->assertNotSame('', $period->label());
        }

        $this->assertSame('Weekly', BillingPeriod::Weekly->label());
        $this->assertSame('Bimonthly', BillingPeriod::Bimonthly->label());
        $this->assertSame('Semi-Annually', BillingPeriod::Semiannual->label());
    }

    #[DataProvider('advanceCases')]
    public function test_advance_moves_the_date_by_one_cycle(BillingPeriod $period, string $expected): void
    {
        $start = Carbon::parse('2026-01-15');

        $this->assertSame($expected, $period->advance($start)->toDateString());
    }

    public static function advanceCases(): array
    {
        return [
            'weekly is a 7-day step' => [BillingPeriod::Weekly, '2026-01-22'],
            'monthly' => [BillingPeriod::Monthly, '2026-02-15'],
            'bimonthly is two months' => [BillingPeriod::Bimonthly, '2026-03-15'],
            'quarterly' => [BillingPeriod::Quarterly, '2026-04-15'],
            'semiannual is six months' => [BillingPeriod::Semiannual, '2026-07-15'],
            'annually' => [BillingPeriod::Annually, '2027-01-15'],
        ];
    }

    public function test_advance_does_not_mutate_the_input_date(): void
    {
        $start = Carbon::parse('2026-01-15');

        BillingPeriod::Weekly->advance($start);
        BillingPeriod::Annually->advance($start);

        $this->assertSame('2026-01-15', $start->toDateString());
    }

    #[DataProvider('monthsPerCycleCases')]
    public function test_months_per_cycle_normalises_a_cycle_to_months(BillingPeriod $period, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, $period->monthsPerCycle(), 0.0001);
    }

    public static function monthsPerCycleCases(): array
    {
        return [
            'weekly is ~0.23 of a month' => [BillingPeriod::Weekly, 12 / 52],
            'monthly' => [BillingPeriod::Monthly, 1.0],
            'bimonthly' => [BillingPeriod::Bimonthly, 2.0],
            'quarterly' => [BillingPeriod::Quarterly, 3.0],
            'semiannual' => [BillingPeriod::Semiannual, 6.0],
            'annually' => [BillingPeriod::Annually, 12.0],
        ];
    }

    public function test_months_per_cycle_is_always_positive(): void
    {
        // Guards the MRR rollup (subtotal / monthsPerCycle) and the profile
        // "cycles behind" catch-up loop against a zero divisor for sub-monthly
        // cadences such as Weekly.
        foreach (BillingPeriod::cases() as $period) {
            $this->assertGreaterThan(0, $period->monthsPerCycle(), "{$period->value} must span > 0 months");
        }
    }

    public function test_weekly_mrr_matches_the_weekly_amount_annualised(): void
    {
        // A $10/week profile bills ~52 times a year => ~$43.33/month MRR.
        $mrr = 10 / BillingPeriod::Weekly->monthsPerCycle();

        $this->assertEqualsWithDelta(43.33, $mrr, 0.01);
    }

    public function test_repeated_advance_makes_forward_progress_and_terminates(): void
    {
        // Regression guard for the profile "cycles behind" math, which runs
        // `while ($d->isPast()) { $d = $period->advance($d); }`. A cadence that
        // failed to move the date forward would loop forever; weekly is the
        // tightest step, so exercise it end to end.
        Carbon::setTestNow(Carbon::parse('2026-07-08'));
        $date = Carbon::parse('2020-01-01');

        $cycles = 0;
        while ($date->isPast() && $cycles < 100_000) {
            $next = BillingPeriod::Weekly->advance($date);
            $this->assertTrue($next->greaterThan($date), 'weekly advance must strictly move the date forward');
            $date = $next;
            $cycles++;
        }

        $this->assertFalse($date->isPast(), 'catch-up loop must terminate');
        // 2020-01-01 -> first non-past week is 340 weekly steps ahead of the pinned "now".
        $this->assertSame(340, $cycles);
    }
}
