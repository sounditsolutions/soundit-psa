<?php

namespace Tests\Feature\Console;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Setting;
use App\Services\Tactical\TacticalClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * psa-1355: `tactical:reconcile-alerts` fails closed when the fetch comes back
 * carrying no usable alert.
 *
 * The command's not-returned branch reads "absent from the Tactical response"
 * as "resolved in Tactical". That inference is only sound when the response is
 * a real answer about alerts. A successful-but-empty response — a key that lost
 * alert-read permission, a timeFilter regression, an upstream filter-semantics
 * change — would otherwise resolve EVERY open Tactical alert in a single hourly
 * run, each with an audit reason reading like a considered decision, and there
 * is no reverse sweep to undo it.
 *
 * So the tests below are mostly about what does NOT happen. Each asserts the
 * open alert is still open afterwards, because an exit code alone would pass
 * against a version that refused loudly and swept anyway.
 */
class TacticalReconcileAlertsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<MessageLogged> */
    private array $capturedLogs = [];

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('tactical_api_url', 'https://tactical.example.com');
        Setting::setEncrypted('tactical_api_key', 'test-key');

        $this->capturedLogs = [];

        Log::listen(function (MessageLogged $record): void {
            $this->capturedLogs[] = $record;
        });
    }

    private function openAlert(string $sourceAlertId): Alert
    {
        return Alert::create([
            'source' => AlertSource::Tactical->value,
            'source_alert_id' => $sourceAlertId,
            'severity' => 'critical',
            'status' => AlertStatus::Ticketed->value,
            'title' => "Tactical alert {$sourceAlertId}",
            'fired_at' => now()->subDay(),
        ]);
    }

    /**
     * Bind a TacticalClient whose one PATCH returns $rows.
     *
     * @param  array<int, mixed>  $rows
     */
    private function fakeTacticalReturning(array $rows): void
    {
        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('patch')
            ->once()
            // The literal 150.0 is the point of this expectation: the call site
            // must hand the fetch timeout to the client, not merely own a
            // constant. Without it the endpoint's measured 41-85s answer keeps
            // losing to the client's 30s default and no run ever gets a list.
            ->with('alerts/', ['timeFilter' => 30], 150.0)
            ->andReturn($rows);

        $this->app->instance(TacticalClient::class, $tactical);
    }

    /**
     * The context of the last logged line whose message contains $needle,
     * or null if no such line was logged.
     *
     * @return array<string, mixed>|null
     */
    private function loggedContext(string $needle): ?array
    {
        $found = null;

        foreach ($this->capturedLogs as $record) {
            if (str_contains((string) $record->message, $needle)) {
                $found = $record->context;
            }
        }

        return $found;
    }

    public function test_an_empty_response_refuses_and_resolves_nothing(): void
    {
        $alert = $this->openAlert('tactical-1');
        $this->fakeTacticalReturning([]);

        $this->artisan('tactical:reconcile-alerts')
            ->expectsOutputToContain('Refusing to reconcile')
            ->assertFailed();

        $this->assertSame(AlertStatus::Ticketed, $alert->fresh()->status);
        $this->assertNull($alert->fresh()->resolved_at);
    }

    public function test_rows_that_carry_no_id_take_the_same_refusal(): void
    {
        $alert = $this->openAlert('tactical-1');
        // A shape change upstream — rows arrive, none usable. Identical outcome
        // to an empty list by design: zero usable alerts is the trigger.
        $this->fakeTacticalReturning([['alert_id' => 7], ['alert_id' => 8]]);

        $this->artisan('tactical:reconcile-alerts')->assertFailed();

        $this->assertSame(AlertStatus::Ticketed, $alert->fresh()->status);
    }

    public function test_the_refusal_logs_the_open_count_and_how_many_rows_arrived(): void
    {
        $this->openAlert('tactical-1');
        $this->openAlert('tactical-2');
        $this->fakeTacticalReturning([['alert_id' => 7]]);

        $this->artisan('tactical:reconcile-alerts')->assertFailed();

        $context = $this->loggedContext('Reconciliation refused');

        $this->assertNotNull($context, 'The refusal should be logged, not only printed.');
        $this->assertSame(2, $context['open_alerts']);
        // 1, not 0: the row arrived and was unusable. That distinction is the
        // difference between "Tactical has nothing to say" and "we no longer
        // understand what Tactical says", which need different repairs.
        $this->assertSame(1, $context['rows_returned']);
    }

    public function test_a_dry_run_refuses_too(): void
    {
        $alert = $this->openAlert('tactical-1');
        $this->fakeTacticalReturning([]);

        // --dry-run resolves nothing either way, so this is not about damage:
        // it is the flag an operator reaches for to see what the command thinks,
        // and it must not print a confident sweep list built from a bad fetch.
        $this->artisan('tactical:reconcile-alerts', ['--dry-run' => true])
            ->expectsOutputToContain('Refusing to reconcile')
            ->assertFailed();

        $this->assertSame(AlertStatus::Ticketed, $alert->fresh()->status);
    }

    public function test_a_usable_response_still_reconciles(): void
    {
        $returnedResolved = $this->openAlert('tactical-1');
        $returnedOpen = $this->openAlert('tactical-2');
        $notReturned = $this->openAlert('tactical-3');

        $this->fakeTacticalReturning([
            ['id' => 'tactical-1', 'resolved' => true],
            ['id' => 'tactical-2', 'resolved' => false],
        ]);

        $this->artisan('tactical:reconcile-alerts')->assertSuccessful();

        // The guard is a floor on an empty fetch, not a new condition on the
        // reconciliation itself: with one usable row present, the absent alert
        // is still swept exactly as before.
        $this->assertSame(AlertStatus::Resolved, $returnedResolved->fresh()->status);
        $this->assertSame(AlertStatus::Ticketed, $returnedOpen->fresh()->status);
        $this->assertSame(AlertStatus::Resolved, $notReturned->fresh()->status);
    }

    /**
     * The second absence-is-not-evidence case, and the one the fail-closed
     * guard does not reach.
     *
     * The fetch is filtered to a 30-day window, so an alert older than that is
     * missing from EVERY response the filter can produce. The not-returned
     * branch read that as "resolved in Tactical" and closed it with an audit
     * reason reading like a considered decision — for being old. The guard above
     * cannot catch it: the response is healthy and full, it simply does not
     * contain the alert. Measured on production while this was written: 60 open
     * Tactical alerts, 3 of them past the window.
     */
    public function test_an_open_alert_older_than_the_window_is_left_open_not_swept(): void
    {
        $inWindow = $this->openAlert('tactical-1');

        $tooOld = $this->openAlert('tactical-old');
        $tooOld->forceFill(['fired_at' => now()->subDays(45)])->save();

        // A healthy answer that contains the in-window alert and — correctly,
        // given the filter — says nothing at all about the 45-day-old one.
        $this->fakeTacticalReturning([
            ['id' => 'tactical-1', 'resolved' => true],
        ]);

        $this->artisan('tactical:reconcile-alerts')
            ->expectsOutputToContain('Skipped 1 open alert(s) older than the 30-day reconcile window')
            ->assertSuccessful();

        $this->assertSame(AlertStatus::Resolved, $inWindow->fresh()->status);
        $this->assertSame(AlertStatus::Ticketed, $tooOld->fresh()->status);
    }

    /**
     * fired_at is nullable, and an alert that cannot prove it is inside the
     * window is not judged by the window's answer either. Fail closed on
     * unknown: left open, counted in the skip line.
     */
    public function test_an_open_alert_with_no_fired_at_falls_back_to_its_creation_time(): void
    {
        $unknownAge = $this->openAlert('tactical-unknown');
        $unknownAge->forceFill([
            'fired_at' => null,
            'created_at' => now()->subDays(45),
        ])->save();

        $this->fakeTacticalReturning([
            ['id' => 'tactical-other', 'resolved' => false],
        ]);

        $this->artisan('tactical:reconcile-alerts')
            ->expectsOutputToContain('Skipped 1 open alert(s)')
            ->assertSuccessful();

        $this->assertSame(AlertStatus::Ticketed, $unknownAge->fresh()->status);
    }

    /**
     * REGRESSION GUARD, and it passes against the unfixed command by design:
     * the window bound must not disable the ordinary sweep. An alert inside the
     * window that Tactical omits is still absent from a response that should
     * have contained it, which is the inference the command exists to make.
     */
    public function test_an_absent_alert_inside_the_window_is_still_resolved(): void
    {
        $notReturned = $this->openAlert('tactical-3');

        $this->fakeTacticalReturning([
            ['id' => 'tactical-1', 'resolved' => false],
        ]);

        $this->artisan('tactical:reconcile-alerts')->assertSuccessful();

        $this->assertSame(AlertStatus::Resolved, $notReturned->fresh()->status);
    }

    public function test_with_no_open_alerts_the_fetch_is_never_made(): void
    {
        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldNotReceive('patch');
        $this->app->instance(TacticalClient::class, $tactical);

        // The early return precedes the guard, so the refusal branch cannot fire
        // on an idle system — nothing to protect, nothing to alarm about.
        $this->artisan('tactical:reconcile-alerts')
            ->expectsOutputToContain('No open Tactical alerts found.')
            ->assertSuccessful();
    }
}
