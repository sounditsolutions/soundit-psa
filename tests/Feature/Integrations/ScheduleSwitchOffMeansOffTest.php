<?php

namespace Tests\Feature\Integrations;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #7926: switching ScreenConnect or Servosity off stops the scheduler starting
 * their commands, as OFF=OFF (card YouDehkS) already does for Level, Control D
 * and Zorus. Each switched-off case has a switched-on positive control on the
 * same schedule with every other condition met, so the filter cannot pass or
 * fail vacuously.
 *
 * Synthetic data only (G-13): fixture keys, .test hosts.
 */
class ScheduleSwitchOffMeansOffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** @return array<string, array{0: string, 1: string}> switch setting, command */
    public static function scheduledCommands(): array
    {
        return [
            'screenconnect:count-licenses' => ['screenconnect_enabled', 'screenconnect:count-licenses'],
            'servosity:sync-licenses' => ['servosity_enabled', 'servosity:sync-licenses'],
            'servosity:provision-backups' => ['servosity_enabled', 'servosity:provision-backups'],
        ];
    }

    private function configureAndMapAll(): void
    {
        Setting::setValue('screenconnect_base_url', 'https://sc.example.test');
        Setting::setValue('screenconnect_webhook_secret', 'fixture-webhook-secret');
        Setting::setEncrypted('servosity_api_token', 'fixture-servosity-token');

        $client = Client::factory()->create(['servosity_company_id' => 4242]);
        Asset::factory()->create([
            'client_id' => $client->id,
            'servosity_backup_enabled' => true,
            'servosity_dr_backup_id' => null,
        ]);
    }

    private function scheduleFiltersPass(string $command): bool
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_ends_with($event->command ?? '', " {$command}"));
        $this->assertCount(1, $events, "premise: one {$command} schedule");

        return $events->first()->filtersPass($this->app);
    }

    #[DataProvider('scheduledCommands')]
    public function test_schedule_skips_while_switched_off(string $setting, string $command): void
    {
        $this->configureAndMapAll();
        Setting::setValue($setting, '0');

        $this->assertFalse($this->scheduleFiltersPass($command), "{$command} must not start while {$setting}=0");
    }

    #[DataProvider('scheduledCommands')]
    public function test_schedule_positive_control_runs_while_switched_on(string $setting, string $command): void
    {
        $this->configureAndMapAll();
        Setting::setValue($setting, '1');

        $this->assertTrue($this->scheduleFiltersPass($command), "{$command} must start while {$setting}=1 and its other conditions hold");
    }
}
