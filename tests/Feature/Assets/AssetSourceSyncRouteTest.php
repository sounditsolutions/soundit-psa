<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\AssetSourceSync;
use App\Models\Setting;
use App\Models\User;
use App\Services\AssetSources\AssetSources;
use App\Services\AssetSources\SourceSyncPipeline;
use App\Services\Tactical\TacticalClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

/**
 * Asset Sources: POST /assets/{asset}/sources/{source}/sync, steps 1-10
 * (design.md Item 3). S1 wires Tactical's existing single-agent read, S2
 * AutoElevate's GET-one (AssetSourcesAutoElevateTest) and S3 Control D's per-org
 * device read (AssetSourcesSecurityPanelsTest); every other source is
 * still unwired, refuses with no_single_asset_read and calls no vendor. Generic
 * limiter/cooldown/audit controls run against unwired sources.
 * Synthetic data only.
 */
class AssetSourceSyncRouteTest extends TestCase
{
    use AssetSourcesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // G-5: any Http-facade call fails the test. No catch-all fake.
        Http::preventStrayRequests();
        Http::fake([]);
    }

    private function sync(Asset $asset, string $source, ?User $user = null)
    {
        return $this->actingAs($user ?? User::factory()->tech()->create())
            ->postJson(route('assets.sources.sync', [$asset, $source]));
    }

    /**
     * Bind TacticalClient (Guzzle, not the Http facade) to a scripted queue. An
     * empty queue makes any Tactical call throw, so "no read" is enforced too.
     *
     * @return \ArrayObject<int, array<string, mixed>> the request history
     */
    private function bindTactical(array $queue): \ArrayObject
    {
        $history = new \ArrayObject;
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));
        $http = new GuzzleClient(['base_uri' => 'https://tactical-api.example.test/', 'handler' => $stack]);
        $this->app->instance(TacticalClient::class, new TacticalClient($http));

        return $history;
    }

    /** A synthetic getAgent detail payload (the existing single-agent read). */
    private function agentResponse(array $overrides = []): PsrResponse
    {
        return new PsrResponse(200, [], json_encode(array_merge([
            'status' => 'online',
            'operating_system' => 'Windows 11 Pro',
            'needs_reboot' => true,
            'checks' => ['total' => 6, 'passing' => 5, 'failing' => 1, 'warning' => 0, 'info' => 0],
        ], $overrides)));
    }

    public function test_no_single_asset_read_returns_the_exact_source_neutral_sentence(): void
    {
        $this->configureAllSources();
        $this->sync($this->assetLinkedEverywhere(), 'screenconnect')->assertOk()
            ->assertJsonPath('reason', 'no_single_asset_read')
            ->assertJsonPath('message', "Sync now can't read this source for a single asset. Its data arrives with the source's own updates.");
        Http::assertNothingSent();
    }

    public function test_off_source_refuses_with_switched_off_and_makes_zero_http_calls(): void
    {
        // M0.1. Ninja is configured and switched off (its default).
        $this->configureAllSources();
        $asset = $this->assetLinkedEverywhere();

        $this->sync($asset, 'ninja')->assertOk()
            ->assertJson(['outcome' => 'refused', 'reason' => 'switched_off', 'changed' => [], 'this_read' => []])
            ->assertJsonPath('message', 'Switched off in Settings; not being updated.');

        Setting::setValue('level_enabled', '0');
        $this->sync($asset, 'level')->assertJsonPath('reason', 'switched_off');

        Http::assertNothingSent();
        $this->assertSame(2, AssetSourceSync::where('reason', 'switched_off')->count());
    }

    public function test_unconfigured_and_unlinked_sources_refuse_before_any_limiter(): void
    {
        $asset = Asset::factory()->create();
        $this->sync($asset, 'tactical')->assertJsonPath('reason', 'not_configured');

        $this->configureAllSources();
        $this->sync($asset, 'tactical')->assertJsonPath('reason', 'not_linked');
        $this->sync($asset, 'autoelevate')->assertJsonPath('reason', 'client_not_mapped');

        Http::assertNothingSent();
    }

    public function test_7th_call_in_a_minute_returns_user_throttled_with_retry_after_s_gt_0(): void
    {
        // M0.2. Six calls across sources pass the limiter; the seventh is refused.
        $this->configureAllSources();
        $asset = $this->assetLinkedEverywhere();
        $user = User::factory()->tech()->create();
        foreach (['screenconnect', 'litsrmm', 'comet', 'level', 'servosity', 'zorus'] as $source) {
            $this->sync($asset, $source, $user)->assertJsonPath('reason', 'no_single_asset_read');
        }

        $r = $this->sync($asset, 'm365', $user)->assertJsonPath('reason', 'user_throttled');
        $this->assertGreaterThan(0, $r->json('retry_after_s'));
        // Another user is not throttled by the first one's calls.
        $this->sync($asset, 'm365')->assertJsonPath('reason', 'no_single_asset_read');
        Http::assertNothingSent();
    }

    public function test_second_call_on_same_asset_and_source_within_30s_returns_recently_synced(): void
    {
        $this->configureAllSources();
        $asset = $this->assetLinkedEverywhere();
        $this->sync($asset, 'litsrmm')->assertJsonPath('reason', 'no_single_asset_read');

        $r = $this->sync($asset, 'litsrmm')->assertJson([
            'outcome' => 'refused', 'reason' => 'recently_synced',
            'previous' => ['outcome' => 'refused', 'reason' => 'no_single_asset_read'],
        ])->assertJsonPath('message', 'Sync now was already tried for this source on this asset moments ago.');
        $this->assertGreaterThan(0, $r->json('retry_after_s'));
        $this->assertLessThanOrEqual(SourceSyncPipeline::ASSET_SOURCE_COOLDOWN_S, $r->json('retry_after_s'));

        $this->travel(SourceSyncPipeline::ASSET_SOURCE_COOLDOWN_S + 1)->seconds();
        $this->sync($asset, 'litsrmm')->assertJsonPath('reason', 'no_single_asset_read');
    }

    public function test_while_vendor_cooldown_is_set_no_request_is_sent_and_the_reply_carries_the_remaining_seconds(): void
    {
        // M0.3.
        $this->configureAllSources();
        $asset = $this->assetLinkedEverywhere();
        Cache::put(SourceSyncPipeline::vendorCooldownKey('litsrmm'), now()->getTimestamp() + 42, 60);

        $this->sync($asset, 'litsrmm')->assertJson([
            'outcome' => 'refused', 'reason' => 'vendor_cooling_down', 'retry_after_s' => 42,
        ]);
        $this->assertSame(42, AssetSourceSync::latest('id')->first()->retry_after_s);
        // The cooldown is per vendor: another source on the same asset is not cooled.
        $this->sync($asset, 'zorus')->assertJsonPath('reason', 'no_single_asset_read');
        Http::assertNothingSent();
    }

    public function test_a_refused_call_writes_one_audit_row_with_its_reason(): void
    {
        // M0.4.
        $this->configureAllSources();
        $asset = $this->assetLinkedEverywhere();
        $user = User::factory()->tech()->create();

        $this->sync($asset, 'ninja', $user);

        $rows = AssetSourceSync::all();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame([$asset->id, 'ninja', $user->id, 'refused', 'switched_off', null, null],
            [$row->asset_id, $row->source, $row->user_id, $row->outcome, $row->reason, $row->http_status, $row->retry_after_s]);
        $this->assertNotNull($row->started_at);
        $this->assertGreaterThanOrEqual(0, $row->duration_ms);
    }

    public function test_only_tactical_autoelevate_and_controld_can_return_ok_in_s3(): void
    {
        // M-s3: wiring a fourth source to the pipeline read turns this red.
        $this->configureAllSources();
        Setting::setValue('ninja_enabled', '1'); // so Ninja reaches the read step like the others
        $history = $this->bindTactical([$this->agentResponse()]);
        $company = '00000000-0000-4000-8000-00000000c0a1';
        $controld = new \ArrayObject;
        $queue = new MockHandler([]);
        $cdStack = HandlerStack::create($queue);
        $cdStack->push(Middleware::history($controld));
        $this->app->bind(\App\Services\ControlD\ControlDClient::class, fn ($app, array $p) => new \App\Services\ControlD\ControlDClient(($p['config'] ?? []) + ['handler' => $cdStack]));
        foreach (AssetSources::ORDER as $source) {
            $asset = $this->assetLinkedEverywhere();
            $asset->client->update(['autoelevate_company_id' => $company, 'controld_org_id' => 'synthetic-org-'.$source]);
            if ($source === 'controld') {
                $queue->append(new PsrResponse(200, [], json_encode(['body' => ['devices' => [
                    ['PK' => $asset->controld_device_id, 'name' => 'DESK-EXAMPLE-01', 'status' => 1, 'profile' => ['name' => 'Synthetic profile'], 'ctrld' => ['status' => 1]],
                ]]])));
            }
            if ($source === 'autoelevate') {
                Http::fake(['https://partner-api.autoelevate.com/api/v1/computers/'.$asset->autoelevate_computer_id => Http::response([
                    'id' => $asset->autoelevate_computer_id, 'machineName' => 'DESK-EXAMPLE-01', 'operatingSystem' => null,
                    'locationId' => '00000000-0000-4000-8000-0000000010c1', 'companyId' => $company, 'elevationMode' => 'live',
                    'lastCheckedInAt' => 1716900000000, 'createdAt' => 1716900000000, 'updatedAt' => 1716900000000,
                ], 200)]);
            }
            $r = $this->sync($asset, $source);
            if (in_array($source, ['tactical', 'autoelevate', 'controld'], true)) {
                $r->assertJsonPath('outcome', 'ok')->assertJsonPath('reason', 'ok');
            } else {
                $r->assertJson(['outcome' => 'refused', 'reason' => 'no_single_asset_read'], $source);
            }
        }
        $this->assertEqualsCanonicalizing(['tactical', 'autoelevate', 'controld'], AssetSourceSync::where('outcome', '!=', 'refused')->pluck('source')->all());
        $this->assertSame(count(AssetSources::ORDER), AssetSourceSync::count());
        $this->assertCount(1, $history, 'one Tactical getAgent read, nothing else');
        $this->assertCount(1, $controld, 'one Control D devices read, nothing else');
        Http::assertSentCount(1); // the one AutoElevate GET-one, nothing else
    }

    public function test_tactical_sync_now_reads_the_existing_single_agent_read_and_writes_this_asset_only(): void
    {
        // Condition 4 (success): Tactical's Sync now is the existing getAgent detail read.
        $this->configureAllSources();
        $asset = $this->assetLinkedEverywhere([], ['synced_at' => now()->subDay(), 'checks_failing' => 0]);
        $sibling = $this->assetLinkedEverywhere([], ['synced_at' => now()->subDay()]);
        $history = $this->bindTactical([$this->agentResponse()]);

        $r = $this->sync($asset, 'tactical')->assertOk()
            ->assertJson(['outcome' => 'ok', 'reason' => 'ok', 'message' => 'Read from the vendor.']);
        $this->assertContains('checks_failing', $r->json('changed'));
        $this->assertContains('synced_at', $r->json('changed'));

        $this->assertCount(1, $history);
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertStringEndsWith('agents/'.$asset->tacticalAsset->agent_id.'/', (string) $history[0]['request']->getUri());
        $ta = $asset->tacticalAsset->refresh();
        $this->assertSame(1, $ta->checks_failing);
        $this->assertTrue($ta->synced_at->greaterThan(now()->subMinute()));
        $this->assertTrue($sibling->tacticalAsset->refresh()->synced_at->lessThan(now()->subHours(23)), 'sibling untouched');
        Http::assertNothingSent();
    }

    public function test_agent_unreachable_leaves_synced_at_unchanged_and_answers_failed_transport(): void
    {
        // M3.2: a degraded read never overwrites the snapshot.
        $this->configureAllSources();
        $asset = $this->assetLinkedEverywhere([], ['synced_at' => now()->subDay(), 'status' => 'offline', 'checks_failing' => 2]);
        $before = $asset->tacticalAsset->synced_at->toDateTimeString();
        $this->bindTactical([new ConnectException('unreachable', new PsrRequest('GET', 'agents/x/'))]);

        $this->sync($asset, 'tactical')->assertOk()
            ->assertJson(['outcome' => 'failed', 'reason' => 'transport', 'changed' => [], 'message' => 'Could not reach the vendor.']);

        $ta = $asset->tacticalAsset->refresh();
        $this->assertSame($before, $ta->synced_at->toDateTimeString());
        $this->assertSame('offline', $ta->status);
        $this->assertSame(2, $ta->checks_failing);
        $row = AssetSourceSync::sole();
        $this->assertSame(['tactical', 'failed', 'transport'], [$row->source, $row->outcome, $row->reason]);
    }

    public function test_tactical_sync_now_writes_one_audit_row_per_attempt(): void
    {
        // Condition 4 (audit): ok and the window refusal each write one row.
        $this->configureAllSources();
        $asset = $this->assetLinkedEverywhere();
        $user = User::factory()->tech()->create();
        $history = $this->bindTactical([$this->agentResponse()]);

        $this->sync($asset, 'tactical', $user)->assertJsonPath('outcome', 'ok');
        $this->sync($asset, 'tactical', $user)->assertJson(['reason' => 'recently_synced', 'previous' => ['outcome' => 'ok', 'reason' => 'ok']]);

        $this->assertSame([['tactical', $user->id, 'ok', 'ok'], ['tactical', $user->id, 'refused', 'recently_synced']],
            AssetSourceSync::orderBy('id')->get()->map(fn ($r) => [$r->source, $r->user_id, $r->outcome, $r->reason])->all());
        $this->assertCount(1, $history, 'the window refusal makes no second read');
    }

    public function test_tactical_refresh_writes_an_audit_row_and_honours_the_per_user_limiter(): void
    {
        // M3.1: the Overview refresh route runs the same pipeline (audit + limiter + window).
        $this->configureAllSources();
        $user = User::factory()->tech()->create();
        $assets = [];
        $queue = [];
        foreach (range(1, SourceSyncPipeline::USER_LIMIT_PER_MINUTE + 1) as $i) {
            $assets[] = $this->assetLinkedEverywhere();
            $queue[] = $this->agentResponse();
        }
        $history = $this->bindTactical($queue);

        foreach (array_slice($assets, 0, SourceSyncPipeline::USER_LIMIT_PER_MINUTE) as $asset) {
            $this->actingAs($user)->postJson(route('assets.tactical-refresh', $asset))
                ->assertOk()->assertJsonPath('degraded', false);
        }
        $this->actingAs($user)->postJson(route('assets.tactical-refresh', end($assets)))
            ->assertStatus(429)->assertJsonPath('reason', 'user_throttled');

        $this->assertCount(SourceSyncPipeline::USER_LIMIT_PER_MINUTE, $history);
        $this->assertSame(SourceSyncPipeline::USER_LIMIT_PER_MINUTE + 1, AssetSourceSync::where('source', 'tactical')->where('user_id', $user->id)->count());
        $this->assertSame('user_throttled', AssetSourceSync::latest('id')->first()->reason);
    }

    public function test_31st_sync_request_in_a_minute_gets_429_and_writes_no_audit_row(): void
    {
        // Prerequisite (b), M-b: the route throttle answers before the pipeline runs.
        $asset = Asset::factory()->create();
        $user = User::factory()->tech()->create();
        foreach (range(1, 30) as $i) {
            $this->sync($asset, 'tactical', $user)->assertOk()->assertJsonPath('reason', 'not_configured');
        }
        $this->assertSame(30, AssetSourceSync::count());

        $this->sync($asset, 'tactical', $user)->assertStatus(429);
        $this->assertSame(30, AssetSourceSync::count(), 'the 31st request writes no asset_source_syncs row');
        Http::assertNothingSent();
    }

    public function test_a_tech_role_user_gets_200(): void
    {
        // M0.5: the one staff role; no admin gate on the route.
        $asset = Asset::factory()->create();
        foreach (['tech', 'billing', 'contractor'] as $role) {
            $this->sync($asset, 'tactical', User::factory()->{$role}()->create())
                ->assertOk()->assertJsonPath('reason', 'not_configured');
        }
        $this->actingAs(User::factory()->tech()->create())
            ->get(route('assets.show', $asset))->assertOk()->assertSee('id="tab-sources"', false);
    }

    public function test_a_guest_gets_the_login_redirect(): void
    {
        // M0.6.
        $asset = Asset::factory()->create();
        $this->post(route('assets.sources.sync', [$asset, 'tactical']))->assertRedirect(route('login'));
        $this->assertSame(0, AssetSourceSync::count());
    }

    public function test_source_outside_the_closed_list_is_404(): void
    {
        $asset = Asset::factory()->create();
        $this->actingAs(User::factory()->tech()->create())
            ->postJson('/assets/'.$asset->id.'/sources/halo/sync')->assertNotFound();
        $this->assertSame(0, AssetSourceSync::count());
    }

    public function test_audit_rows_are_append_only(): void
    {
        $row = AssetSourceSync::create([
            'asset_id' => 1, 'source' => 'tactical', 'user_id' => null, 'outcome' => 'refused',
            'reason' => 'not_linked', 'started_at' => now(), 'duration_ms' => 0,
        ]);

        try {
            $row->update(['reason' => 'ok']);
            $this->fail('update must throw');
        } catch (LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        try {
            $row->delete();
            $this->fail('delete must throw');
        } catch (LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        $this->assertSame('not_linked', AssetSourceSync::find($row->id)->reason);
    }

    public function test_every_reason_has_a_fixed_sentence_and_none_reads_ok(): void
    {
        foreach (SourceSyncPipeline::REASONS as $reason) {
            $sentence = SourceSyncPipeline::sentence($reason);
            $this->assertStringNotContainsString('Unrecognised', $sentence, $reason);
            if ($reason !== 'ok') {
                $this->assertDoesNotMatchRegularExpression('/\bok\b/i', $sentence, $reason);
            }
        }
        $this->assertSame('The vendor answered HTTP 503.', SourceSyncPipeline::sentence('http_503'));
    }
}
