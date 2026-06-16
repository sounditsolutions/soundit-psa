<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\User;
use App\Services\Tactical\EndpointInsight;
use App\Services\Tactical\TacticalClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Commit 2 (P4 chunk 2, plan Task 4 + amendments B/H/J): the in-place AJAX
 * refresh-now endpoint and the eager card health line.
 *
 * Refresh-now is a READ — it calls syncDeviceDetail (NOT the action bus), is not
 * audited, returns {status, freshAsOf, degraded, message}; a live failure is a
 * 200 with degraded:true (never a 500) and leaves the prior snapshot intact. It
 * is POST + CSRF + the same auth as the page.
 *
 * The eager health line is snapshot/local-DB derived — the asset page must make
 * ZERO outbound Tactical calls on initial render.
 */
class TacticalRefreshNowTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('tactical_api_url', 'https://tactical.example.com');
        Setting::setEncrypted('tactical_api_key', 'svc-key-abc123');
    }

    private function bindClient(array $queue): void
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));
        $http = new GuzzleClient(['base_uri' => 'https://tactical.example.com/', 'handler' => $stack]);
        $this->app->instance(TacticalClient::class, new TacticalClient($http));
    }

    private function linkedAsset(array $taOverrides = []): Asset
    {
        $asset = Asset::factory()->create(['hostname' => 'BOX-1']);
        TacticalAsset::create(array_merge([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-1',
            'hostname' => 'BOX-1',
            'status' => 'offline',
            'ram_gb' => 8.0,
            'os_version' => 'Windows 10 Pro',
            'checks_failing' => 2,
            'checks_total' => 5,
            'has_patches_pending' => true,
            'synced_at' => now()->subDay(),
            'last_seen_at' => now()->subDay(),
        ], $taOverrides));

        return $asset->refresh();
    }

    // ── refresh-now endpoint ─────────────────────────────────────────────────

    public function test_refresh_now_calls_detail_sync_and_returns_fresh_as_of(): void
    {
        $asset = $this->linkedAsset();
        // getAgent detail: a successful live refresh.
        $this->bindClient([
            new Response(200, [], json_encode([
                'status' => 'online',
                'total_ram' => 17179869184, // 16 GiB
                'operating_system' => 'Windows 11 Pro',
                'last_seen' => '2026-06-16 12:00:00',
            ])),
        ]);

        $resp = $this->actingAs(User::factory()->create())
            ->postJson(route('assets.tactical-refresh', $asset));

        $resp->assertOk();
        $resp->assertJsonPath('degraded', false);
        $resp->assertJsonPath('status', 'online');
        $this->assertNotEmpty($resp->json('freshAsOf'));

        // The snapshot was updated (the detail read wrote ram_gb / os_version).
        $ta = $asset->tacticalAsset->refresh();
        $this->assertSame('online', $ta->status);
        $this->assertSame('16.0', (string) $ta->ram_gb);
        $this->assertSame('Windows 11 Pro', $ta->os_version);
    }

    public function test_refresh_now_live_failure_is_200_degraded_not_500(): void
    {
        $asset = $this->linkedAsset();
        $priorSyncedAt = $asset->tacticalAsset->synced_at;
        $this->bindClient([new ConnectException('offline', new Request('GET', 'agents/AGENT-1/'))]);

        $resp = $this->actingAs(User::factory()->create())
            ->postJson(route('assets.tactical-refresh', $asset));

        // A degraded read is a 200 with degraded:true — NEVER a 500.
        $resp->assertOk();
        $resp->assertJsonPath('degraded', true);
        $this->assertNotEmpty($resp->json('message'));

        // Prior snapshot intact (refresh-now never clobbers the snapshot on failure).
        $ta = $asset->tacticalAsset->refresh();
        $this->assertSame('8.0', (string) $ta->ram_gb);
        $this->assertSame('Windows 10 Pro', $ta->os_version);
        $this->assertSame($priorSyncedAt->toDateTimeString(), $ta->synced_at->toDateTimeString());
    }

    public function test_refresh_now_is_not_audited(): void
    {
        $asset = $this->linkedAsset();
        $this->bindClient([new Response(200, [], json_encode(['status' => 'online']))]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('assets.tactical-refresh', $asset))
            ->assertOk();

        // Refresh-now is a READ — it must NOT write an audit row.
        $this->assertDatabaseCount('tactical_action_logs', 0);
    }

    public function test_refresh_now_not_linked_returns_422(): void
    {
        $asset = Asset::factory()->create(['hostname' => 'NOLINK']);
        $this->bindClient([]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('assets.tactical-refresh', $asset))
            ->assertStatus(422)
            ->assertJsonStructure(['error']);
    }

    public function test_refresh_now_requires_auth(): void
    {
        $asset = $this->linkedAsset();

        // Unauthenticated POST → redirected to login (the web auth group), not run.
        $this->post(route('assets.tactical-refresh', $asset))->assertRedirect();
    }

    public function test_refresh_now_is_in_the_csrf_protected_web_group(): void
    {
        // The framework skips ValidateCsrfToken under PHPUnit, so assert the route
        // is registered in the CSRF-on web middleware group (same as the actions).
        $route = app('router')->getRoutes()->getByName('assets.tactical-refresh');
        $this->assertNotNull($route);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('POST', $route->methods());
    }

    // ── eager health line (zero live calls on render) ─────────────────────────

    public function test_show_renders_eager_health_line_with_zero_live_calls(): void
    {
        $asset = $this->linkedAsset([
            'status' => 'online',
            'checks_failing' => 3,
            'checks_total' => 8,
        ]);
        // Bind a client whose handler would EXPLODE if called — proving the eager
        // render makes no outbound Tactical request.
        $stack = HandlerStack::create(new MockHandler([]));
        $http = new GuzzleClient(['base_uri' => 'https://tactical.example.com/', 'handler' => $stack]);
        $this->app->instance(TacticalClient::class, new TacticalClient($http));

        $resp = $this->actingAs(User::factory()->create())->get(route('assets.show', $asset));

        $resp->assertOk();
        // The eager health summary (failing-checks / patches) is present from the snapshot.
        $resp->assertSee('tactical-health-line', false);
        $resp->assertSeeText('3 checks failing');
    }

    public function test_eager_health_line_shows_dash_when_checks_count_unknown(): void
    {
        // checks_failing null (never refreshed) must render "—", NOT "0 failing"
        // (Unavailable != clean).
        $asset = $this->linkedAsset(['checks_failing' => null, 'checks_total' => null]);

        $resp = $this->actingAs(User::factory()->create())->get(route('assets.show', $asset));

        $resp->assertOk();
        $resp->assertSee('tactical-health-line', false);
        // The dash marker for an unknown checks count.
        $resp->assertSeeText('checks: —');
    }

    public function test_stale_online_renders_freshness_in_a_warning_treatment(): void
    {
        // An "online" status older than the staleness window → amber freshness
        // adjacent to the status badge (amendment H), not muted grey.
        $asset = $this->linkedAsset([
            'status' => 'online',
            'synced_at' => now()->subMinutes(EndpointInsight::STALE_AFTER_MINUTES + 30),
        ]);

        $resp = $this->actingAs(User::factory()->create())->get(route('assets.show', $asset));

        $resp->assertOk();
        // The freshness span sits next to the status as one unit, with the
        // stale-warning class on the element itself (not merely in the JS).
        $resp->assertSee('tactical-freshness tactical-freshness-stale', false);
    }

    public function test_fresh_online_renders_freshness_muted_not_warning(): void
    {
        $asset = $this->linkedAsset([
            'status' => 'online',
            'synced_at' => now()->subMinutes(5),
        ]);

        $resp = $this->actingAs(User::factory()->create())->get(route('assets.show', $asset));

        $resp->assertOk();
        // The freshness element renders muted (text-muted), NOT the stale-amber
        // class — assert the muted class is on the freshness span.
        $resp->assertSee('tactical-freshness text-muted', false);
        $resp->assertDontSee('tactical-freshness tactical-freshness-stale', false);
    }
}
