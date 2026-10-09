<?php

namespace Tests\Feature\Mesh;

use App\Models\Setting;
use App\Models\User;
use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshWriteClient;
use App\Services\Wiki\Mining\WikiRedactor;
use App\Support\MeshConfig;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Card 6ac8a1ff (burner mesh b6k): the stored-key states the #6283
 * residuals name, on MeshConfig and the surfaces that read it.
 *
 *  - #6296: a mesh_api_key row that does not decrypt throws nowhere: not
 *    in the reaper schedule filter, not in any MeshConfig helper. It reads
 *    as set but unusable and is logged status-only (class, no row text).
 *  - #6288: a stored key holding a CR or LF reads as set but unusable on
 *    the operator surfaces and gates, as every client refuses it before
 *    send.
 *  - #6289: a past successful test does not make an unusable key's badge
 *    'Connected'.
 *  - #6297: mesh:sync-licenses logs its refusal (the schedule runs it in
 *    the background, where console output is discarded).
 *  - #6302: the 'set but unusable' text survives the audit redactor.
 *
 * G-5: no row reaches a Mesh client: the container's clients are Mockery
 * doubles that fail on any call, every command and page row refuses at its
 * isConfigured() guard first, and the base URL is a reserved example.test
 * host. Synthetic data (G-13): SECRET_FIXTURE keys.
 */
