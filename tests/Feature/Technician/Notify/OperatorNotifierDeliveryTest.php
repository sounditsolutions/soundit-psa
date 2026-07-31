<?php

namespace Tests\Feature\Technician\Notify;

use App\Models\Setting;
use App\Services\Agent\Escalation\OperatorDelivery;
use App\Services\EmailService;
use App\Services\Technician\Notify\OperatorNotifier;
use App\Services\Technician\Notify\SmsNotifier;
use App\Services\Technician\Notify\TeamsNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * psa-tmdw: a Technician notification with NO delivery channel configured used to
 * no-op silently — no delivery, no log. notify() must now report whether anything
 * was actually delivered and shout (Log::warning) when it delivered nothing, so
 * the worker-down alert can never silently vanish.
 */
class OperatorNotifierDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function notifier(TeamsNotifier $teams, EmailService $email): OperatorNotifier
    {
        return new OperatorNotifier(
            $teams,
            $email,
            Mockery::mock(SmsNotifier::class),
            Mockery::mock(OperatorDelivery::class),
        );
    }

    public function test_notify_returns_false_and_warns_when_no_channel_delivers(): void
    {
        // No webhook -> Teams post() returns false; no notify email configured.
        $teams = Mockery::mock(TeamsNotifier::class);
        $teams->shouldReceive('post')->once()->andReturn(false);
        $email = Mockery::mock(EmailService::class);
        $email->shouldReceive('sendNew')->never();

        Log::spy();

        $delivered = $this->notifier($teams, $email)->notify('subj', 'body');

        $this->assertFalse($delivered);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $msg, array $ctx = []) => str_contains($msg, 'not delivered'))
            ->once();
    }

    /**
     * A configured-but-broken channel must NOT be reported as unconfigured: post()
     * fail-softs to false on a revoked webhook and sendNew() throws on a dead relay,
     * so the undelivered warning has to consult the config before naming a cause.
     */
    public function test_notify_blames_delivery_failure_not_missing_config_when_channels_are_configured(): void
    {
        Setting::setValue('technician_teams_webhook_url', 'https://x.webhook.office.com/h');
        Setting::setValue('technician_notify_email', 'ops@example.com');

        $teams = Mockery::mock(TeamsNotifier::class);
        $teams->shouldReceive('post')->once()->andReturn(false); // e.g. 403 from a revoked webhook
        $email = Mockery::mock(EmailService::class);
        $email->shouldReceive('sendNew')->once()->andThrow(new \RuntimeException('smtp down'));

        Log::spy();

        $delivered = $this->notifier($teams, $email)->notify('subj', 'body');

        $this->assertFalse($delivered);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $msg, array $ctx = []) => str_contains($msg, 'not delivered')
                && ! str_contains($msg, 'no delivery channel configured')
                && str_contains($msg, 'failed at send time')
                && ($ctx['teams_webhook_configured'] ?? null) === true
                && ($ctx['notify_email_configured'] ?? null) === true)
            ->once();
    }

    public function test_notify_returns_true_when_teams_delivers(): void
    {
        $teams = Mockery::mock(TeamsNotifier::class);
        $teams->shouldReceive('post')->once()->andReturn(true);
        $email = Mockery::mock(EmailService::class);
        $email->shouldReceive('sendNew')->never(); // no notify email configured

        Log::spy();

        $delivered = $this->notifier($teams, $email)->notify('subj', 'body');

        $this->assertTrue($delivered);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_notify_returns_true_when_only_email_delivers(): void
    {
        Setting::setValue('technician_notify_email', 'ops@example.com');

        $teams = Mockery::mock(TeamsNotifier::class);
        $teams->shouldReceive('post')->once()->andReturn(false);
        $email = Mockery::mock(EmailService::class);
        $email->shouldReceive('sendNew')->once();

        Log::spy();

        $delivered = $this->notifier($teams, $email)->notify('subj', 'body');

        $this->assertTrue($delivered);
        Log::shouldNotHaveReceived('warning');
    }
}
