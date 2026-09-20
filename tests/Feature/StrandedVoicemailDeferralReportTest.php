<?php

namespace Tests\Feature;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\NotificationEventType;
use App\Models\PhoneCall;
use App\Models\Setting;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * Card 6aafb4ab — the notify guard (PR #2880) withholds a voicemail email until
 * end evidence arrives, and if it never arrives nothing surfaced the withheld
 * row. This is the gauge that surfaces it.
 *
 * WHAT THESE CONTROLS PIN: that an unreleased marker older than the threshold
 * is counted, that a released one and a fresh one are not, that a row already
 * emailed about is not, and that the command writes nothing back to a call.
 *
 * WHAT THEY DO NOT PIN, said plainly: whether the threshold of 60 minutes is
 * the right operational line. That is a reporting choice, and no test can tell
 * a well-chosen threshold from a badly-chosen one.
 */
class StrandedVoicemailDeferralReportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Deliberately uses array_key_exists rather than `??` for the nullable
     * columns: `??` coalesces an explicitly-passed null, so a test wanting a
     * row with NO deferral marker would silently get the default instead and
     * pass for the wrong reason. That trap is GitHub #2883, found in the
     * sibling suite; it is not being reproduced here.
     */
    public function test_the_log_record_carries_the_count_and_the_call_ids(): void
    {
        // THE LOG LINE IS THE PRODUCTION OUTPUT. The schedule entry uses
        // runInBackground() with no redirection, so every expectsOutputToContain
        // assertion in this file exercises a path that does not run in
        // production. Deleting Log::warning entirely left all 8 original
        // controls green -- measured, which is why this control exists.
        Log::spy();

        $a = $this->deferredCall();
        $b = $this->deferredCall();

        $this->artisan('calls:report-stranded-voicemail-deferrals')->assertExitCode(0);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($a, $b) {
                return $message === '[Voicemail] Stranded notification deferrals outstanding'
                    && $context['count'] === 2
                    && in_array($a->id, $context['call_ids'], true)
                    && in_array($b->id, $context['call_ids'], true)
                    && $context['call_ids_truncated'] === false;
            })
            ->once();
    }

    public function test_nothing_is_logged_when_no_deferral_is_outstanding(): void
    {
        // Positive control against the one above: if the warning fired
        // unconditionally, the control above could not fail.
        Log::spy();

        $this->deferredCall(['deferred_at' => null]);

        $this->artisan('calls:report-stranded-voicemail-deferrals')->assertExitCode(0);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_non_numeric_minutes_option_is_refused(): void
    {
        // (int) 'soon' is 0, a valid-looking zero-minute threshold that would
        // report every in-flight marker as a fault. Shape is checked before
        // value so a typo refuses rather than silently widening the gauge.
        $this->deferredCall(['deferred_at' => now()->subSeconds(5)]);

        $this->artisan('calls:report-stranded-voicemail-deferrals', ['--minutes' => 'soon'])
            ->expectsOutputToContain('--minutes must be a whole number of minutes.')
            ->assertExitCode(1);
    }

    public function test_the_table_is_capped_and_says_so(): void
    {
        // The cap is a real bound on the listing; an operator must not read 20
        // rows as the whole set.
        for ($i = 0; $i < 22; $i++) {
            $this->deferredCall();
        }

        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->expectsOutputToContain('22 voicemail notification(s)')
            ->expectsOutputToContain('Showing the 20 oldest of 22')
            ->assertExitCode(0);
    }

    public function test_the_fixture_helper_refuses_an_unknown_override_key(): void
    {
        // Pins the guard above: a control that silently built a default row
        // would be green for the wrong reason.
        // NOT wrapped in a try that catches AssertionFailedError: $this->fail()
        // throws that exact type, so a catch-all would swallow its own
        // diagnostic and report the guard as present when it had been removed.
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('notifed_at');

        $this->deferredCall(['notifed_at' => now()]);
    }

    public function test_reported_timestamps_are_converted_to_the_app_timezone(): void
    {
        // C-14: stored UTC, displayed via toAppTz(). An unlabelled UTC time in
        // a report read by a human deciding which call to listen to is an
        // hours-wrong answer to "how long has this been sitting?".
        Setting::setValue('app_timezone', 'America/Los_Angeles');

        $deferred = CarbonImmutable::parse('2026-09-20 02:30:00', 'UTC');
        $this->deferredCall(['deferred_at' => $deferred]);

        // The zone marker is asserted, not just the converted digits: a
        // converted time with no marker is the same "hours-wrong answer"
        // trap one step further on, and without this the abbreviation could
        // be dropped with every test still green (measured: mutant M4).
        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->expectsOutputToContain('2026-09-19 19:30:00 PDT')
            ->assertExitCode(0);
    }

    public function test_the_logged_oldest_is_also_converted(): void
    {
        Setting::setValue('app_timezone', 'America/Los_Angeles');
        Log::spy();

        $this->deferredCall(['deferred_at' => CarbonImmutable::parse('2026-09-20 02:30:00', 'UTC')]);

        $this->artisan('calls:report-stranded-voicemail-deferrals')->assertExitCode(0);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m, array $c) => str_contains($c['oldest_deferred_at'], '2026-09-19 19:30:00 PDT'))
            ->once();
    }

    public function test_the_count_and_the_listed_rows_come_from_one_read(): void
    {
        // The previous revision read the count and the rows as TWO queries
        // while a comment claimed one read. A release landing between them
        // could yield a non-zero count with an empty row set and print
        // "Oldest outstanding since ." with nothing after it. Under the cap
        // the count is now derived from the rows themselves, so exactly one
        // query decides both.
        $this->deferredCall();
        $this->deferredCall();

        // Listener registered AFTER the fixtures: their INSERT/UPDATE
        // statements also mention the marker column and would otherwise be
        // counted as reads.
        $reads = [];
        DB::listen(function ($q) use (&$reads) {
            if (str_starts_with(strtolower(ltrim($q->sql)), 'select')
                && str_contains($q->sql, 'voicemail_notify_deferred_at')) {
                $reads[] = $q->sql;
            }
        });

        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->expectsOutputToContain('2 voicemail notification(s)')
            ->assertExitCode(0);

        $this->assertCount(1, $reads, 'the gauge must read the stranded rows exactly once when under the cap; got: '.implode(' | ', $reads));
        $this->assertStringNotContainsStringIgnoringCase('count(', $reads[0] ?? '', 'the total must be derived from the rows read, not a second aggregate query');
    }

    public function test_an_oldest_is_never_reported_empty_when_a_count_is_reported(): void
    {
        // The defect the "one read" claim was supposed to close, pinned
        // directly rather than by proxy.
        $this->deferredCall();

        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->doesntExpectOutputToContain('Oldest outstanding since .')
            ->assertExitCode(0);
    }

    public function test_the_total_is_not_bounded_by_the_cap_when_the_listing_truncates(): void
    {
        // The cap bounds the LISTING; it must never bound the reported total.
        // Named as a bound rather than as exactness: above the cap the total is
        // a second read floored at the rows listed, and the control below is
        // the one that pins what happens when those two reads disagree.
        for ($i = 0; $i < 23; $i++) {
            $this->deferredCall();
        }

        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->expectsOutputToContain('23 voicemail notification(s)')
            ->expectsOutputToContain('Showing the 20 oldest of 23')
            ->assertExitCode(0);
    }

    public function test_the_reported_total_is_never_smaller_than_the_rows_listed(): void
    {
        // The truncated branch takes its total from a SECOND query that runs
        // after the listing read, so a burst of releases in between produced
        // "Showing the 20 oldest of 5" -- a report contradicting itself -- and a
        // log record whose count was smaller than its own 20-id list.
        //
        // The race is FORCED, not waited for: DB::listen fires the instant the
        // listing read completes, which is exactly the window the count query
        // sits in, so releasing 16 of the 21 rows there is the real interleaving
        // rather than a simulation of it. Without the floor the count query
        // returns 5 and both assertions below fail.
        Log::spy();

        for ($i = 0; $i < 21; $i++) {
            $this->deferredCall();
        }

        $released = false;
        DB::listen(function ($q) use (&$released) {
            if ($released
                || ! str_starts_with(strtolower(ltrim($q->sql)), 'select')
                || ! str_contains($q->sql, 'voicemail_notify_deferred_at')) {
                return;
            }

            // Set before the queries below so this listener cannot re-enter on
            // its own reads.
            $released = true;

            $ids = PhoneCall::query()
                ->whereNotNull('voicemail_notify_deferred_at')
                ->orderBy('id')
                ->take(16)
                ->pluck('id');

            // What a release leaves behind: marker cleared, send claimed.
            PhoneCall::whereIn('id', $ids)->update([
                'voicemail_notify_deferred_at' => null,
                'voicemail_notified_at' => now(),
            ]);
        });

        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->expectsOutputToContain('21 voicemail notification(s)')
            ->expectsOutputToContain('Showing the 20 oldest of 21')
            ->assertExitCode(0);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m, array $c) => $c['count'] >= count($c['call_ids'])
                && $c['call_ids_truncated'] === true)
            ->once();
    }

    public function test_an_absurdly_large_minutes_value_is_refused(): void
    {
        // Measured at this tip: subMinutes(999999999999) gives year -1899298
        // and subMinutes(PHP_INT_MAX) WRAPS back to now, reporting nothing
        // while looking healthy. A silent wrong answer is the worst outcome
        // for a gauge, so the upper end is refused.
        $this->artisan('calls:report-stranded-voicemail-deferrals', ['--minutes' => '999999999999'])
            ->expectsOutputToContain('--minutes must not exceed')
            ->assertExitCode(1);
    }

    public function test_php_int_max_minutes_is_refused_rather_than_wrapping(): void
    {
        $this->deferredCall();

        // Without the ceiling this WRAPS to the present and reports zero
        // stranded rows -- green, silent, and wrong.
        $this->artisan('calls:report-stranded-voicemail-deferrals', ['--minutes' => (string) PHP_INT_MAX])
            ->expectsOutputToContain('--minutes must not exceed')
            ->assertExitCode(1);
    }

    public function test_the_largest_accepted_threshold_still_works(): void
    {
        // Positive control for the ceiling: the boundary value itself must be
        // accepted and must actually run, or the guard is just an off-by-one.
        $this->deferredCall();

        $this->artisan('calls:report-stranded-voicemail-deferrals', ['--minutes' => '5256000'])
            ->expectsOutputToContain('No voicemail deferral has been outstanding')
            ->assertExitCode(0);
    }

    public function test_the_truncation_flag_is_true_in_the_log_when_capped(): void
    {
        // The flag's TRUE branch was pinned by nothing: hard-coding it false
        // killed no test, because the only control reading the log used 2 rows.
        Log::spy();

        for ($i = 0; $i < 22; $i++) {
            $this->deferredCall();
        }

        $this->artisan('calls:report-stranded-voicemail-deferrals')->assertExitCode(0);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m, array $c) => $c['call_ids_truncated'] === true
                && $c['count'] === 22
                && count($c['call_ids']) === 20)
            ->once();
    }

    public function test_an_unmapped_status_does_not_kill_the_report(): void
    {
        // My earlier comment and this test's former name both said the cast
        // throws "at hydration, before this command formats anything".
        // MEASURED and FALSE (round 3 diff:3): Eloquent casts lazily in
        // getAttributeValue(), so newFromBuilder() returns without throwing
        // and the ValueError fires on attribute ACCESS -- inside the table
        // map, which runs AFTER Log::warning has already recorded the count.
        //
        // So the old behaviour logged the warning and then died, every hour,
        // on a row that sorts to the front of the sample forever (context:3).
        // The count must stay truthful and the row must stay visible.
        $call = $this->deferredCall();

        DB::table('phone_calls')->where('id', $call->id)->update(['status' => 'legacy_unmapped']);

        Log::spy();

        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->expectsOutputToContain('unmapped:legacy_unmapped')
            ->assertExitCode(0);

        // The gauge still counted it: degrading the LABEL must not silently
        // drop the row from the number an operator acts on.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m, array $c) => $c['count'] === 1
                && $c['call_ids'] === [$call->id])
            ->once();
    }

    public function test_the_raw_status_is_still_named_so_the_bad_row_can_be_found(): void
    {
        // A placeholder that hid WHICH value was unmappable would trade a
        // hard failure for an unactionable one.
        $call = $this->deferredCall();
        DB::table('phone_calls')->where('id', $call->id)->update(['status' => 'weird_legacy']);

        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->expectsOutputToContain('weird_legacy')
            ->assertExitCode(0);
    }

    public function test_the_real_guard_marks_and_releases_the_columns_the_gauge_reads(): void
    {
        // Round 2 diff:12, upheld: every other control in this file models the
        // column states by hand. If NotificationService ever writes a shape
        // this fixture does not, the whole suite passes while the gauge is
        // blind. This control drives the REAL service so the model is
        // derived, not asserted.
        $service = app(NotificationService::class);

        // Built through the same helper, then reset to a genuinely UNMARKED
        // row so the guard itself does the first write.
        $call = $this->deferredCall(['deferred_at' => null]);

        $service->notifyNewVoicemail($call);
        $call->refresh();

        // The marker the gauge selects on is what the guard actually writes.
        $this->assertNotNull($call->voicemail_notify_deferred_at);
        $this->assertNull($call->voicemail_notified_at);

        // Round 2 diff:4 claimed a re-stamp resets the age clock. REFUTED at
        // source and now by execution: the marking UPDATE is guarded by
        // whereNull on the same column, so a second entrance cannot re-stamp
        // an already-marked row.
        $firstMark = $call->voicemail_notify_deferred_at;
        $this->travel(90)->minutes();
        $service->notifyNewVoicemail($call);
        $call->refresh();
        $this->assertEquals(
            $firstMark->timestamp,
            $call->voicemail_notify_deferred_at->timestamp,
            'A second deferral re-stamped the marker and reset the gauge age clock.'
        );
        $this->travelBack();

        // And the release clears the marker in the SAME statement that claims
        // the send -- the docblock's central argument, now executed.
        $call->ended_at = now();
        $call->save();
        $service->releaseDeferredVoicemailNotification($call);
        $call->refresh();

        $this->assertNull($call->voicemail_notify_deferred_at);
        $this->assertNotNull($call->voicemail_notified_at);

        // A row in that state is exactly what the gauge must NOT report.
        //
        // Round 3 c1:v1:1, UPHELD against me and proven by execution: the
        // marker here was written at the current test clock (travelBack()
        // undid the 90-minute jump), so at ~0 minutes old it fell below the
        // 60-minute threshold and this block passed with the release DELETED
        // ENTIRELY -- marker still set, send never claimed. It asserted
        // nothing and its comment said it did. The gauge must be asked about
        // a row old enough to be reported, or the answer is free.
        $this->travel(120)->minutes();

        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->expectsOutputToContain('No voicemail deferral has been outstanding')
            ->assertExitCode(0);

        $this->travelBack();
    }

    public function test_the_listed_rows_are_stable_when_markers_share_a_timestamp(): void
    {
        // Round 2 context:13: the cap control creates 22 rows all carrying the
        // same marker and asserts only counts, so it passes under ANY
        // ordering. With no tiebreak "the 20 oldest" is engine-dependent and
        // two runs a second apart can name different calls.
        //
        // Asserted on the LOG record, not stdout: the schedule entry is
        // runInBackground() with no redirection, so stdout is discarded in
        // production and an assertion there tests a surface nobody reads.
        //
        // HONEST LIMIT, measured: removing the ->orderBy('id') tiebreak does
        // NOT fail this control, because SQLite returns rows in rowid order
        // for this query and rowid tracks insertion here. The mutant SURVIVES.
        // No fixture can kill it on SQLite -- the defect is a MariaDB/engine
        // property, and prod is MariaDB. The tiebreak is kept as a correctness
        // guarantee this suite cannot pin; do not read this test as proof of
        // it. Disclosed rather than dressed up with an assertion that only
        // looks like coverage.
        Log::spy();

        $shared = now()->subHours(2);
        $ids = [];
        for ($i = 0; $i < 22; $i++) {
            $ids[] = $this->deferredCall(['deferred_at' => $shared])->id;
        }
        sort($ids);
        $expected = array_slice($ids, 0, 20);

        $this->artisan('calls:report-stranded-voicemail-deferrals')->assertExitCode(0);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m, array $c) => $c['call_ids'] === $expected
                && $c['call_ids_truncated'] === true
                && $c['count'] === 22)
            ->once();
    }

    private function deferredCall(array $overrides = []): PhoneCall
    {
        // A mistyped override key must not silently yield the default row:
        // that is #2883's "passes for the wrong reason" hazard relocated from
        // ?? to key names, and a control that builds the wrong fixture proves
        // nothing while looking green.
        $unknown = array_diff(array_keys($overrides), ['deferred_at', 'notified_at', 'ended_at']);
        if ($unknown !== []) {
            $this->fail('deferredCall() got unknown override key(s): '.implode(', ', $unknown));
        }

        $call = PhoneCall::create([
            'call_uuid' => 'vm-'.uniqid(),
            'direction' => CallDirection::Inbound,
            'from_number' => '+12065550101',
            'to_number' => '+12065550199',
            'status' => CallStatus::Voicemail,
            'started_at' => now()->subHours(3),
        ]);

        $call->voicemail_notify_deferred_at = array_key_exists('deferred_at', $overrides)
            ? $overrides['deferred_at']
            : now()->subHours(2);
        $call->voicemail_notified_at = array_key_exists('notified_at', $overrides)
            ? $overrides['notified_at']
            : null;
        $call->ended_at = array_key_exists('ended_at', $overrides)
            ? $overrides['ended_at']
            : null;
        $call->save();

        return $call->refresh();
    }

    public function test_an_unreleased_deferral_past_the_threshold_is_reported(): void
    {
        $call = $this->deferredCall();

        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->expectsOutputToContain('1 voicemail notification(s) withheld')
            ->assertExitCode(0);

        // The gauge writes nothing back to the call it reports.
        $this->assertNotNull($call->refresh()->voicemail_notify_deferred_at);
        $this->assertNull($call->refresh()->voicemail_notified_at);
    }

    public function test_a_released_deferral_is_not_reported(): void
    {
        // What a release leaves behind: marker cleared, send claimed.
        $this->deferredCall(['deferred_at' => null, 'notified_at' => now()->subHour(), 'ended_at' => now()->subHour()]);

        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->expectsOutputToContain('No voicemail deferral has been outstanding')
            ->assertExitCode(0);
    }

    public function test_a_deferral_younger_than_the_threshold_is_not_reported(): void
    {
        // The ordinary case: deferred moments ago, release still in flight.
        $this->deferredCall(['deferred_at' => now()->subMinute()]);

        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->expectsOutputToContain('No voicemail deferral has been outstanding')
            ->assertExitCode(0);
    }

    /**
     * The disagreement case that justifies testing BOTH columns. A marker can
     * sit beside a set voicemail_notified_at when one entrance marked a row
     * whose send another entrance had already claimed. That row has been
     * emailed about, so it is not stranded — and a gauge keying on the marker
     * alone would report it.
     */
    public function test_a_row_already_emailed_about_is_not_reported_even_with_a_marker(): void
    {
        $this->deferredCall(['deferred_at' => now()->subHours(2), 'notified_at' => now()->subHours(2)]);

        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->expectsOutputToContain('No voicemail deferral has been outstanding')
            ->assertExitCode(0);
    }

    public function test_the_threshold_is_overridable(): void
    {
        $this->deferredCall(['deferred_at' => now()->subMinutes(30)]);

        // Outside a 60-minute window...
        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->expectsOutputToContain('No voicemail deferral has been outstanding')
            ->assertExitCode(0);

        // ...inside a 10-minute one.
        $this->artisan('calls:report-stranded-voicemail-deferrals', ['--minutes' => 10])
            ->expectsOutputToContain('1 voicemail notification(s) withheld')
            ->assertExitCode(0);
    }

    public function test_a_negative_threshold_is_refused(): void
    {
        $this->artisan('calls:report-stranded-voicemail-deferrals', ['--minutes' => -5])
            ->expectsOutputToContain('--minutes must be at least 1')
            ->assertExitCode(1);
    }

    public function test_a_zero_threshold_is_refused(): void
    {
        // Round 3 diff:4: the shape check refuses 'soon' BECAUSE (int) 'soon'
        // is 0, yet an explicit --minutes=0 walked through both gates and set
        // the cutoff to now(), reporting in-flight deferrals as faults. The
        // boundary between the refused and accepted ranges had no control at
        // all; 0 and 1 are both pinned now.
        $this->artisan('calls:report-stranded-voicemail-deferrals', ['--minutes' => 0])
            ->expectsOutputToContain('--minutes must be at least 1')
            ->assertExitCode(1);
    }

    public function test_a_one_minute_threshold_is_accepted(): void
    {
        $this->deferredCall(['deferred_at' => now()->subMinutes(5)]);

        $this->artisan('calls:report-stranded-voicemail-deferrals', ['--minutes' => 1])
            ->expectsOutputToContain('1 voicemail notification(s)')
            ->assertExitCode(0);
    }

    /**
     * A row that never carried a marker is invisible to this gauge. Pinned so
     * the limit is a control rather than a sentence in a docblock: this is the
     * pre-existing pool on card 6aade104, and a zero here does not speak for it.
     */
    public function test_a_row_that_was_never_marked_is_not_reported(): void
    {
        $this->deferredCall(['deferred_at' => null]);

        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->expectsOutputToContain('No voicemail deferral has been outstanding')
            ->assertExitCode(0);
    }

    public function test_the_count_and_oldest_reflect_several_stranded_rows(): void
    {
        $this->deferredCall(['deferred_at' => now()->subHours(5)]);
        $this->deferredCall(['deferred_at' => now()->subHours(2)]);
        $this->deferredCall(['deferred_at' => now()->subMinute()]); // in flight, excluded

        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->expectsOutputToContain('2 voicemail notification(s) withheld')
            ->assertExitCode(0);
    }

    public function test_the_command_writes_nothing_to_any_call(): void
    {
        // Round 3 contract:5, upheld: the docblock asserts in capitals that
        // THIS COMMAND WRITES NOTHING TO A CALL -- the card's central safety
        // property, the whole reason a gauge was built instead of a resend --
        // and no control pinned it. A gauge that quietly remediates is the one
        // failure this leg exists to prevent, and "I read the code and saw no
        // update()" is not a control.
        //
        // Snapshot every column of every row, run the command over a
        // population it will actually report on, and require byte-identity.
        // The mutation that makes this fail is any write at all: releasing a
        // marker, claiming a send, or stamping a row as seen.
        $older = $this->deferredCall(['deferred_at' => now()->subMinutes(180)]);
        $newer = $this->deferredCall(['deferred_at' => now()->subMinutes(120)]);
        $this->deferredCall(['deferred_at' => null]);

        // Round 2 context:1, and the fixture is PART OF THE CONTROL. Every
        // send-shaped mutant this guard exists to kill has to get past two
        // early returns before it can reach a transport, and an empty fixture
        // fails both of them:
        //
        //   NotificationService::dispatchVoicemailNotification() iterates
        //   User::where('is_active', true)->whereNotNull('email')->get() --
        //   an empty collection means the foreach body never runs.
        //   SendTicketNotification::handle() returns on a missing recipient
        //   (User::find(...) / no email) and returns AGAIN on a missing
        //   graph_mailbox, logging '[Notification] Email not configured'.
        //
        // Without a recipient and a mailbox the raiser below is unreachable
        // and this control is green by construction -- the exact false-green
        // that made a round-1 dispatch_sync mutant look like a gap when it had
        // in fact never dispatched, and the reason two mutants this round were
        // reported NOT SCORED and re-aimed. The kill evidence was measured
        // against a fixture with a recipient and a mailbox, so the committed
        // fixture has to be that one.
        //
        // notification_preferences is left null deliberately:
        // User::wantsNotification() then takes NotificationEventType's enabled
        // default, which is the shape a real operator account has.
        $recipient = User::factory()->create([
            'email' => 'oncall@example.test',
            'is_active' => true,
            'notification_preferences' => null,
        ]);
        Setting::setValue('graph_mailbox', 'helpdesk@example.test');

        // Round 2 c1:v3-replacement:1. Leaving preferences null buys realism
        // at the cost of resting this control's reachability on an UNPINNED
        // production default: NotificationEventType::defaultEnabled() is a
        // hardcoded `return true`, and nothing else in this file asserts it.
        // MEASURED, not argued -- flipping that one production line to
        // `return false` leaves this whole control PASSING (9 assertions,
        // green) while the Graph raiser below has become unreachable again.
        // That is precisely the green-by-construction failure the round-2
        // rework was written to close, restored through a back door.
        //
        // So the precondition is asserted rather than assumed. This is a
        // statement about the fixture, not about product policy: if the
        // default is ever deliberately flipped, this line fails loudly and
        // whoever flips it has to give the fixture an explicit preference,
        // instead of the guard quietly going hollow.
        $this->assertTrue(
            $recipient->wantsNotification(NotificationEventType::NewVoicemail),
            'The fixture recipient does not want the notification, so no send-shaped '
            .'mutant can reach the Graph raiser and this control is green by construction.'
        );

        // The two marked rows are the stranded set by construction: both are
        // older than the default threshold and the third row carries no marker
        // at all. The ids come from the rows the helper returned -- the idiom
        // the sibling control at the top of this file already uses.
        //
        // The previous revision read them back with
        // DB::table('phone_calls')->whereNotNull('deferred_at'), and there is
        // no deferred_at column: 'deferred_at' is only an override key of
        // deferredCall(), which writes voicemail_notify_deferred_at. Under
        // SQLite that did not even error -- an unresolvable double-quoted
        // identifier degrades to a string literal, so the predicate read
        // 'deferred_at' IS NOT NULL, was always true, and the expectation
        // became every row in the table including the unmarked one. The
        // precondition below then matched nothing and this whole control
        // errored, so the model layer's own ids are used instead of a column
        // name a typo can silently reinterpret.
        $strandedIds = [$older->id, $newer->id];
        sort($strandedIds);

        $snapshot = fn () => DB::table('phone_calls')
            ->orderBy('id')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();

        $before = $snapshot();

        // Round 5 contract:6 + context:5, upheld: the precondition used to
        // assert on stdout -- the surface this same file documents at lines
        // 43-47 as discarded by runInBackground(), and the surface a sibling
        // control was already re-aimed off. It fired in-test, so the control
        // was not hollow, but it was coupled to the one output this leg has
        // decided is deletable. The log record is the production surface and
        // it carries call_ids, so the precondition now pins WHICH rows the
        // command held -- exactly the set a remediating mutant would act on --
        // rather than only how many.
        Log::spy();

        // Round 5 contract:1, upheld and PROVEN BY MUTATION: byte-identity on
        // phone_calls covers two of the three prohibited acts. A resend leaves
        // no row behind -- dispatchVoicemailNotification() (NotificationService
        // :564-590) only dispatches SendTicketNotification per user, and the
        // only phone_calls write on that path is the separate claim in
        // sendVoicemailNotificationOnce() (:542-545), which a mutant need not
        // perform. A mutant dispatching one job per stranded row and writing
        // nothing passed this control green. QUEUE_CONNECTION=sync and
        // MAIL_MAILER=array meant it was swallowed silently.
        //
        // #2935 (r6 diff:5), MEASURED RATHER THAN ACCEPTED. The finding says
        // Queue::assertNothingPushed() misses dispatch_sync()/dispatchNow()
        // as well as the Mail:: and Notification:: transports. Half of that
        // is FALSE at laravel/framework 12.69.2 and half is TRUE, and only
        // mutation separated them:
        //
        //   dispatch_sync(new SendTicketNotification(...))  -> KILLED
        //       QueueFake routes the sync dispatch through its own record,
        //       so assertNothingPushed() names the job.
        //   Dispatcher::dispatchNow(...)                    -> SURVIVED
        //       Round 1 diff:2 caught this against an earlier version of
        //       THIS COMMENT, which named dispatchNow() in the same breath
        //       as dispatch_sync() and declared both closed on one measured
        //       kill. dispatchNow() runs the handler through the pipeline
        //       inline and never touches the queue resolver, so QueueFake
        //       records nothing; the mutant was instrumented and fired
        //       twice while the control stayed green. Two verbs that look
        //       like synonyms are not: one is closed, one was wide open.
        //
        //       Chasing that mutant found the bigger hole. The production
        //       notification job does not send through the mail transport
        //       at all: SendTicketNotification::handle() calls EmailService,
        //       which posts users/{mailbox}/sendMail through GraphClient --
        //       and GraphClient uses RAW GUZZLE, so neither Mail::, nor
        //       Notification::, nor even Http::fake() can observe it. Run
        //       inline with a real recipient it reaches a live outbound
        //       request, stopped today only by the HTTP_PROXY pin at
        //       phpunit.xml:22-23 -- a network accident, not an assertion.
        //       So the Graph client is bound to a raiser below: the one
        //       transport that actually carries production notifications is
        //       the one a writes-nothing guard most needs to watch.
        //   Notification::route('mail',...)->notify(...)    -> SURVIVED
        //   Mail::to(...)->send(new Mailable)               -> SURVIVED
        //   Mail::raw(...) / Mail::send('view', ...)        -> SURVIVED
        //
        // Notification:: is closed by faking that transport. Mail:: is NOT,
        // and Mail::fake() actively makes it worse -- measured, not assumed:
        //
        //   Mail::raw() with Mail::fake()     -> array transport count 0
        //   Mail::raw() without Mail::fake()  -> array transport count 1
        //
        // MailFake records MAILABLES only; its raw() and send() are empty
        // stubs (MailFake.php:473+), so assertNothingSent() inspects a
        // collection those verbs never populate AND the message never
        // reaches a transport anyone can inspect. Mailer::raw()
        // (Mailer.php:221) really does send. Faking mail here would hide
        // exactly the shape that escapes.
        //
        // Round 1 context:1 (escalated as diff:5): asserting on the mailer
        // NAMED 'array' while the command sends through the DEFAULT mailer
        // ties the two together only via phpunit.xml, a file this test never
        // reads -- and phpunit.xml:32 sets MAIL_MAILER WITHOUT force="true"
        // (contrast :22-23, which use it), so a MAIL_MAILER already in the
        // process environment silently wins. Measured:
        //
        //   default env        -> mail.default=array, named count after send 1
        //   MAIL_MAILER=log    -> mail.default=log,   named count after send 0
        //
        // config/mail.php:78 always defines an 'array' mailer, so the
        // instrument keeps returning a valid, EMPTY transport and the guard
        // passes while real mail leaves by another route. Silent, not loud,
        // which is the worst shape for a safety control. So assert the
        // binding instead of trusting it, and inspect the DEFAULT mailer
        // rather than one addressed by name.
        //
        // So Mail is deliberately NOT faked. Under MAIL_MAILER=array the
        // real mailer delivers into ArrayTransport, which keeps every
        // message whatever verb produced it -- an assertion about what LEFT
        // rather than about which API was called. Nothing reaches a network:
        // phpunit.xml pins MAIL_MAILER=array for the whole suite.
        Queue::fake();
        Notification::fake();

        // The real notification transport. Binding it to a double turns any
        // send attempt into an observable event instead of an outbound
        // request, and covers the inline paths that reach EmailService
        // without ever touching the queue binding.
        //
        // Round 2 c1:v2:1: this sentence USED to name "dispatchNow()/
        // dispatchSync()" together, which is the same conflation round 1
        // diff:2 already caught 60 lines above -- and the measurement up
        // there contradicts it: dispatch_sync() is KILLED by QueueFake,
        // dispatchNow() SURVIVED it. Pairing them again here re-asserted a
        // claim this file's own evidence refutes, so the pairing is gone:
        // what this binding covers is the inline-handler route, whichever
        // verb reaches it.
        //
        // Round 2 context:2: THE THROW ALONE IS NOT THE CONTROL. The
        // production remediation path swallows it --
        // NotificationService::sendVoicemailNotificationOnce() (:554-561)
        // wraps its dispatch in catch (\Throwable) and logs '[Voicemail]
        // Notification could not be queued and will not be retried', by
        // design, because the claim is already taken by then. A
        // \RuntimeException raised inside EmailService on that path therefore
        // never reaches PHPUnit: the command still exits 0 and every
        // assertion below still passes while a Graph send was attempted. So
        // the attempt is RECORDED in a variable this test owns and asserted
        // after the run; no production catch can reach that. The throw stays
        // because it also stops a caller mid-loop rather than letting it walk
        // the whole stranded set, and because on the paths that do NOT catch
        // (a direct EmailService call, dispatchNow of the job -- whose own
        // catch is on GraphClientException, not its \RuntimeException parent)
        // it still fails loudly at the point of the send.
        $graphSends = [];
        $recordGraphSend = function (string $endpoint) use (&$graphSends): void {
            $graphSends[] = $endpoint;
        };

        // Round 3 contract:1, MEASURED BEFORE IT WAS ACCEPTED. This binding used
        // to be an anonymous GraphClient subclass that skipped the parent
        // constructor and overrode post() ONLY. That instrument was broken in
        // the exact direction it was added to detect: a send-shaped mutant
        // calling patch() (or get/delete/createEvent/getRaw/...) never reached
        // the override, hit a half-constructed parent, and raised
        //   Error: Typed property GraphClient::$cache must not be accessed
        //   before initialization
        // which NotificationService's catch (\Throwable) then swallowed. The
        // mutant SURVIVED with the marker showing it fired: a Graph send was
        // attempted and this guard reported green. Overriding one verb is a
        // claim about which verb a future defect will choose.
        //
        // So the instrument moved from the VERB to the WIRE. GraphClient's
        // constructor has a documented Guzzle `handler` seam (config['handler'],
        // honoured at every one of its three client-construction sites);
        // production config never sets it. A real, fully-constructed client is
        // built here with a handler that records and refuses EVERY outbound
        // request, whatever verb or helper reaches it. The token cache is
        // pre-seeded because getToken() posts through `authHttp`, which has no
        // handler seam -- without the seed a token request would be the thing
        // that escapes, which is this same finding one layer down.
        cache()->put('graph_api_token', 'test-token-not-a-real-credential', 3600);

        $graphHandler = \GuzzleHttp\HandlerStack::create(function ($request) use ($recordGraphSend) {
            $target = $request->getUri()->getPath();
            $recordGraphSend($target);

            throw new \RuntimeException(
                'The command sent a Graph request to '.$target.': it sent something.'
            );
        });

        $this->app->bind(\App\Services\Graph\GraphClient::class, function () use ($graphHandler) {
            return new \App\Services\Graph\GraphClient(
                array_merge(config('services.graph'), ['handler' => $graphHandler]),
                app(\Illuminate\Contracts\Cache\Repository::class),
            );
        });

        $this->assertSame(
            'array',
            config('mail.default'),
            'This guard inspects the mail transport directly and needs the array '
                .'mailer to be the DEFAULT one. phpunit.xml sets MAIL_MAILER without '
                .'force="true", so an inherited environment variable silently wins.'
        );

        $transport = app(\Illuminate\Mail\MailManager::class)
            ->mailer()
            ->getSymfonyTransport();

        $this->assertInstanceOf(
            \Illuminate\Mail\Transport\ArrayTransport::class,
            $transport,
            'The default mailer must resolve to an ArrayTransport, or nothing below '
                .'can observe what the command sent.'
        );

        $transport->flush();

        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->assertExitCode(0);

        // The recorded half of the Graph guard, and the half a caught throw
        // cannot silence -- see the binding above. A send attempt that
        // sendVoicemailNotificationOnce() logs and swallows leaves the command
        // exiting 0 and leaves nothing else in this test to fail, but it
        // leaves an endpoint here.
        $this->assertSame(
            [],
            $graphSends,
            'The command reached the Graph send transport: it sent something.'
        );

        $this->assertSame($before, $snapshot());

        // The third prohibited act: no notification may leave the box, by any
        // transport. Each assertion below has its own kill-mutant recorded
        // above; none is decorative.
        Queue::assertNothingPushed();
        Notification::assertNothingSent();

        // The transport-level check that covers every mail verb, including
        // the two MailFake cannot record. Kill-mutants: Mail::raw(...),
        // Mail::send([...], ...), and Mail::to(...)->send(new Mailable).
        $this->assertSame(
            [],
            $transport->messages()->all(),
            'The command put a message on the mail transport: it sent something.'
        );

        // Precondition, on the production surface: the command must actually
        // have reported the two stranded rows, or every assertion above is
        // satisfied by a command that did nothing and this control is hollow.
        // Round 6 diff:3 + diff:4: pinning only the count left the record
        // itself unpinned -- any warning carrying a key named count equal to 2
        // (a truncation or refusal line, say) satisfied it -- and the call_ids
        // the comment above promises were never read, so only cardinality was
        // held, not row identity. Pin all three: the message, the exact count,
        // and the id set a remediating mutant would have acted on.
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($strandedIds) {
                $logged = is_array($context['call_ids'] ?? null)
                    ? array_map('intval', $context['call_ids'])
                    : [];
                sort($logged);

                return $message === '[Voicemail] Stranded notification deferrals outstanding'
                    && ($context['count'] ?? null) === 2
                    && $logged === $strandedIds;
            })
            ->once();
    }
}
