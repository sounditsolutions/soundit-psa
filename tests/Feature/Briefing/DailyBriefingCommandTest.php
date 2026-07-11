<?php

namespace Tests\Feature\Briefing;

use App\Enums\NotificationEventType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\DailyBriefing;
use App\Models\Email;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class DailyBriefingCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Neutralize the ticket-creation side effects (SendTicketNotification,
        // RunTriagePipeline) so test-data setup can't fire a real Graph send. The
        // briefing itself emails inline via EmailService, which the tests mock.
        Queue::fake();
    }

    private function enable(): void
    {
        Setting::setValue('briefing_enabled', '1');
        Setting::setValue('graph_mailbox', 'help@example.com');
    }

    /** Mock EmailService and capture every sendNew() call. */
    private function captureEmails(array &$sent): void
    {
        $this->mock(EmailService::class, function (MockInterface $m) use (&$sent) {
            $m->shouldReceive('sendNew')->andReturnUsing(
                function ($to, $subject, $body, $toName = null, $cc = null, $userId = null) use (&$sent) {
                    $sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body, 'userId' => $userId];

                    // sendNew() is typed to return an Email; hand back a stub so the
                    // service's per-technician try/catch doesn't swallow a TypeError.
                    return new Email;
                }
            );
        });
    }

    private function expectNoEmail(): void
    {
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendNew')->never());
    }

    private function techWithOpenTicket(array $userOverrides = []): User
    {
        $tech = User::factory()->tech()->create($userOverrides);
        $client = Client::factory()->create(['primary_tech_id' => $tech->id, 'is_active' => true]);
        Ticket::factory()->create([
            'assignee_id' => $tech->id,
            'client_id' => $client->id,
            'status' => TicketStatus::InProgress->value,
            'priority' => TicketPriority::P2->value,
            'opened_at' => now()->subDay(),
            'closed_at' => null,
            'resolved_at' => null,
        ]);

        return $tech;
    }

    public function test_does_nothing_when_disabled(): void
    {
        $this->techWithOpenTicket();
        $this->expectNoEmail();

        $this->artisan('briefing:send-daily')
            ->expectsOutputToContain('disabled')
            ->assertSuccessful();

        $this->assertSame(0, DailyBriefing::count());
    }

    public function test_emails_active_technician_and_records_row(): void
    {
        $this->enable();
        $tech = $this->techWithOpenTicket();
        $sent = [];
        $this->captureEmails($sent);

        $this->artisan('briefing:send-daily')->assertSuccessful();

        $this->assertCount(1, $sent);
        $this->assertSame($tech->email, $sent[0]['to']);
        $this->assertSame($tech->id, $sent[0]['userId']);
        $this->assertStringContainsString('daily briefing', strtolower($sent[0]['subject']));

        $briefing = DailyBriefing::where('user_id', $tech->id)->first();
        $this->assertNotNull($briefing);
        $this->assertSame(1, $briefing->open_ticket_count);
        $this->assertNotNull($briefing->sent_at);
        $this->assertSame(now()->toDateString(), $briefing->briefing_date->toDateString());
    }

    public function test_excludes_inactive_billing_and_contractor_users(): void
    {
        $this->enable();
        $active = $this->techWithOpenTicket();
        $this->techWithOpenTicket(['is_active' => false]);
        $this->techWithOpenTicket()->update(['role' => \App\Enums\UserRole::Billing]);
        $this->techWithOpenTicket(['is_contractor' => true]);

        $sent = [];
        $this->captureEmails($sent);

        $this->artisan('briefing:send-daily')->assertSuccessful();

        $this->assertCount(1, $sent);
        $this->assertSame($active->email, $sent[0]['to']);
        $this->assertSame(1, DailyBriefing::count());
    }

    public function test_is_idempotent_across_repeated_runs_same_day(): void
    {
        $this->enable();
        $this->techWithOpenTicket();
        $sent = [];
        $this->captureEmails($sent);

        $this->artisan('briefing:send-daily')->assertSuccessful();
        $this->artisan('briefing:send-daily')->assertSuccessful();

        $this->assertCount(1, $sent);
        $this->assertSame(1, DailyBriefing::count());
    }

    public function test_respects_opt_out_preference(): void
    {
        $this->enable();
        $tech = $this->techWithOpenTicket();
        $tech->update(['notification_preferences' => [NotificationEventType::DailyBriefing->value => false]]);
        $this->expectNoEmail();

        $this->artisan('briefing:send-daily')->assertSuccessful();

        $this->assertSame(0, DailyBriefing::count());
    }

    public function test_does_not_send_or_record_an_empty_briefing(): void
    {
        $this->enable();
        // Active tech with no tickets/alerts/voicemails.
        User::factory()->tech()->create();
        $this->expectNoEmail();

        $this->artisan('briefing:send-daily')->assertSuccessful();

        $this->assertSame(0, DailyBriefing::count());
    }

    public function test_dry_run_previews_without_sending_or_recording(): void
    {
        $this->enable();
        $this->techWithOpenTicket();
        $this->expectNoEmail();

        $this->artisan('briefing:send-daily --dry-run')
            ->expectsOutputToContain('[DRY RUN]')
            ->assertSuccessful();

        $this->assertSame(0, DailyBriefing::count());
    }

    public function test_user_option_targets_a_single_technician(): void
    {
        $this->enable();
        $target = $this->techWithOpenTicket();
        $other = $this->techWithOpenTicket();
        $sent = [];
        $this->captureEmails($sent);

        $this->artisan('briefing:send-daily --user='.$target->id)->assertSuccessful();

        $this->assertCount(1, $sent);
        $this->assertSame($target->email, $sent[0]['to']);
        $this->assertSame(1, DailyBriefing::where('user_id', $target->id)->count());
        $this->assertSame(0, DailyBriefing::where('user_id', $other->id)->count());
    }

    public function test_skips_run_when_no_mailbox_configured(): void
    {
        Setting::setValue('briefing_enabled', '1');
        // graph_mailbox intentionally not set.
        $this->techWithOpenTicket();
        $this->expectNoEmail();

        $this->artisan('briefing:send-daily')->assertSuccessful();

        $this->assertSame(0, DailyBriefing::count());
    }

    // ── Schedule gating ─────────────────────────────────────────────────────

    public function test_schedule_is_dark_by_default(): void
    {
        $this->assertFalse($this->filtersPass('briefing:send-daily'));
    }

    public function test_schedule_fires_only_at_configured_local_time(): void
    {
        Setting::setValue('briefing_enabled', '1');
        Setting::setValue('briefing_time', '07:00');

        Carbon::setTestNow(Carbon::parse('2026-07-09 07:00:00', 'UTC'));
        $this->assertTrue($this->filtersPass('briefing:send-daily'));

        Carbon::setTestNow(Carbon::parse('2026-07-09 09:30:00', 'UTC'));
        $this->assertFalse($this->filtersPass('briefing:send-daily'));

        Carbon::setTestNow();
    }

    private function filtersPass(string $summaryNeedle): bool
    {
        return $this->scheduleEvent($summaryNeedle)->filtersPass($this->app);
    }

    private function scheduleEvent(string $summaryNeedle): Event
    {
        foreach ($this->app->make(Schedule::class)->events() as $event) {
            if (str_contains($event->getSummaryForDisplay(), $summaryNeedle)) {
                return $event;
            }
        }

        $this->fail("Scheduled event [{$summaryNeedle}] was not registered.");
    }
}
