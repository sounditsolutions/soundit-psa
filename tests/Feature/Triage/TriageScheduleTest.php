<?php

namespace Tests\Feature\Triage;

use App\Models\Setting;
use App\Support\TriageSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * TriageSchedule — the review-pass throttle (psa-lqlu). The trip-critical agent dispatch
 * (triage:review-open) rides this; a sign bug here silently killed the whole agent for
 * 12.7h. These tests pin the sign-safe due-decision.
 */
class TriageScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function enableAutoReview(int $freq = 60): void
    {
        Setting::setValue('triage_enabled', '1');
        Setting::setValue('triage_auto_review', '1');
        Setting::setValue('triage_review_frequency_minutes', (string) $freq);
    }

    public function test_review_pass_is_due_when_last_run_is_older_than_the_frequency(): void
    {
        // THE REGRESSION (must be RED before the fix): a past last-run beyond the frequency
        // must ALLOW a run. Carbon 3's signed diffInMinutes made `negative < freq` always
        // true, so the pass never reran once last-run was set.
        $this->enableAutoReview(60);
        Cache::put('triage:review-open:last-run', now()->subMinutes(82), now()->addHours(24));

        $this->assertTrue(
            TriageSchedule::reviewPassDue(),
            'a last-run 82 min ago (> 60 freq) must be due to run again',
        );
    }

    public function test_review_pass_is_not_due_within_the_frequency(): void
    {
        $this->enableAutoReview(60);
        Cache::put('triage:review-open:last-run', now()->subMinutes(20), now()->addHours(24));

        $this->assertFalse(TriageSchedule::reviewPassDue(), 'a last-run 20 min ago (< 60 freq) is not due');
    }

    public function test_review_pass_is_due_when_it_has_never_run(): void
    {
        $this->enableAutoReview(60);
        Cache::forget('triage:review-open:last-run');

        $this->assertTrue(TriageSchedule::reviewPassDue(), 'a never-run pass is due');
    }

    public function test_review_pass_is_never_due_when_auto_review_is_disabled(): void
    {
        // triage_auto_review off → never due, even with a long-stale last-run.
        Setting::setValue('triage_enabled', '1');
        Setting::setValue('triage_auto_review', '0');
        Cache::put('triage:review-open:last-run', now()->subMinutes(999), now()->addHours(24));

        $this->assertFalse(TriageSchedule::reviewPassDue());
    }

    public function test_marking_a_run_records_now_and_makes_it_not_due(): void
    {
        $this->enableAutoReview(60);
        TriageSchedule::markReviewPassRun();

        $this->assertNotNull(TriageSchedule::lastRun());
        $this->assertFalse(TriageSchedule::reviewPassDue(), 'a just-marked run is not immediately due again');
    }

    public function test_a_run_becomes_due_again_after_the_frequency_elapses(): void
    {
        // The core liveness property the regression broke: after marking a run, once the
        // frequency has passed it must become due again (so the pass keeps firing).
        $this->enableAutoReview(60);
        TriageSchedule::markReviewPassRun();
        $this->assertFalse(TriageSchedule::reviewPassDue());

        $this->travel(61)->minutes();

        $this->assertTrue(TriageSchedule::reviewPassDue(), 'after 61 min (> 60 freq) the pass must be due again');
    }

    public function test_a_negative_frequency_is_floored_to_a_safe_positive(): void
    {
        // Defense-in-depth (psa-lqlu): a negative freq must not make the throttle window
        // non-positive (which would flood the pass / the staleness alarm's TTL).
        Setting::setValue('triage_review_frequency_minutes', '-5');

        $this->assertGreaterThanOrEqual(1, \App\Support\TriageConfig::reviewFrequencyMinutes());
    }
}
