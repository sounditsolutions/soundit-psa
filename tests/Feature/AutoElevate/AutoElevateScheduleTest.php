<?php

namespace Tests\Feature\AutoElevate;

use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The autoelevate:sync-assets schedule (ruled hourly, card NtwR7tDf): once an
 * hour, a 55-minute overlap lock, run in the background, and one fixed log
 * line when a run fails. Each property is read from the registered schedule
 * event itself; the failure line is driven through the event's own after
 * callbacks with a failing and a succeeding exit code.
 *
 * G-5: no command is run and no vendor is read; stray Http requests are
 * prevented anyway.
 */
class AutoElevateScheduleTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{0: string, 1: string}> level, message */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = [$e->level, $e->message];
        });
    }

    private function syncEvent(): ScheduleEvent
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_ends_with($event->command ?? '', ' autoelevate:sync-assets'));
        $this->assertCount(1, $events, 'autoelevate:sync-assets must be scheduled exactly once');

        return $events->first();
    }

    public function test_the_sync_runs_hourly_on_the_hour(): void
    {
        $this->assertSame('0 * * * *', $this->syncEvent()->expression);
    }

    public function test_the_sync_holds_a_55_minute_overlap_lock(): void
    {
        $event = $this->syncEvent();

        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(55, $event->expiresAt);
    }

    public function test_the_sync_runs_in_the_background(): void
    {
        $this->assertTrue($this->syncEvent()->runInBackground);
    }

    public function test_a_failed_run_logs_one_fixed_error_line(): void
    {
        $event = $this->syncEvent();
        $event->exitCode = 1;
        $event->callAfterCallbacks($this->app);

        $this->assertSame([['error', '[AutoElevateSyncAssets] scheduled run failed']], $this->logged);
    }

    public function test_a_successful_run_logs_nothing(): void
    {
        $event = $this->syncEvent();
        $event->exitCode = 0;
        $event->callAfterCallbacks($this->app);

        $this->assertSame([], $this->logged);
    }

    public function test_the_scheduled_name_is_a_registered_command(): void
    {
        $this->syncEvent();

        $this->assertTrue(array_key_exists('autoelevate:sync-assets', Artisan::all()));
    }

    /**
     * The no-when() ruling rests on the event always running: with no API key
     * configured it must still pass its filters, and carry no environment limit.
     * Its one when() filter is the autoelevate_enabled switch (Q-5 A), which a
     * missing key does not close (switch at its default, no key: filters pass).
     * Its one reject is the overlap lock's own mutex check (added by
     * withoutOverlapping), and nothing else.
     */
    public function test_the_events_only_filter_is_the_switch_and_a_missing_key_still_passes(): void
    {
        $this->assertFalse(\App\Support\AutoElevateConfig::isConfigured());

        $event = $this->syncEvent();
        $filters = (new \ReflectionProperty($event, 'filters'))->getValue($event);
        $rejects = (new \ReflectionProperty($event, 'rejects'))->getValue($event);

        $this->assertCount(1, $filters);
        $this->assertSame('framework/schedule-autoelevate-sync-assets', $event->mutexName());
        $this->assertCount(1, $rejects);
        $this->assertSame([], $event->environments);
        $this->assertTrue($event->runsInEnvironment($this->app->environment()));
        $this->assertTrue($event->filtersPass($this->app));
    }
}
