<?php

namespace Tests\Feature\Technician\Notify;

use App\Models\Setting;
use App\Services\Technician\Notify\OperatorNotifier;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class TechnicianDigestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_technician_sends_nothing(): void
    {
        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notify')->never());
        $this->artisan('technician:digest')->assertSuccessful();
    }

    public function test_enabled_builds_notifies_and_records(): void
    {
        Setting::setValue('technician_enabled', '1');
        // Delivered → the last-digest-sent stamp is recorded.
        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notify')->once()->andReturn(true));

        $this->assertNull(TechnicianConfig::lastDigestAt());
        $this->artisan('technician:digest')->assertSuccessful();
        $this->assertNotNull(TechnicianConfig::lastDigestAt());
    }

    /**
     * psa-tmdw: recordDigestSent() must NOT stamp "digest sent" when notify()
     * delivered nothing (no channel configured) — otherwise technician_last_digest_at
     * reports success on a delivery that never happened. The command must also exit
     * NON-ZERO: a scheduler cannot otherwise tell "nothing to send" from "could not
     * send", because both look like a clean run.
     */
    public function test_digest_not_recorded_and_command_fails_when_delivery_fails(): void
    {
        Setting::setValue('technician_enabled', '1');
        // Nothing delivered (no webhook, no email) → notify() returns false.
        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notify')->once()->andReturn(false));

        $this->assertNull(TechnicianConfig::lastDigestAt());
        $this->artisan('technician:digest')->assertFailed();
        $this->assertNull(TechnicianConfig::lastDigestAt());
    }

    /**
     * psa-tmdw: the once-per-local-day schedule guard dedupes on the ATTEMPT stamp, so
     * the attempt must be recorded even when delivery reports failure. notify() returns
     * false for deliveries that DID happen (a Teams timeout, or sendNew() throwing after
     * the transport accepted the mail); if the attempt were stamped only on success the
     * guard would stay open and re-send a digest the operator already received.
     */
    public function test_attempt_is_stamped_even_when_delivery_reports_failure(): void
    {
        Setting::setValue('technician_enabled', '1');
        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notify')->once()->andReturn(false));

        $this->assertNull(TechnicianConfig::lastDigestAttemptAt());

        $this->artisan('technician:digest')->assertFailed();

        $this->assertNotNull(
            TechnicianConfig::lastDigestAttemptAt(),
            'the attempt must be stamped so the daily guard cannot re-fire and repeat-send'
        );
        $this->assertNull(TechnicianConfig::lastDigestAt(), 'delivery did not succeed');
    }

    /**
     * psa-tmdw: an install that ran the digest before the attempt key existed has only
     * the delivery stamp. lastDigestAttemptAt() must fall back to it, or the guard
     * re-fires once on upgrade and the operator gets that day's digest twice.
     */
    public function test_attempt_stamp_falls_back_to_the_legacy_delivery_stamp(): void
    {
        $this->assertNull(TechnicianConfig::lastDigestAttemptAt());

        TechnicianConfig::recordDigestSent();

        $this->assertNotNull(TechnicianConfig::lastDigestAttemptAt());
        $this->assertSame(
            TechnicianConfig::lastDigestAt()?->toIso8601String(),
            TechnicianConfig::lastDigestAttemptAt()?->toIso8601String()
        );
    }

    public function test_digest_disabled_sends_nothing(): void
    {
        \App\Models\Setting::setValue('technician_enabled', '1');
        \App\Models\Setting::setValue('technician_digest_enabled', '0');
        $this->mock(\App\Services\Technician\Notify\OperatorNotifier::class,
            fn (\Mockery\MockInterface $m) => $m->shouldReceive('notify')->never());
        $this->artisan('technician:digest')->assertSuccessful();
    }

    public function test_settings_save_persists_notify_config(): void
    {
        $user = \App\Models\User::factory()->create();

        $this->actingAs($user)->post(route('settings.integrations.technician.update'), [
            'technician_enabled' => '1',
            'technician_teams_webhook_url' => 'https://x.webhook.office.com/h',
            'technician_notify_email' => 'ops@example.com',
            'technician_digest_time' => '07:30',
            'technician_heartbeat_interval' => '20',
        ])->assertRedirect();

        $this->assertSame('https://x.webhook.office.com/h', \App\Support\TechnicianConfig::teamsWebhookUrl());
        $this->assertSame('ops@example.com', \App\Support\TechnicianConfig::notifyEmail());
        $this->assertSame('07:30', \App\Support\TechnicianConfig::digestTimeLocal());
        $this->assertSame(20, \App\Support\TechnicianConfig::heartbeatIntervalMinutes());
    }
}
