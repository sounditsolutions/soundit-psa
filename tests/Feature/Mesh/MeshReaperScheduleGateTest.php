<?php

namespace Tests\Feature\Mesh;

use App\Models\Setting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #6212: the hourly mesh:reap-allow-rules schedule must not skip a blank
 * or unusable stored key silently. The gate reads only whether Mesh is on
 * and whether a key is stored; with a stored key the command runs, and
 * for a key the PSA does not send it logs a warning that no rules were
 * reaped, every hour. Only no stored key at all, or Mesh switched off,
 * skips (as before #6205).
 *
 * Both halves are driven: the schedule event's own filter (filtersPass())
 * and the command it runs, whose warning is read with its level.
 *
 * G-5, #6295: on these rows the command refuses before the reaper is
 * called, so no Mesh client is used. Http::preventStrayRequests() guards
 * Laravel's Http facade only, not the reaper's Guzzle-backed
 * MeshWriteClient, and is not what keeps these rows off the network.
 * Synthetic data (G-13): SECRET_FIXTURE keys.
 */
class MeshReaperScheduleGateTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{0: string, 1: string}> level, message */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = [$e->level, $e->message];
        });
    }

    private function reaperFiltersPass(): bool
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'mesh:reap-allow-rules'));
        $this->assertCount(1, $events, 'premise: one reaper schedule');

        return $events->first()->filtersPass($this->app);
    }

    /** @return array<string, array{0: string|null, 1: string, 2: bool}> stored key, mesh_enabled, runs */
    public static function gates(): array
    {
        return [
            'no key stored' => [null, '1', false],
            'Mesh switched off, key stored' => ['SECRET_FIXTURE-6212', '0', false],
            'usable key' => ['SECRET_FIXTURE-6212', '1', true],
            // #6212: before #6205 these ran and logged; they must not be
            // skipped silently.
            'blank key' => ['   ', '1', true],
            'tab key' => ["\t", '1', true],
            "'0' key" => ['0', '1', true],
        ];
    }

    #[DataProvider('gates')]
    public function test_the_schedule_gate_runs_whenever_a_key_is_stored(?string $stored, string $enabled, bool $runs): void
    {
        Setting::setValue('mesh_enabled', $enabled);
        if ($stored !== null) {
            Setting::setEncrypted('mesh_api_key', $stored);
        }

        $this->assertSame($runs, $this->reaperFiltersPass());
    }

    /** @return array<string, array{0: string, 1: string}> stored key, the command's error */
    public static function unsendable(): array
    {
        return [
            'blank key' => ['   ', 'Mesh is not configured. Add the API key in Settings → Integrations.'],
            "'0' key" => ['0', 'Mesh has a stored API key the PSA does not send (zero or true). Replace it in Settings → Integrations.'],
        ];
    }

    /**
     * #6212: the run the gate lets through logs a warning; #6213: a stored
     * '0' is named set but unusable, not 'not configured'.
     */
    #[DataProvider('unsendable')]
    public function test_the_command_logs_a_warning_for_a_stored_key_it_cannot_send(string $stored, string $error): void
    {
        Setting::setValue('mesh_enabled', '1');
        Setting::setEncrypted('mesh_api_key', $stored);
        $this->assertTrue($this->reaperFiltersPass(), 'premise: the schedule runs it');

        $this->artisan('mesh:reap-allow-rules')
            ->expectsOutput($error)
            ->assertExitCode(1);

        $this->assertSame([[
            'warning',
            '[MeshReapAllowRules] '.$error.' No allow rules were reaped; expired rules are not being removed and may still be live upstream.',
        ]], $this->logged);
    }
}
