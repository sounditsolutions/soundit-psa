<?php

namespace Tests\Feature\Integrations;

use App\Enums\ClientStage;
use App\Models\Asset;
use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\Setting;
use App\Models\User;
use App\Services\Litsrmm\LitsrmmAssetSyncService;
use App\Services\Litsrmm\LitsrmmClient;
use App\Services\SyncResult;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Mockery;
use Tests\TestCase;

/**
 * The three ways a LITSRMM device sync starts, and the seats it leaves on the
 * client page:
 *
 *  - the schedule, every four hours beside level:sync-devices;
 *  - "Sync devices" on the client page's LITSRMM card, for that client only;
 *  - "Sync all devices" on Settings > Integrations > LITSRMM, admins only.
 *
 * The sync itself is LitsrmmAssetSyncTest's; here it is a mock, so these tests
 * pin who may start it, for which client, and what the operator is told.
 */
class LitsrmmSyncControlsTest extends TestCase
{
    use RefreshDatabase;

    private const VENDOR_CLIENT = '2f12dedb-b4cf-48cf-86bb-9f63b5b8f7af';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setEncrypted('litsrmm_api_key', 'fake-'.bin2hex(random_bytes(8)));
        Setting::setValue('litsrmm_base_url', 'https://litsrmm.test');
    }

    private function mapped(): Client
    {
        return Client::factory()->create([
            'stage' => ClientStage::Active,
            'is_active' => true,
            'litsrmm_client_id' => self::VENDOR_CLIENT,
        ]);
    }

    /** @return Mockery\MockInterface the service, bound so controllers receive it */
    private function syncService(): Mockery\MockInterface
    {
        $service = Mockery::mock(LitsrmmAssetSyncService::class);
        $this->app->instance(LitsrmmAssetSyncService::class, $service);

        return $service;
    }

    private static function created(int $n): SyncResult
    {
        $result = new SyncResult;
        $result->created = $n;

        return $result;
    }

    // ---- the schedule ----

    private function event(): Event
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $e) => str_contains((string) $e->command, 'litsrmm:sync-devices'));
        $this->assertCount(1, $events, 'exactly one litsrmm:sync-devices schedule');

        return $events->first();
    }

    public function test_it_is_scheduled_every_four_hours_without_overlap(): void
    {
        $event = $this->event();

        $this->assertSame('0 */4 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    /** Review of #4485: not live until a supervised manual run has been read. */
    public function test_the_schedule_is_off_by_default(): void
    {
        $this->mapped();

        $this->assertFalse($this->event()->filtersPass($this->app), 'mapped and configured, but the schedule was never switched on');
    }

    public function test_the_schedule_runs_only_when_switched_on_configured_and_mapped(): void
    {
        Setting::setValue('litsrmm_sync_schedule_enabled', '1');
        $this->assertFalse($this->event()->filtersPass($this->app), 'no client mapped: nothing to do');

        $this->mapped();
        $this->assertTrue($this->event()->filtersPass($this->app));

        Setting::setValue('litsrmm_enabled', '0');
        $this->assertFalse($this->event()->filtersPass($this->app), 'switched off means off');
    }

    /** #5360: a failed scheduled run leaves a fixed log line; a clean one does not. */
    public function test_a_failed_scheduled_run_writes_one_status_only_log_line(): void
    {
        Log::spy();
        $event = $this->event();

        $event->finish($this->app, 0);
        Log::shouldNotHaveReceived('error');

        $event->finish($this->app, 1);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context = []) => $message === '[LitsrmmSyncDevices] scheduled run failed' && $context === [])
            ->once();
    }

    /** #5690: the schedule never passes the admin's per-run accept (#5577). CONTROL (#5792): no change in #5745 made it red. */
    public function test_the_schedule_never_passes_the_accept(): void
    {
        $command = (string) $this->event()->command;

        $this->assertStringNotContainsString('accept-short-read', $command);
        $this->assertMatchesRegularExpression("/litsrmm:sync-devices'?\\s*$/", $command, 'the scheduled command carries no option at all');
    }

    /**
     * #5853 / #5844: the scheduled path itself. The schedule event's own
     * command line (what schedule:run hands the shell) is run through the
     * console kernel in this process, with the real sync over a fake
     * transport, after the event's own filters pass. Nothing is enabled
     * outside this test's database. The log channel uses a JSON formatter,
     * under which a line break in a message is escaped: the code is still
     * found as the 'refusal_code' field and passes when fed back.
     */
    public function test_a_scheduled_run_logs_its_refusal_code_as_a_field_under_a_json_formatter(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        $client = $this->mapped();
        $asset = Asset::factory()->create(['client_id' => $client->id, 'litsrmm_device_id' => '8f14e45f-ceea-467a-9f38-000000000001']);
        $handler = fn ($request) => Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode(['devices' => [], 'nextCursor' => null])));
        $this->app->instance(LitsrmmAssetSyncService::class, new LitsrmmAssetSyncService(new LitsrmmClient([
            'api_key' => 'fake-'.bin2hex(random_bytes(8)),
            'base_url' => 'https://litsrmm.test',
            'handler' => HandlerStack::create($handler),
            'request_timeout' => 5,
        ])));
        $path = storage_path('logs/litsrmm-l5-json-'.bin2hex(random_bytes(4)).'.log');
        config([
            'logging.channels.l5json' => ['driver' => 'single', 'path' => $path, 'level' => 'debug', 'formatter' => \Monolog\Formatter\JsonFormatter::class],
            'logging.default' => 'l5json',
        ]);
        Setting::setValue('litsrmm_sync_schedule_enabled', '1');
        $event = $this->event();
        $this->assertTrue($event->filtersPass($this->app));
        $line = $event->buildCommand();
        $this->assertSame(1, preg_match("/'?artisan'?\\s+(litsrmm:sync-devices)\\s*>/", $line, $m), $line);

        try {
            $exit = Artisan::call($m[1]);
            $event->finish($this->app, $exit);
            $records = array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($path))));
        } finally {
            @unlink($path);
        }

        $this->assertSame(1, $exit, 'a refused client fails the scheduled run');
        $codes = array_values(array_filter(array_map(fn ($r) => $r['context']['refusal_code'] ?? null, $records)));
        $expected = "{$client->id}:a:0:1:1:0:0:0:".LitsrmmAssetSyncService::deviceSetDigest([], [$asset->id]);
        $this->assertSame([$expected], $codes);
        $this->assertContains('[LitsrmmSyncDevices] scheduled run failed', array_column($records, 'message'));

        $this->assertSame(0, Artisan::call('litsrmm:sync-devices', ['--accept-short-read' => $codes]), 'the field, as logged, is accepted');
        $this->assertNull($asset->fresh()->litsrmm_device_id);
    }

    public function test_the_scheduled_output_is_not_written_to_a_file(): void
    {
        // The skip lines it prints carry hostnames (C-56).
        $this->assertSame('/dev/null', $this->event()->output);
    }

    // ---- the client page ----

    public function test_the_client_page_button_syncs_that_client_only(): void
    {
        $client = $this->mapped();
        $this->syncService()->shouldReceive('sync')->once()
            ->withArgs(fn (...$args) => $args[0]?->id === $client->id && ($args[1] ?? []) === [])
            ->andReturn(self::created(3));

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('clients.show', $client))
            ->post(route('clients.litsrmm.sync', $client))
            ->assertRedirect(route('clients.show', $client))
            ->assertSessionHas('success', fn ($message) => str_contains($message, '3 created'));
    }

    public function test_the_client_page_button_reports_what_went_wrong(): void
    {
        $client = $this->mapped();
        $result = new SyncResult;
        $result->recordError('Failed to read LITSRMM devices: boom');
        $result->recordSkipped('SHARED: more than one asset or device could be this machine; not linked, not created');
        $this->syncService()->shouldReceive('sync')->once()->andReturn($result);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('clients.litsrmm.sync', $client))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'boom') && str_contains($message, 'SHARED'));
    }

    public function test_the_button_shows_a_refusal_code_as_its_last_line_and_passes_no_accept(): void
    {
        // #5786 / #5690: the button run's message ends with the code on a
        // line of its own, nothing after it; the button itself never accepts.
        $client = $this->mapped();
        $code = "{$client->id}:b:1:5:4:5:1:0:d0123456789";
        $result = new SyncResult;
        $result->recordError("client {$client->id}: refused as a short read (rule b: fixture). If the change is real, re-run litsrmm:sync-devices with --accept-short-read set to the code that follows, pasted exactly (a bare client id is rejected):\n{$code}");
        $result->recordSkipped('WORKSTATION-1: a fixture skip');
        $this->syncService()->shouldReceive('sync')->once()
            ->withArgs(fn (...$args) => $args[0]?->id === $client->id && ($args[1] ?? []) === [])
            ->andReturn($result);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('clients.litsrmm.sync', $client))
            ->assertSessionHas('error', function ($message) use ($code) {
                $lines = explode("\n", $message);

                return end($lines) === $code && LitsrmmAssetSyncService::isRefusalCode(end($lines));
            });
    }

    public function test_the_settings_button_passes_no_accept_whatever_the_last_run_refused(): void
    {
        // #5690: a refusal shown on the page is display only. CONTROL
        // (#5792): green at base 6144b475 too.
        $this->mapped();
        $this->syncService()->shouldReceive('sync')->once()
            ->withArgs(fn (...$args) => count($args) <= 2 && ($args[0] ?? null) === null && ($args[1] ?? []) === [])
            ->andReturn(new SyncResult);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('settings.integrations.litsrmm.sync-devices'), ['accept_short_read' => '1:a:0:1:1:0:0:0:d0123456789', '--accept-short-read' => '1:a:0:1:1:0:0:0:d0123456789'])
            ->assertSessionHas('success');
    }

    public function test_an_unmapped_client_is_refused_without_reading(): void
    {
        $client = Client::factory()->create(['stage' => ClientStage::Active, 'is_active' => true]);
        $this->syncService()->shouldNotReceive('sync');

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('clients.litsrmm.sync', $client))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'not linked to LITSRMM'));
    }

    public function test_a_switched_off_integration_is_refused_without_reading(): void
    {
        $client = $this->mapped();
        Setting::setValue('litsrmm_enabled', '0');
        $this->syncService()->shouldNotReceive('sync');

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('clients.litsrmm.sync', $client))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'switched off'));
    }

    /** Review of #4485: a vendor read plus asset and seat writes is an admin's call, as in Settings. */
    public function test_the_client_page_button_is_admins_only(): void
    {
        $client = $this->mapped();
        $this->syncService()->shouldNotReceive('sync');

        $this->actingAs(User::factory()->tech()->create())
            ->post(route('clients.litsrmm.sync', $client))
            ->assertForbidden();
    }

    public function test_a_tech_does_not_see_the_client_page_button(): void
    {
        $client = $this->mapped();

        $this->actingAs(User::factory()->tech()->create())
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertDontSee(route('clients.litsrmm.sync', $client));
    }

    public function test_the_client_page_button_needs_a_login(): void
    {
        $client = $this->mapped();
        $this->syncService()->shouldNotReceive('sync');

        $this->post(route('clients.litsrmm.sync', $client))->assertRedirect(route('login'));
    }

    public function test_the_litsrmm_card_carries_the_button_and_its_seats(): void
    {
        $client = $this->mapped();
        $type = LicenseType::create(['vendor' => 'litsrmm', 'vendor_sku_id' => 'rmm_workstation', 'name' => 'LITSRMM — Workstation', 'is_active' => true]);
        License::create(['license_type_id' => $type->id, 'client_id' => $client->id, 'vendor_ref' => self::VENDOR_CLIENT, 'quantity' => 3, 'status' => 'active', 'synced_at' => now()]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertSee(route('clients.litsrmm.sync', $client))
            ->assertSee('3 licenses');
    }

    public function test_an_unlinked_client_page_has_no_sync_button(): void
    {
        $client = Client::factory()->create(['stage' => ClientStage::Active, 'is_active' => true]);

        $this->actingAs(User::factory()->tech()->create())
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertDontSee(route('clients.litsrmm.sync', $client));
    }

    // ---- Settings > Integrations ----

    public function test_the_settings_button_runs_a_full_sync_for_an_admin(): void
    {
        $this->mapped();
        $this->syncService()->shouldReceive('sync')->once()
            ->withArgs(fn (...$args) => ($args[0] ?? null) === null && ($args[1] ?? []) === [])
            ->andReturn(self::created(2));

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('settings.integrations.litsrmm.sync-devices'))
            ->assertSessionHas('success', fn ($message) => str_contains($message, '2 created'));
    }

    public function test_the_settings_button_is_admins_only(): void
    {
        $this->mapped();
        $this->syncService()->shouldNotReceive('sync');

        $this->actingAs(User::factory()->tech()->create())
            ->post(route('settings.integrations.litsrmm.sync-devices'))
            ->assertForbidden();
    }

    public function test_an_admin_switches_the_schedule_on_and_off(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('settings.integrations.litsrmm.schedule'), ['enabled' => '1'])
            ->assertSessionHas('success');
        $this->assertSame('1', Setting::getValue('litsrmm_sync_schedule_enabled'));

        $this->actingAs($admin)->post(route('settings.integrations.litsrmm.schedule'), [])
            ->assertSessionHas('success');
        $this->assertSame('0', Setting::getValue('litsrmm_sync_schedule_enabled'));
    }

    public function test_the_schedule_switch_is_admins_only(): void
    {
        $this->actingAs(User::factory()->tech()->create())
            ->post(route('settings.integrations.litsrmm.schedule'), ['enabled' => '1'])
            ->assertForbidden();
        $this->assertNotSame('1', Setting::getValue('litsrmm_sync_schedule_enabled'));
    }

    public function test_the_settings_card_offers_the_schedule_switch(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee(route('settings.integrations.litsrmm.schedule'))
            ->assertSee('Sync every 4 hours');
    }

    public function test_the_settings_card_offers_the_button_only_when_it_can_run(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('settings.integrations'))
            ->assertOk()->assertSee(route('settings.integrations.litsrmm.sync-devices'));

        Setting::setValue('litsrmm_enabled', '0');
        $this->actingAs($admin)->get(route('settings.integrations'))
            ->assertOk()->assertDontSee(route('settings.integrations.litsrmm.sync-devices'));
    }

    // ---- the asset page's RMM and Last Synced rows ----

    private function assetPage(Asset $asset): string
    {
        return $this->actingAs(User::factory()->tech()->create())
            ->get(route('assets.show', $asset))
            ->assertOk()
            ->getContent();
    }

    /** Text of the details-table cell under $heading, tags stripped and whitespace collapsed. */
    private static function cell(string $html, string $heading): string
    {
        $found = preg_match('#<th class="text-muted">'.preg_quote($heading, '#').'</th>\s*<td>(.*?)</td>#s', $html, $m);
        self::assertSame(1, $found, "the asset page has a {$heading} row");

        return trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
    }

    public function test_a_litsrmm_asset_names_its_rmm_and_when_it_last_synced(): void
    {
        // Seen in testing: #131, #132 and #135 read "-" under RMM and "Never"
        // under Last Synced, because both rows only knew Ninja, Level,
        // ScreenConnect and Tactical.
        $asset = Asset::factory()->create([
            'client_id' => $this->mapped()->id,
            'litsrmm_device_id' => '8f14e45f-ceea-467a-9f38-000000000001',
            'litsrmm_synced_at' => now()->subMinutes(5),
        ]);

        $html = $this->assetPage($asset);

        $this->assertSame('LITSRMM', self::cell($html, 'RMM'));
        $this->assertSame('5 minutes ago', self::cell($html, 'Last Synced'));
    }

    public function test_an_asset_mid_handover_names_both_rmms(): void
    {
        $asset = Asset::factory()->create([
            'client_id' => $this->mapped()->id,
            'level_id' => 'lvl-1',
            'level_synced_at' => now()->subHours(3),
            'litsrmm_device_id' => '8f14e45f-ceea-467a-9f38-000000000001',
            'litsrmm_synced_at' => now()->subMinutes(5),
        ]);

        $html = $this->assetPage($asset);

        $this->assertSame('Level | LITSRMM', self::cell($html, 'RMM'));
    }

    public function test_an_asset_with_no_rmm_still_reads_a_dash_and_never(): void
    {
        $asset = Asset::factory()->create(['client_id' => $this->mapped()->id]);

        $html = $this->assetPage($asset);

        $this->assertSame('-', self::cell($html, 'RMM'));
        $this->assertSame('Never', self::cell($html, 'Last Synced'));
    }

    // ---- the command ----

    public function test_the_command_can_sync_one_client(): void
    {
        $client = $this->mapped();
        $this->syncService()->shouldReceive('sync')->once()
            ->withArgs(fn (?Client $only) => $only?->id === $client->id)
            ->andReturn(self::created(1));

        $this->artisan('litsrmm:sync-devices', ['--client' => $client->id])
            ->expectsOutputToContain('1 created')
            ->assertSuccessful();
    }

    public function test_the_command_refuses_an_unknown_client(): void
    {
        $this->mapped();
        $this->syncService()->shouldNotReceive('sync');

        $this->artisan('litsrmm:sync-devices', ['--client' => 999999])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    }
}
