<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\AssetSourceSync;
use App\Models\Setting;
use App\Models\User;
use App\Services\AssetSources\AssetSources;
use App\Services\AssetSources\SourceSyncPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

/**
 * Asset Sources S0: POST /assets/{asset}/sources/{source}/sync, steps 1-6 and 10
 * (design.md Item 3). No source returns ok in S0 and no vendor is called.
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
        foreach (['autoelevate', 'litsrmm', 'tactical', 'level', 'controld', 'zorus'] as $source) {
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
        $this->sync($asset, 'tactical')->assertJsonPath('reason', 'no_single_asset_read');

        $r = $this->sync($asset, 'tactical')->assertJson([
            'outcome' => 'refused', 'reason' => 'recently_synced',
            'previous' => ['outcome' => 'refused', 'reason' => 'no_single_asset_read'],
        ])->assertJsonPath('message', 'Sync now was already tried for this source on this asset moments ago.');
        $this->assertGreaterThan(0, $r->json('retry_after_s'));
        $this->assertLessThanOrEqual(SourceSyncPipeline::ASSET_SOURCE_COOLDOWN_S, $r->json('retry_after_s'));

        $this->travel(SourceSyncPipeline::ASSET_SOURCE_COOLDOWN_S + 1)->seconds();
        $this->sync($asset, 'tactical')->assertJsonPath('reason', 'no_single_asset_read');
    }

    public function test_while_vendor_cooldown_is_set_no_request_is_sent_and_the_reply_carries_the_remaining_seconds(): void
    {
        // M0.3.
        $this->configureAllSources();
        $asset = $this->assetLinkedEverywhere();
        Cache::put(SourceSyncPipeline::vendorCooldownKey('autoelevate'), now()->getTimestamp() + 42, 60);

        $this->sync($asset, 'autoelevate')->assertJson([
            'outcome' => 'refused', 'reason' => 'vendor_cooling_down', 'retry_after_s' => 42,
        ]);
        $this->assertSame(42, AssetSourceSync::latest('id')->first()->retry_after_s);
        // The cooldown is per vendor: another source on the same asset is not cooled.
        $this->sync($asset, 'litsrmm')->assertJsonPath('reason', 'no_single_asset_read');
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

    public function test_no_source_returns_ok_in_s0(): void
    {
        $this->configureAllSources();
        foreach (AssetSources::ORDER as $source) {
            $asset = $this->assetLinkedEverywhere();
            $this->sync($asset, $source)->assertJsonPath('outcome', 'refused');
        }
        $this->assertSame(0, AssetSourceSync::where('outcome', '!=', 'refused')->count());
        $this->assertSame(count(AssetSources::ORDER), AssetSourceSync::count());
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
