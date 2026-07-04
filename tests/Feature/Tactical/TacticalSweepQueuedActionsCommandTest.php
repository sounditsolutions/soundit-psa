<?php

namespace Tests\Feature\Tactical;

use App\Models\Setting;
use App\Services\Tactical\OfflineActionSweep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/** The scheduled fallback sweep command (bd psa-xr84). */
class TacticalSweepQueuedActionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_fails_when_tactical_not_configured(): void
    {
        $this->artisan('tactical:sweep-queued-actions')->assertExitCode(1);
    }

    public function test_command_runs_the_due_sweep_and_reports(): void
    {
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'secret');

        $sweep = Mockery::mock(OfflineActionSweep::class);
        $sweep->shouldReceive('sweepDue')->once()->andReturn(['ran' => 2, 'expired' => 1]);
        $this->app->instance(OfflineActionSweep::class, $sweep);

        $this->artisan('tactical:sweep-queued-actions')
            ->expectsOutput('Offline-queue sweep: ran 2, expired 1.')
            ->assertExitCode(0);
    }
}
