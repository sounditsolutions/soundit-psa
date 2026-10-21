<?php

namespace Tests\Feature\Email;

use App\Enums\NotificationEventType;
use App\Jobs\SendTicketNotification;
use App\Models\McpToken;
use App\Models\Setting;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalRoute;
use App\Models\SignalRouteStep;
use App\Models\User;
use App\Services\Agent\Intake\EmailTriageWatch;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailIntakeGaugeTest extends TestCase
{
    use RefreshDatabase;

    private const UNWATCHED = 'Support inbox email is not reaching any agent route';

    private const PILE_UP = 'Auto-ticketing is off and no agent route is watching: inbound support email will pile up unhandled';

    private const LAPSE = 'Microsoft Graph mail subscription is missing, expired or due to expire within 20 hours; if it lapses, new mail will arrive only via the fallback poll';

    private SignalRoute $route;

    private User $admin;

    private array $records = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        Queue::fake();
        $this->freezeTime();
        Setting::setValue('email_auto_ticket', '1');
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email' => 'admin@example.test']);
        McpToken::create(['label' => 'gauge-test', 'token_hash' => 'synthetic-gauge-hash', 'tools' => ['poll_signals'], 'activated_at' => now()]);
        $destination = SignalDestination::create(['label' => 'Gauge inbox', 'type' => 'mcp', 'mcp_token_label' => 'gauge-test', 'enabled' => true]);
        $this->route = SignalRoute::create(['label' => 'Gauge route', 'event_filter' => ['types' => [EmailTriageWatch::SIGNAL]], 'enabled' => true]);
        SignalRouteStep::create(['route_id' => $this->route->id, 'step_order' => 1, 'destination_id' => $destination->id]);
        Log::listen(function ($record): void {
            $this->records[] = [$record->level, (string) $record->message, $record->context];
        });
    }

    private function runGauge(): void
    {
        $this->artisan('email:intake-gauge')->assertExitCode(0);
    }

    private function broken(): void
    {
        $this->route->update(['enabled' => false]);
    }

    private function subscription(int $hours): void
    {
        Setting::setValue('graph_mailbox', 'support@example.test');
        Setting::setValue('graph_subscription_id', 'synthetic-subscription');
        Setting::setValue('graph_subscription_expiry', now()->addHours($hours)->toIso8601String());
    }

    private function property(SendTicketNotification $job, string $name): mixed
    {
        return (new \ReflectionProperty($job, $name))->getValue($job);
    }

    private function jobs(): array
    {
        return Queue::pushed(SendTicketNotification::class)->all();
    }

    private function assertAlerts(array $conditions): void
    {
        $jobs = $this->jobs();
        $this->assertCount(count($conditions), $jobs);
        $this->assertCount(count($conditions), $this->records);
        foreach ($conditions as $i => $condition) {
            $this->assertSame('critical', $this->records[$i][0]);
            $this->assertSame('[EmailIntakeGauge] '.$condition, $this->records[$i][1]);
            $this->assertSame(['condition', 'auto_ticket'], array_keys($this->records[$i][2]));
            $ctx = json_decode($this->property($jobs[$i], 'extraContext'), true);
            $this->assertSame($condition, $ctx['condition']);
            $this->assertSame('email_intake_alarm', $this->property($jobs[$i], 'eventType'));
            $this->assertNull($this->property($jobs[$i], 'ticketId'));
            $this->assertNull($this->property($jobs[$i], 'actorUserId'));
        }
    }

    public function test_a_disabled_route_alerts_outside_the_signal_route(): void
    {
        $this->broken();
        $before = SignalEvent::count();
        $this->runGauge();
        $this->assertSame($before, SignalEvent::count());
        $this->assertAlerts([self::UNWATCHED]);
    }

    public function test_b_repeated_break_is_silent(): void
    {
        $this->broken();
        $this->runGauge();
        $this->runGauge();
        $this->assertAlerts([self::UNWATCHED]);
    }

    public function test_c_recovery_then_break_alerts_again(): void
    {
        $this->broken();
        $this->runGauge();
        $this->route->update(['enabled' => true]);
        $this->runGauge();
        $this->assertAlerts([self::UNWATCHED]);
        $this->assertFalse(json_decode(Setting::getValue('email_intake_gauge_state'), true)['unwatched']);
        $this->broken();
        $this->runGauge();
        $this->assertAlerts([self::UNWATCHED, self::UNWATCHED]);
    }

    public function test_d_healthy_route_and_subscription_are_silent(): void
    {
        $this->subscription(70);
        $this->runGauge();
        $this->runGauge();
        $this->assertAlerts([]);
    }

    public function test_e_lapse_alert_is_once_and_has_only_safe_context(): void
    {
        $this->subscription(10);
        $this->runGauge();
        $this->runGauge();
        $this->assertAlerts([self::LAPSE]);
        $job = $this->jobs()[0];
        $raw = $this->property($job, 'extraContext');
        $this->assertStringNotContainsString('graph_mailbox', $raw);
        $this->assertStringNotContainsString('support@example.test', $raw);
        $this->assertStringNotContainsString('synthetic-subscription', $raw);
        $detail = 'auto_ticket: true'."\nexpiry: ".now()->addHours(10)->toIso8601String();
        $this->assertSame(['condition' => self::LAPSE, 'detail' => $detail], json_decode($raw, true));
        $body = (new \ReflectionMethod($job, 'buildBody'))->invoke($job, NotificationEventType::EmailIntakeAlarm, null, null);
        $subject = (new \ReflectionMethod($job, 'buildSubject'))->invoke($job, NotificationEventType::EmailIntakeAlarm, null);
        $this->assertSame(self::LAPSE."\n".$detail, $body);
        $this->assertSame('PSA email intake alarm: '.self::LAPSE, $subject);
    }

    public function test_f_subscription_recovery_rearms_the_alert(): void
    {
        $this->subscription(10);
        $this->runGauge();
        $this->subscription(70);
        $this->runGauge();
        $this->assertAlerts([self::LAPSE]);
        $this->assertFalse(json_decode(Setting::getValue('email_intake_gauge_state'), true)['subscription_lapse']);
        $this->subscription(10);
        $this->runGauge();
        $this->assertAlerts([self::LAPSE, self::LAPSE]);
    }

    public function test_g_no_mailbox_means_no_subscription_alarm(): void
    {
        $this->runGauge();
        $this->runGauge();
        $this->assertAlerts([]);
        $this->assertFalse(json_decode(Setting::getValue('email_intake_gauge_state'), true)['subscription_lapse']);
    }

    public function test_h_only_active_admins_with_email_receive_alerts(): void
    {
        User::factory()->create(['role' => 'tech', 'is_active' => true, 'email' => 'tech@example.test']);
        User::factory()->create(['role' => 'admin', 'is_active' => false, 'email' => 'inactive@example.test']);
        $this->broken();
        $this->runGauge();
        $this->assertAlerts([self::UNWATCHED]);
        $this->assertSame($this->admin->id, $this->property($this->jobs()[0], 'recipientUserId'));
    }

    public function test_i_schedule_is_unique_every_fifteen_minutes_with_overlap_lock(): void
    {
        $events = array_values(array_filter(app(Schedule::class)->events(), fn ($event) => str_ends_with($event->command ?? '', ' email:intake-gauge')));
        $this->assertCount(1, $events);
        $this->assertSame('*/15 * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertSame(10, $events[0]->expiresAt);
    }

    public function test_j_pile_up_is_distinct_from_unwatched(): void
    {
        $this->broken();
        $this->runGauge();
        $this->assertAlerts([self::UNWATCHED]);
        Setting::setValue('email_auto_ticket', '0');
        $this->runGauge();
        $this->runGauge();
        $this->assertAlerts([self::UNWATCHED, self::PILE_UP]);
        $this->assertFalse($this->records[1][2]['auto_ticket']);
    }

    public function test_k_ticketless_subject_and_body_are_explicit(): void
    {
        $this->broken();
        $this->runGauge();
        $job = $this->jobs()[0];
        $subject = (new \ReflectionMethod($job, 'buildSubject'))->invoke($job, NotificationEventType::EmailIntakeAlarm, null);
        $this->assertSame('PSA email intake alarm: '.self::UNWATCHED, $subject);
        $this->assertStringNotContainsString('Update on:', $subject);
        $body = (new \ReflectionMethod($job, 'buildBody'))->invoke($job, NotificationEventType::EmailIntakeAlarm, null, null);
        $this->assertSame(self::UNWATCHED."\nauto_ticket: true", $body);
    }

    public function test_l_opt_out_does_not_suppress_critical_log(): void
    {
        $this->admin->update(['notification_preferences' => ['email_intake_alarm' => false]]);
        $this->broken();
        $this->runGauge();
        Queue::assertNothingPushed();
        $this->assertSame([['critical', '[EmailIntakeGauge] '.self::UNWATCHED, ['condition' => 'unwatched', 'auto_ticket' => true]]], $this->records);
    }

    public function test_subscription_boundary_and_missing_or_invalid_settings(): void
    {
        $this->subscription(21);
        $this->runGauge();
        $this->assertAlerts([]);
        $this->subscription(20);
        $this->runGauge();
        $this->assertAlerts([self::LAPSE]);

        foreach (['missing_id', 'missing_expiry', 'invalid_expiry', 'expired'] as $case) {
            $this->subscription(70);
            $this->runGauge();
            if ($case === 'missing_id') {
                Setting::setValue('graph_subscription_id', null);
            } elseif ($case === 'missing_expiry') {
                Setting::setValue('graph_subscription_expiry', null);
            } elseif ($case === 'invalid_expiry') {
                Setting::setValue('graph_subscription_expiry', 'not-a-date');
            } else {
                $this->subscription(-1);
            }
            $before = count($this->jobs());
            $this->runGauge();
            $this->runGauge();
            $this->assertCount($before + 1, $this->jobs(), $case);
            $this->assertAlerts(array_fill(0, $before + 1, self::LAPSE));
        }
    }

    public function test_first_run_pile_up_fires_both_conditions_once(): void
    {
        $this->broken();
        Setting::setValue('email_auto_ticket', '0');
        $this->runGauge();
        $this->runGauge();
        $this->assertAlerts([self::UNWATCHED, self::PILE_UP]);
    }

    public function test_preference_copy_is_the_ruled_description(): void
    {
        $event = NotificationEventType::EmailIntakeAlarm;
        $this->assertSame('Email intake alarms', $event->label());
        $this->assertSame('bi-envelope-slash', $event->icon());
        $this->assertTrue($event->defaultEnabled());
        $this->assertSame('Admins only. Sent once when support email stops reaching an agent route or could pile up unhandled, or the Microsoft Graph mail subscription is missing, expired or due to expire within 20 hours. Turning this off stops the email; the critical log entry is always written.', $event->description());
    }
}
