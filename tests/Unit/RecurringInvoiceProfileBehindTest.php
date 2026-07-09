<?php

namespace Tests\Unit;

use App\Models\RecurringInvoiceProfile;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Unit coverage for the overdue/"behind" helpers on RecurringInvoiceProfile.
 * These back the contract-detail flag (psa-fbyq) and must agree with the
 * profile-detail "Generate Now" affordance: active + a past next run date.
 * The helpers only read cast attributes, so no database is needed.
 */
class RecurringInvoiceProfileBehindTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function profile(array $attributes = []): RecurringInvoiceProfile
    {
        return new RecurringInvoiceProfile(array_merge([
            'name' => 'Managed Services',
            'is_active' => true,
            'billing_period' => 'monthly',
            'billing_day' => 4,
            'payment_terms_days' => 30,
            'next_run_date' => today()->subDay()->toDateString(),
        ], $attributes));
    }

    public function test_active_profile_with_a_past_next_run_is_behind(): void
    {
        $profile = $this->profile(['next_run_date' => today()->subDay()->toDateString()]);

        $this->assertTrue($profile->isBehind());
        // Yesterday, monthly: one period is due (next step lands in the future).
        $this->assertSame(1, $profile->cyclesBehind());
    }

    public function test_future_next_run_is_not_behind(): void
    {
        $profile = $this->profile(['next_run_date' => today()->addDay()->toDateString()]);

        $this->assertFalse($profile->isBehind());
        $this->assertSame(0, $profile->cyclesBehind());
    }

    public function test_inactive_profile_is_never_behind_even_when_overdue(): void
    {
        $profile = $this->profile([
            'is_active' => false,
            'next_run_date' => today()->subMonthsNoOverflow(3)->toDateString(),
        ]);

        $this->assertFalse($profile->isBehind());
        $this->assertSame(0, $profile->cyclesBehind());
    }

    public function test_null_next_run_is_not_behind(): void
    {
        $profile = $this->profile(['next_run_date' => null]);

        $this->assertFalse($profile->isBehind());
        $this->assertSame(0, $profile->cyclesBehind());
    }

    public function test_cycles_behind_counts_each_elapsed_monthly_period(): void
    {
        Carbon::setTestNow('2026-07-09 12:00:00');
        // May 9 → +1mo Jun 9, +1mo Jul 9 (today, due), +1mo Aug 9 (future) = 3.
        $profile = $this->profile(['next_run_date' => '2026-05-09']);

        $this->assertTrue($profile->isBehind());
        $this->assertSame(3, $profile->cyclesBehind());
    }

    public function test_cycles_behind_steps_by_the_billing_period(): void
    {
        Carbon::setTestNow('2026-07-09 12:00:00');
        // Quarterly from Jan 9: +3 Apr 9, +3 Jul 9 (today, due), +3 Oct 9 (future) = 3.
        $profile = $this->profile([
            'billing_period' => 'quarterly',
            'next_run_date' => '2026-01-09',
        ]);

        $this->assertTrue($profile->isBehind());
        $this->assertSame(3, $profile->cyclesBehind());
    }

    public function test_next_run_exactly_today_counts_as_one_cycle_due(): void
    {
        Carbon::setTestNow('2026-07-09 12:00:00');
        // The date cast yields midnight; at noon "today" is already past, so a
        // profile due today reads as one cycle due — matching the profile page.
        $profile = $this->profile(['next_run_date' => '2026-07-09']);

        $this->assertTrue($profile->isBehind());
        $this->assertSame(1, $profile->cyclesBehind());
    }
}
