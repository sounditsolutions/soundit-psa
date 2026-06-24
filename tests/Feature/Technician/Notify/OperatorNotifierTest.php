<?php

namespace Tests\Feature\Technician\Notify;

use App\Models\Setting;
use App\Services\EmailService;
use App\Services\Technician\Notify\OperatorNotifier;
use App\Services\Technician\Notify\TeamsNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class OperatorNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivers_to_teams_and_email_when_both_configured(): void
    {
        Setting::setValue('technician_notify_email', 'ops@example.com');
        $this->mock(TeamsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('post')->once()->andReturnTrue());
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendNew')
            ->once()->with('ops@example.com', 'S', 'B', \Mockery::any(), \Mockery::any(), \Mockery::any())->andReturnNull());

        app(OperatorNotifier::class)->notify('S', 'B');
    }

    public function test_email_skipped_when_no_notify_email_but_teams_still_fires(): void
    {
        $this->mock(TeamsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('post')->once()->andReturnTrue());
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendNew')->never());

        app(OperatorNotifier::class)->notify('S', 'B');
    }

    public function test_an_email_throw_does_not_stop_or_crash(): void
    {
        Setting::setValue('technician_notify_email', 'ops@example.com');
        $this->mock(TeamsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('post')->once()->andReturnFalse());
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendNew')->once()->andThrow(new \RuntimeException('graph down')));

        app(OperatorNotifier::class)->notify('S', 'B'); // must not throw
        $this->assertTrue(true);
    }
}
