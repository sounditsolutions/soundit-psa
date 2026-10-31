<?php

namespace Tests\Feature\Integrations;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #7926: switching ScreenConnect or Servosity off stops the scheduler starting
 * their commands, as OFF=OFF (card YouDehkS) already does for Level, Control D
 * and Zorus. Each switched-off case in the scheduledCommands pair has a
 * switched-on positive control on the same schedule with every other condition
 * met, so the filter cannot pass or fail vacuously. The switch is checked
 * before the config, as for Control D and Zorus: a switched-off schedule never
 * reads its credentials, so a Servosity token that no longer decrypts cannot
 * break schedule:run.
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

    /** @return array<string, array{0: string, 1: string, 2: string}> switch setting, command, config key */
    public static function switchFirstCommands(): array
    {
        return [
            'screenconnect:count-licenses' => ['screenconnect_enabled', 'screenconnect:count-licenses', 'screenconnect_base_url'],
            'servosity:sync-licenses' => ['servosity_enabled', 'servosity:sync-licenses', 'servosity_api_token'],
            'servosity:provision-backups' => ['servosity_enabled', 'servosity:provision-backups', 'servosity_api_token'],
        ];
    }

    #[DataProvider('switchFirstCommands')]
    public function test_switched_off_schedule_does_not_read_its_config(string $setting, string $command, string $configKey): void
    {
        $this->configureAndMapAll();
        Setting::setValue($setting, '0');

        $readKeys = [];
        DB::listen(function ($query) use (&$readKeys) {
            foreach ($query->bindings as $binding) {
                $readKeys[] = $binding;
            }
        });

        $this->assertFalse($this->scheduleFiltersPass($command));
        $this->assertContains($setting, $readKeys, "premise: the filter read {$setting}");
        $this->assertNotContains($configKey, $readKeys, "{$command} must check {$setting} before reading {$configKey}");
    }

    public function test_switched_off_servosity_skips_a_token_that_no_longer_decrypts(): void
    {
        $this->configureAndMapAll();
        Setting::setValue('servosity_api_token', 'not-a-decryptable-payload');
        Setting::setValue('servosity_enabled', '0');

        $this->assertFalse($this->scheduleFiltersPass('servosity:provision-backups'));
        $this->assertFalse($this->scheduleFiltersPass('servosity:sync-licenses'));
    }

    public function test_screenconnect_switch_never_saved_reads_as_off(): void
    {
        $this->configureAndMapAll();
        $this->assertNull(Setting::getValue('screenconnect_enabled'), 'premise: no screenconnect_enabled row');

        $this->assertFalse($this->scheduleFiltersPass('screenconnect:count-licenses'), 'the switch defaults to 0 (see the INSTALL upgrading note)');
    }
}
