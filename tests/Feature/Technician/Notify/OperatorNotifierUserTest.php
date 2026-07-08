<?php

namespace Tests\Feature\Technician\Notify;

use App\Models\Setting;
use App\Models\User;
use App\Services\EmailService;
use App\Services\Teams\TeamsBotClient;
use App\Services\Technician\Notify\OperatorNotifier;
use App\Services\Technician\Notify\SmsNotifier;
use App\Services\Technician\Notify\TeamsNotifier;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class OperatorNotifierUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_notify_user_emails_that_user_and_posts_teams(): void
    {
        $user = User::factory()->create(['email' => 'justin@example.com']);

        $this->mock(TeamsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('post')->once()->andReturnTrue());
        $this->mock(SmsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('send')->never());
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendNew')
            ->once()->with('justin@example.com', 'S', 'B', \Mockery::any(), \Mockery::any(), \Mockery::any())->andReturnNull());

        app(OperatorNotifier::class)->notifyUser($user->id, 'S', 'B');
    }

    public function test_notify_user_uses_modern_teams_bot_when_escalation_chat_is_configured(): void
    {
        $user = User::factory()->create([
            'name' => 'Justin',
            'email' => 'justin@example.com',
            'microsoft_id' => 'aad-justin',
        ]);

        Setting::setValue('teams_bot_enabled', '1');
        Setting::setValue('teams_escalation_conversation_id', 'conv-day-to-day');
        Setting::setValue('teams_escalation_service_url', 'https://smba.trafficmanager.net/amer/');

        $this->mock(TeamsBotClient::class, function (MockInterface $m) {
            $m->shouldReceive('getConversationMember')
                ->once()
                ->with('https://smba.trafficmanager.net/amer/', 'conv-day-to-day', 'aad-justin')
                ->andReturn(['id' => '29:justin', 'name' => 'Justin']);

            $m->shouldReceive('sendMessageWithMentions')
                ->once()
                ->with(
                    'https://smba.trafficmanager.net/amer/',
                    'conv-day-to-day',
                    \Mockery::on(fn (string $body): bool => str_contains($body, '<at>Justin</at> B')),
                    [['mentionId' => '29:justin', 'name' => 'Justin']],
                )
                ->andReturnTrue();
        });
        $this->mock(TeamsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('post')->never());
        $this->mock(SmsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('send')->never());
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendNew')
            ->once()->with('justin@example.com', 'S', 'B', \Mockery::any(), \Mockery::any(), \Mockery::any())->andReturnNull());

        app(OperatorNotifier::class)->notifyUser($user->id, 'S', 'B');
    }

    public function test_sms_only_when_requested_and_phone_present(): void
    {
        $user = User::factory()->create(['email' => 'j@example.com']);
        TechnicianConfig::setOperatorPhone($user->id, '+15550001111');

        $this->mock(TeamsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('post')->once());
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendNew')->once());
        $this->mock(SmsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('send')->once()->with('+15550001111', \Mockery::any())->andReturnTrue());

        app(OperatorNotifier::class)->notifyUser($user->id, 'S', 'B', sms: true);
    }

    public function test_sms_skipped_when_no_phone_configured(): void
    {
        $user = User::factory()->create(['email' => 'k@example.com']);
        // No setOperatorPhone — phone is null

        $this->mock(TeamsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('post')->once());
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendNew')->once());
        $this->mock(SmsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('send')->never());

        app(OperatorNotifier::class)->notifyUser($user->id, 'S', 'B', sms: true);
    }

    public function test_unknown_user_is_ignored_gracefully(): void
    {
        $this->mock(TeamsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('post')->never());
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendNew')->never());
        $this->mock(SmsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('send')->never());

        app(OperatorNotifier::class)->notifyUser(99999, 'S', 'B');

        $this->assertTrue(true); // must not throw
    }
}
