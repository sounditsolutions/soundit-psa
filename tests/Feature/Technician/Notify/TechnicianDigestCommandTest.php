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
        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notify')->once());

        $this->assertNull(TechnicianConfig::lastDigestAt());
        $this->artisan('technician:digest')->assertSuccessful();
        $this->assertNotNull(TechnicianConfig::lastDigestAt());
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
