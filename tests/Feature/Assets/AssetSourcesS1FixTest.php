<?php

namespace Tests\Feature\Assets;

use App\Models\AssetSourceSync;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalDeviceSyncService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Asset Sources S1-fix (Jeeves ruling, card nfuBmADp, 2026-10-30 08:42Z):
 *  1. after an ok Sync now, only the chips that read wrote carry its age;
 *  2. a failed Tactical read is classified by the HTTP status it carried back
 *     (404, 429, any other status), and transport only when no response came.
 * Synthetic data only (G-13). Tactical is Guzzle bound to a scripted queue; the
 * Http facade refuses every call (G-5, no catch-all fake).
 */
class AssetSourcesS1FixTest extends TestCase
{
    use AssetSourcesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([]);
        $this->configureAllSources();
    }

    private function bindTactical(array $queue): void
    {
        $http = new GuzzleClient([
            'base_uri' => 'https://tactical-api.example.test/',
            'handler' => HandlerStack::create(new MockHandler($queue)),
        ]);
        $this->app->instance(TacticalClient::class, new TacticalClient($http));
    }

    private function chipNote(string $html, string $label): string
    {
        $start = strpos($html, 'data-source-panel="tactical"');
        $this->assertNotFalse($start, 'tactical panel is rendered');
        $this->assertSame(1, preg_match('/>'.preg_quote($label, '/').'<\/div>\s*<div class="fw-semibold">[^<]*<\/div>\s*<div class="small text-muted" data-chip-note>([^<]*)</', substr($html, $start), $m), "chip {$label}");

        return trim(html_entity_decode($m[1]));
    }

    // ── 1. as-of only on what the read wrote ──

    public function test_ok_sync_that_leaves_last_user_and_checks_unwritten_does_not_date_them_just_now(): void
    {
        $asset = $this->assetLinkedEverywhere([], [
            'last_user' => 'EXAMPLE\\sam',
            'has_patches_pending' => true,
            'checks_failing' => 1,
            'checks_total' => 4,
            'synced_at' => now()->subDays(3),
        ]);
        // getAgent with no logged_username, no has_patches_pending, no needs_reboot and
        // a null checks summary: the read writes status + synced_at, nothing for those chips.
        $this->bindTactical([new PsrResponse(200, [], json_encode(['status' => 'online', 'checks' => null]))]);
        $user = User::factory()->tech()->create();

        $this->actingAs($user)->postJson(route('assets.sources.sync', [$asset, 'tactical']))
            ->assertOk()->assertJson(['outcome' => 'ok', 'reason' => 'ok']);
        $ta = $asset->tacticalAsset->refresh();
        $this->assertTrue($ta->synced_at->greaterThan(now()->subMinute()), 'the read stamped synced_at now');
        $this->assertSame('EXAMPLE\\sam', $ta->last_user, 'last_user left as the earlier sync wrote it');
        $this->assertSame(1, $ta->checks_failing, 'checks left as the earlier sync wrote them');

        $html = $this->actingAs($user)->get(route('assets.show', $asset))->assertOk()->getContent();

        // Positive control: the chip this read did write carries the fresh age.
        $this->assertMatchesRegularExpression('/^as of \d+ seconds? ago$/', $this->chipNote($html, 'Agent status'));
        foreach (['Last user', 'Checks', 'Reboot', 'Updates'] as $label) {
            $note = $this->chipNote($html, $label);
            $this->assertStringNotContainsString('as of', $note, $label);
            $this->assertDoesNotMatchRegularExpression('/seconds? ago|just now/', $note, $label);
        }
    }

    // ── 2. failure reason from the HTTP status: one test per class ──

    /** @return array<string, array{0: \Throwable|PsrResponse, 1: string, 2: ?int, 3: string, 4: string}> */
    public static function failureClasses(): array
    {
        return [
            'transport' => [new ConnectException('unreachable', new PsrRequest('GET', 'agents/x/')), 'transport', null,
                'Could not reach the vendor.', 'Could not reach the agent — showing the last sync.'],
            'http 404' => [new PsrResponse(404), 'not_found_at_vendor', 404,
                'The vendor did not return this device; the link is kept.', 'The vendor did not return this device; the link is kept.'],
            'http 429' => [new PsrResponse(429), 'vendor_429', 429,
                'The vendor is rate-limiting requests.', 'The vendor is rate-limiting requests.'],
            'http 401' => [new PsrResponse(401), 'http_401', 401,
                'The vendor answered HTTP 401.', 'The vendor answered HTTP 401.'],
        ];
    }

    #[DataProvider('failureClasses')]
    public function test_sync_now_failure_reason_and_http_status_come_from_the_vendor_answer($answer, string $reason, ?int $status, string $sentence, string $refreshText): void
    {
        $asset = $this->assetLinkedEverywhere([], ['synced_at' => now()->subDay(), 'status' => 'offline']);
        $before = $asset->tacticalAsset->synced_at->toDateTimeString();
        $this->bindTactical([$answer]);

        $this->actingAs(User::factory()->tech()->create())->postJson(route('assets.sources.sync', [$asset, 'tactical']))
            ->assertOk()
            ->assertJson(['outcome' => 'failed', 'reason' => $reason, 'changed' => [], 'message' => $sentence])
            ->assertJsonMissingPath('http_status');

        $row = AssetSourceSync::sole();
        $this->assertSame(['failed', $reason, $status], [$row->outcome, $row->reason, $row->http_status]);
        $this->assertSame($before, $asset->tacticalAsset->refresh()->synced_at->toDateTimeString(), 'snapshot untouched');
    }

    #[DataProvider('failureClasses')]
    public function test_tactical_refresh_failure_text_comes_from_the_vendor_answer($answer, string $reason, ?int $status, string $sentence, string $refreshText): void
    {
        $asset = $this->assetLinkedEverywhere([], ['synced_at' => now()->subDay()]);
        $this->bindTactical([$answer]);

        $this->actingAs(User::factory()->tech()->create())->postJson(route('assets.tactical-refresh', $asset))
            ->assertOk()
            ->assertJson(['degraded' => true, 'message' => $refreshText]);
        $this->assertSame([$reason, $status], [AssetSourceSync::sole()->reason, AssetSourceSync::sole()->http_status]);
    }

    #[DataProvider('failureClasses')]
    public function test_detail_read_degraded_text_and_status_come_from_the_vendor_answer($answer, string $reason, ?int $status, string $sentence, string $refreshText): void
    {
        $asset = $this->assetLinkedEverywhere();
        $this->bindTactical([$answer]);

        $result = $this->app->make(TacticalDeviceSyncService::class)->syncDeviceDetail($asset);

        $this->assertFalse($result->ok);
        $this->assertSame($status, $result->httpStatus);
        $this->assertSame($refreshText, $result->message);
    }

    public function test_no_failure_class_says_could_not_reach_except_transport(): void
    {
        foreach (self::failureClasses() as $name => [$answer, $reason]) {
            $asset = $this->assetLinkedEverywhere();
            $this->bindTactical([$answer]);
            $message = $this->actingAs(User::factory()->tech()->create())
                ->postJson(route('assets.tactical-refresh', $asset))->json('message');
            $this->assertSame($reason === 'transport', str_contains((string) $message, 'Could not reach'), $name);
        }
    }
}