class MeshKeyStateB6kTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{0: string, 1: string}> level, message */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MeshClient::class, Mockery::mock(MeshClient::class));
        $this->app->instance(MeshWriteClient::class, Mockery::mock(MeshWriteClient::class));
        Setting::setValue('mesh_base_url', 'https://mesh-b6k.example.test');
        Setting::setValue('mesh_enabled', '1');
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = [$e->level, $e->message];
        });
    }

    /** A row that is not a ciphertext under this APP_KEY (as after a key rotation). */
    private const UNDECRYPTABLE_ROW = 'SECRET_FIXTURE-not-a-ciphertext-6296';

    private const UNDECRYPTABLE_WARNING = '[MeshConfig] The stored Mesh API key could not be decrypted (Illuminate\Contracts\Encryption\DecryptException); it is treated as unusable and no Mesh call is made with it.';

    private function storeUndecryptable(): void
    {
        Setting::setValue('mesh_api_key', self::UNDECRYPTABLE_ROW);
    }

    private function reaperFiltersPass(): bool
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'mesh:reap-allow-rules'));
        $this->assertCount(1, $events, 'premise: one reaper schedule');

        return $events->first()->filtersPass($this->app);
    }

    /** @return list<string> */
    private function loggedText(): array
    {
        return array_map(fn (array $l): string => $l[1], $this->logged);
    }

    /**
     * #6296: the reaper schedule filter does not throw on a row that does
     * not decrypt (schedule:run evaluates it outside its per-event catch,
     * so a throw would end the whole tick); a row is stored, so it runs.
     */
    public function test_the_reaper_schedule_gate_does_not_throw_on_an_undecryptable_key(): void
    {
        $this->storeUndecryptable();

        $this->assertTrue($this->reaperFiltersPass());
    }

    /** #6296: the mesh:sync-licenses schedule has no filter to throw in; its command is driven below. */
    public function test_the_sync_licenses_schedule_has_no_key_filter(): void
    {
        $this->storeUndecryptable();
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'mesh:sync-licenses'));
        $this->assertCount(1, $events, 'premise: one license sync schedule');

        $this->assertTrue($events->first()->filtersPass($this->app));
    }

    /**
     * #6296: every MeshConfig key helper fails closed on a row that does
     * not decrypt: no throw, the key unusable, a status-only warning that
     * quotes neither the row nor the exception's message.
     */
    public function test_each_mesh_config_helper_fails_closed_on_an_undecryptable_key(): void
    {
        $this->storeUndecryptable();

        $this->assertNull(MeshConfig::get('api_key'));
        $this->assertTrue(MeshConfig::hasStoredKey());
        $this->assertFalse(MeshConfig::isConfigured());
        $this->assertTrue(MeshConfig::isKeyStoredButUnusable());
        $this->assertSame(MeshConfig::UNDECRYPTABLE_KEY, MeshConfig::storedKeyRefusal());
        $this->assertSame('it could not be decrypted', MeshConfig::storedKeyNote());
        $this->assertSame(
            'Mesh has a stored API key the PSA does not send (it could not be decrypted). Replace it in Settings → Integrations.',
            MeshConfig::notConfiguredText('missing'),
        );

        $this->assertNotSame([], $this->logged, 'premise: something was logged');
        foreach ($this->logged as [$level, $message]) {
            $this->assertSame(['warning', self::UNDECRYPTABLE_WARNING], [$level, $message]);
        }
        $this->assertStringNotContainsString('SECRET_FIXTURE', implode("\n", $this->loggedText()));
        $this->assertStringNotContainsString('payload', strtolower(implode("\n", $this->loggedText())), 'not the exception message');
    }

    /** #6296: the reaper command the gate lets through refuses and logs, without throwing. */
    public function test_the_reaper_command_refuses_an_undecryptable_key_and_logs(): void
    {
        $this->storeUndecryptable();
        $text = 'Mesh has a stored API key the PSA does not send (it could not be decrypted). Replace it in Settings → Integrations.';

        $this->artisan('mesh:reap-allow-rules')->expectsOutput($text)->assertExitCode(1);

        $this->assertContains(['warning', '[MeshReapAllowRules] '.$text.' No allow rules were reaped; expired rules are not being removed and may still be live upstream.'], $this->logged);
    }

    /** @return array<string, array{0: string|null, 1: string}> stored key (null: undecryptable row), refusal text */
    public static function unusableStored(): array
    {
        $tail = '. Replace it in Settings → Integrations.';

        return [
            "'0'" => ['0', 'Mesh has a stored API key the PSA does not send (zero or true)'.$tail],
            'a CR' => ["SECRET_FIXTURE\r6288", 'Mesh has a stored API key the PSA does not send (it holds a character an HTTP header cannot carry)'.$tail],
            'an LF' => ["SECRET_FIXTURE\n6288", 'Mesh has a stored API key the PSA does not send (it holds a character an HTTP header cannot carry)'.$tail],
            'undecryptable' => [null, 'Mesh has a stored API key the PSA does not send (it could not be decrypted)'.$tail],
        ];
    }

    private function storeKey(?string $key): void
    {
        $key === null ? $this->storeUndecryptable() : Setting::setEncrypted('mesh_api_key', $key);
    }

    /**
     * #6297: mesh:sync-licenses logs its refusal as a warning, as the
     * reaper does (#6212); #6288: a CR or LF key is refused as set but
     * unusable there too.
     */
    #[DataProvider('unusableStored')]
    public function test_the_sync_licenses_command_logs_its_refusal(?string $key, string $text): void
    {
        $this->storeKey($key);

        $this->artisan('mesh:sync-licenses')->expectsOutput($text)->assertExitCode(1);

        $this->assertContains(['warning', '[MeshSyncLicenses] '.$text.' No Mesh licenses were synced; license counts are not being updated.'], $this->logged);
    }

    /** #6297: a missing key is logged too, with the 'not configured' text. */
    public function test_the_sync_licenses_command_logs_a_missing_key(): void
    {
        $text = 'Mesh is not configured. Add API key in Settings → Integrations.';

        $this->artisan('mesh:sync-licenses')->expectsOutput($text)->assertExitCode(1);

        $this->assertSame([['warning', '[MeshSyncLicenses] '.$text.' No Mesh licenses were synced; license counts are not being updated.']], $this->logged);
    }

    /**
     * #6288: a CR or LF key is not configured on the gates and reads as
     * set but unusable, with the header rule's reason.
     */
    public function test_a_key_holding_a_cr_or_lf_is_set_but_unusable(): void
    {
        foreach (["SECRET_FIXTURE\r6288", "SECRET_FIXTURE\n6288", "SECRET_FIXTURE\r\n6288"] as $key) {
            Setting::setEncrypted('mesh_api_key', $key);
            $this->assertFalse(MeshConfig::isConfigured(), json_encode($key));
            $this->assertTrue(MeshConfig::isKeyStoredButUnusable(), json_encode($key));
            $this->assertSame('holds a character an HTTP header cannot carry', MeshConfig::storedKeyRefusal(), json_encode($key));
            $this->assertTrue($this->reaperFiltersPass(), 'the reaper runs and logs it (#6212)');
        }
        // Positive control: a usable key is configured, with no refusal.
        Setting::setEncrypted('mesh_api_key', 'SECRET_FIXTURE-6288');
        $this->assertTrue(MeshConfig::isConfigured());
        $this->assertFalse(MeshConfig::isKeyStoredButUnusable());
        $this->assertNull(MeshConfig::storedKeyRefusal());
    }

    /** The Mesh card's header and key input, from the settings page. */
    private function meshCard(): string
    {
        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.integrations'))->assertOk()->getContent();
        $start = (int) strpos($html, 'Mesh Email Security');

        return substr($html, $start, (int) strpos($html, 'id="mesh_base_url"', $start) - $start);
    }

    /**
     * #6289: with a past successful test recorded (mesh_connected_at set),
     * an unusable stored key reads 'Key unusable', not 'Connected'; #6288
     * and #6296: so do a CR or LF key and a row that does not decrypt.
     */
    #[DataProvider('unusableStored')]
    public function test_the_badge_is_not_connected_for_an_unusable_key_after_a_past_test(?string $key, string $text): void
    {
        Setting::setValue('mesh_connected_at', '2026-10-01 12:00:00');
        $this->storeKey($key);

        $card = $this->meshCard();

        $this->assertStringContainsString('id="mesh_api_key"', $card, 'premise: the Mesh card');
        $this->assertStringContainsString('Key unusable</span>', $card);
        $this->assertStringNotContainsString('Connected</span>', $card);
        $this->assertStringContainsString('placeholder="Stored key is unusable; enter a new one"', $card);
    }

    /** #6289 positive control: a usable key with a past test still reads 'Connected'. */
    public function test_the_badge_is_connected_for_a_usable_key_after_a_past_test(): void
    {
        Setting::setValue('mesh_connected_at', '2026-10-01 12:00:00');
        Setting::setEncrypted('mesh_api_key', 'SECRET_FIXTURE-6289');

        $card = $this->meshCard();

        $this->assertStringContainsString('Connected</span>', $card);
        $this->assertStringNotContainsString('Key unusable', $card);
    }

    /**
     * #6302: the operator text for a stored unusable key is not changed by
     * the audit redactor (WikiRedactor, which the executor's audit summary
     * goes through via ActionRedactor::redactString()). Positive control:
     * the old 'API key is set, …' wording is redacted by it. Whether a
     * log channel redacts is not driven here: config/logging.php, read,
     * names no redacting processor.
     */
    #[DataProvider('unusableStored')]
    public function test_the_unusable_key_text_survives_the_audit_redactor(?string $key, string $text): void
    {
        $this->storeKey($key);
        $redactor = new WikiRedactor;

        $this->assertSame($text, MeshConfig::notConfiguredText('missing'));
        $this->assertSame($text, $redactor->redact($text));
        $old = 'The Mesh API key '.MeshClient::UNUSABLE_KEY.'.';
        $this->assertNotSame($old, $redactor->redact($old), 'positive control: the old wording is redacted');
    }
}
