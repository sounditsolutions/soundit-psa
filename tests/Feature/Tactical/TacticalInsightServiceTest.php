<?php

namespace Tests\Feature\Tactical;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Asset;
use App\Models\TacticalActionLog;
use App\Models\TacticalAsset;
use App\Services\Tactical\EndpointInsight;
use App\Services\Tactical\SignalState;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalInsightService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Task 1/2 + amendments A/C: the snapshot-base + bounded-live-refresh read
 * layer. The snapshot path makes ZERO live calls; the live path opportunistically
 * refreshes the cheap signals (status/checks) via the generic bounded wrapper and
 * degrades to snapshot on any failure — never throwing to the caller.
 */
class TacticalInsightServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    private function service(array $queue = []): TacticalInsightService
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        $http = new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => $stack,
            'timeout' => 30,
            'allow_redirects' => false,
        ]);

        return new TacticalInsightService(new TacticalClient($http));
    }

    private function linkedAsset(array $taOverrides = []): Asset
    {
        $asset = Asset::factory()->create(['hostname' => 'BOX-1']);
        TacticalAsset::create(array_merge([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-1',
            'hostname' => 'BOX-1',
            'os' => 'Windows 11 Pro',
            'cpu' => 'Intel i7',
            'ram_gb' => 16.0,
            'disk_summary' => 'C: 256GB',
            'status' => 'online',
            'needs_reboot' => false,
            'has_patches_pending' => true,
            'checks_failing' => 1,
            'checks_total' => 4,
            'last_seen_at' => now()->subMinutes(5),
            'synced_at' => now()->subMinutes(10),
        ], $taOverrides));

        return $asset->refresh();
    }

    // ── Snapshot base ──

    public function test_not_linked_asset_returns_not_linked_insight_no_throw(): void
    {
        $asset = Asset::factory()->create(['hostname' => 'NOLINK']);

        $insight = $this->service()->forAsset($asset);

        $this->assertFalse($insight->linked);
        $this->assertSame(SignalState::Unavailable, $insight->checksState);
        $this->assertEmpty($this->history); // zero live calls
    }

    public function test_snapshot_base_assembles_from_columns_with_zero_live_calls(): void
    {
        $asset = $this->linkedAsset();

        $insight = $this->service()->forAsset($asset);

        $this->assertTrue($insight->linked);
        $this->assertSame('online', $insight->status);
        $this->assertSame(SignalState::Snapshot, $insight->statusState);
        $this->assertSame('Intel i7', $insight->cpu);
        $this->assertSame(16.0, $insight->ramGb);
        $this->assertSame(1, $insight->checksFailing);
        $this->assertSame(4, $insight->checksTotal);
        $this->assertSame(SignalState::Snapshot, $insight->checksState);
        $this->assertEmpty($this->history); // NO live calls on the snapshot path
    }

    public function test_pending_patch_count_is_null_on_snapshot_but_boolean_is_honest(): void
    {
        // The snapshot only carries a has_patches_pending BOOLEAN — the precise
        // count is a live/panel read. Surfacing "1 pending" here would lie to the
        // P5 snapshot (a box 47 behind would read as "1"). So the count is null
        // (unknown) while the boolean honestly says "updates pending".
        $asset = $this->linkedAsset(['has_patches_pending' => true]);

        $insight = $this->service()->forAsset($asset);

        $this->assertNull($insight->pendingPatchCount, 'count is unknown on the snapshot path');
        $this->assertTrue($insight->hasPendingPatches, 'the boolean is the honest snapshot signal');
        $this->assertEmpty($this->history);
    }

    public function test_no_pending_patches_boolean_is_false_when_snapshot_clean(): void
    {
        $asset = $this->linkedAsset(['has_patches_pending' => false]);

        $insight = $this->service()->forAsset($asset);

        $this->assertNull($insight->pendingPatchCount);
        $this->assertFalse($insight->hasPendingPatches);
    }

    public function test_fresh_as_of_is_the_snapshot_synced_at(): void
    {
        $syncedAt = now()->subHours(3);
        $asset = $this->linkedAsset(['synced_at' => $syncedAt]);

        $insight = $this->service()->forAsset($asset);

        $this->assertNotNull($insight->freshAsOf);
        $this->assertSame($syncedAt->toDateTimeString(), $insight->freshAsOf->toDateTimeString());
    }

    public function test_open_alerts_come_from_the_local_db(): void
    {
        $asset = $this->linkedAsset();

        Alert::create([
            'asset_id' => $asset->id,
            'client_id' => $asset->client_id,
            'source' => AlertSource::Tactical,
            'source_alert_id' => '900',
            'severity' => AlertSeverity::Error,
            'status' => AlertStatus::Active,
            'title' => 'Disk Space - C:',
            'fired_at' => now(),
        ]);
        // A resolved alert must NOT count as open.
        Alert::create([
            'asset_id' => $asset->id,
            'client_id' => $asset->client_id,
            'source' => AlertSource::Tactical,
            'source_alert_id' => '901',
            'severity' => AlertSeverity::Warning,
            'status' => AlertStatus::Resolved,
            'title' => 'CPU',
            'fired_at' => now()->subDay(),
        ]);

        $insight = $this->service()->forAsset($asset);

        $this->assertSame(1, $insight->openAlerts);
        $this->assertCount(1, $insight->openAlertList);
        $this->assertSame('Disk Space - C:', $insight->openAlertList[0]['title']);
        $this->assertEmpty($this->history); // local DB read, no live cost
    }

    public function test_recent_actions_come_from_action_logs_newest_first(): void
    {
        $asset = $this->linkedAsset();

        // Eloquent stamps created_at on insert, so insertion order IS chronological
        // (the model is append-only — created_at can't be back-dated). The reboot
        // is inserted first (older), then the run-script (newest).
        TacticalActionLog::create([
            'actor_label' => 'tech@example.com',
            'action_key' => 'tactical.reboot',
            'agent_id' => 'AGENT-1',
            'asset_id' => $asset->id,
            'ticket_id' => null,
            'target_label' => 'BOX-1',
            'params' => [],
            'result_status' => 'ok',
            'correlation_id' => 'c-1',
        ]);
        TacticalActionLog::create([
            'actor_label' => 'tech@example.com',
            'action_key' => 'tactical.run_script',
            'agent_id' => 'AGENT-1',
            'asset_id' => $asset->id,
            'ticket_id' => null,
            'target_label' => 'BOX-1',
            'params' => [],
            'result_status' => 'ok',
            'correlation_id' => 'c-2',
        ]);

        $insight = $this->service()->forAsset($asset);

        $this->assertCount(2, $insight->recentActions);
        // newest first
        $this->assertSame('tactical.run_script', $insight->recentActions[0]['action']);
        $this->assertSame('tactical.reboot', $insight->recentActions[1]['action']);
        // amendment K: the ticket_id is carried (here null — an out-of-band action)
        // so a change can tie to its incident when present.
        $this->assertArrayHasKey('ticket_id', $insight->recentActions[1]);
    }

    // ── Deterministic flags (§11.4) ──

    public function test_stale_flag_when_snapshot_older_than_threshold(): void
    {
        $fresh = $this->linkedAsset(['synced_at' => now()->subMinutes(10)]);
        $this->assertFalse($this->service()->forAsset($fresh)->stale);

        $stale = $this->linkedAsset([
            'agent_id' => 'AGENT-STALE',
            'synced_at' => now()->subMinutes(EndpointInsight::STALE_AFTER_MINUTES + 5),
        ]);
        $this->assertTrue($this->service()->forAsset($stale)->stale);
    }

    public function test_long_offline_flag_when_last_seen_beyond_threshold(): void
    {
        $recent = $this->linkedAsset(['last_seen_at' => now()->subDays(1)]);
        $this->assertFalse($this->service()->forAsset($recent)->longOffline);

        $abandoned = $this->linkedAsset([
            'agent_id' => 'AGENT-GONE',
            'last_seen_at' => now()->subDays(EndpointInsight::LONG_OFFLINE_AFTER_DAYS + 2),
        ]);
        $this->assertTrue($this->service()->forAsset($abandoned)->longOffline);
    }

    public function test_needs_reboot_flag_from_snapshot_column(): void
    {
        $asset = $this->linkedAsset(['needs_reboot' => true]);

        $this->assertTrue($this->service()->forAsset($asset)->needsReboot);
    }

    // ── Bounded live refresh (amendment C) ──

    public function test_live_refresh_marks_status_and_checks_live_on_success(): void
    {
        $asset = $this->linkedAsset(['status' => 'offline', 'checks_failing' => 9]);

        // getAgent (status) then getAgentChecks (checks) — two cheap reads.
        $service = $this->service([
            new Response(200, [], json_encode([
                'status' => 'online',
                'maintenance_mode' => false,
                'logged_in_username' => 'jsmith',
            ])),
            new Response(200, [], json_encode([
                ['name' => 'Disk C', 'check_result' => ['status' => 'failing', 'retcode' => 1, 'stdout' => 'low space']],
                ['name' => 'Ping', 'check_result' => ['status' => 'passing']],
            ])),
        ]);

        $insight = $service->forAsset($asset, live: true);

        $this->assertSame('online', $insight->status);
        $this->assertSame(SignalState::Live, $insight->statusState);
        $this->assertSame(SignalState::Live, $insight->checksState);
        $this->assertSame(1, $insight->checksFailing);
        $this->assertSame(2, $insight->checksTotal);
        $this->assertCount(1, $insight->failingChecks);
        $this->assertSame('Disk C', $insight->failingChecks[0]->name);
        $this->assertTrue($insight->userLoggedIn);
        // freshAsOf is "now"-ish for a live refresh (not the 10-min-old snapshot).
        $this->assertTrue($insight->freshAsOf->diffInMinutes(now()) < 1);
    }

    public function test_live_refresh_degrades_to_snapshot_on_failure_never_throws(): void
    {
        $asset = $this->linkedAsset(['status' => 'online', 'checks_failing' => 1, 'checks_total' => 4]);

        // Both cheap reads fail (500).
        $service = $this->service([
            new Response(500, [], 'boom'),
            new Response(500, [], 'boom'),
        ]);

        $insight = $service->forAsset($asset, live: true);

        // Status falls back to the snapshot value, marked Snapshot (NOT Unavailable —
        // we still have an honest snapshot for status).
        $this->assertSame('online', $insight->status);
        $this->assertSame(SignalState::Snapshot, $insight->statusState);
        // Checks: the live fetch failed and we have a snapshot count, so Snapshot.
        $this->assertSame(SignalState::Snapshot, $insight->checksState);
        $this->assertSame(1, $insight->checksFailing);
    }

    public function test_mixed_read_keeps_per_signal_state_honest_status_live_checks_snapshot(): void
    {
        // freshAsOf is a single freshest-signal scalar; on a MIXED read (status
        // refreshes Live, checks degrade to Snapshot) it is stamped now() while
        // checks are stale. The honesty lives in the per-signal SignalState, NOT
        // in freshAsOf — assert each signal carries its own truthful state.
        $asset = $this->linkedAsset(['status' => 'offline', 'checks_failing' => 2, 'checks_total' => 5]);

        $service = $this->service([
            new Response(200, [], json_encode(['status' => 'online'])), // status: Live
            new Response(500, [], 'boom'),                              // checks: degrade
        ]);

        $insight = $service->forAsset($asset, live: true);

        // status refreshed Live...
        $this->assertSame('online', $insight->status);
        $this->assertSame(SignalState::Live, $insight->statusState);
        // ...but checks fell back to the snapshot count — Snapshot, NOT Live.
        $this->assertSame(SignalState::Snapshot, $insight->checksState);
        $this->assertSame(2, $insight->checksFailing);
        // freshAsOf alone (the freshest-signal stamp) would mislead a consumer that
        // ignored checksState — the per-signal enum is the source of truth.
    }

    public function test_live_refresh_uses_a_short_timeout_not_the_30s_default(): void
    {
        $asset = $this->linkedAsset();

        // Capture the transfer options to assert the bounded read timeout.
        $captured = [];
        $handler = function (RequestInterface $request, array $options) use (&$captured) {
            $captured[] = $options['timeout'] ?? null;

            return \GuzzleHttp\Promise\Create::promiseFor(new Response(200, [], json_encode(['status' => 'online'])));
        };
        $http = new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => HandlerStack::create($handler),
            'timeout' => 30,
            'allow_redirects' => false,
        ]);
        $service = new TacticalInsightService(new TacticalClient($http));

        $service->forAsset($asset, live: true);

        $this->assertNotEmpty($captured);
        foreach ($captured as $timeout) {
            $this->assertNotNull($timeout, 'live read must set a per-request timeout');
            $this->assertLessThanOrEqual(3, $timeout, 'live read timeout must be the short bound, not 30s');
            $this->assertGreaterThanOrEqual(2, $timeout);
        }
    }

    public function test_checks_unavailable_when_live_fails_and_no_snapshot_count(): void
    {
        // No snapshot checks count + a failed live fetch => Unavailable, NOT "0 clean".
        $asset = $this->linkedAsset(['checks_failing' => null, 'checks_total' => null]);

        $service = $this->service([
            new Response(200, [], json_encode(['status' => 'online'])), // status ok
            new Response(500, [], 'boom'),                              // checks fail
        ]);

        $insight = $service->forAsset($asset, live: true);

        $this->assertSame(SignalState::Unavailable, $insight->checksState);
        $this->assertNull($insight->checksFailing);
        $this->assertFalse($insight->checksKnownClean());
    }
}
