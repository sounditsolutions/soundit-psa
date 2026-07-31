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

    /**
     * psa-tmdw: the undelivered warning must not carry the subject.
     *
     * NotifyStagedActionAwaitingApproval builds it as
     * "{action} approval: {client name} ticket #{id}" — a customer name by
     * construction. This warning fires on a repeating path (once per staged action,
     * plus every digest attempt) for as long as the misconfiguration lasts, and
     * laravel.log is a single unrotated file, so logging it accumulates customer
     * names without bound. The two config booleans are what an operator acts on.
     */
    public function test_the_undelivered_warning_does_not_log_the_subject(): void
    {
        $teams = Mockery::mock(TeamsNotifier::class);
        $teams->shouldReceive('post')->once()->andReturn(false);
        $email = Mockery::mock(EmailService::class);
        $email->shouldReceive('sendNew')->never();

        Log::spy();

        $subject = 'Reboot approval: Acme Dental Group ticket #4471';
        $this->notifier($teams, $email)->notify($subject, 'body');

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $msg, array $ctx = []) use ($subject) {
                if (! str_contains($msg, 'not delivered')) {
                    return false;
                }

                // Neither the subject nor the client name inside it may appear
                // anywhere in the context, under any key.
                $rendered = json_encode($ctx);

                return ! str_contains($rendered, $subject)
                    && ! str_contains($rendered, 'Acme Dental Group')
                    && ! str_contains($rendered, '#4471')
                    // ...and the actionable booleans are still there.
                    && array_key_exists('teams_webhook_configured', $ctx)
                    && array_key_exists('notify_email_configured', $ctx);
            })
            ->once();
    }
}
