<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Client;
use App\Models\User;
use App\Services\AssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AssetMergeWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_merge_endpoint_merges_and_reports_summary(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $survivor = Asset::factory()->for($client)->create(['hostname' => 'WS-01']);
        $duplicate = Asset::factory()->for($client)->create(['hostname' => 'WS-01-DUP', 'ninja_id' => 'ninja-9']);

        $response = $this->actingAs($user)
            ->post(route('assets.merge', $survivor), ['duplicate_id' => $duplicate->id]);

        $response->assertRedirect(route('assets.show', $survivor));
        $response->assertSessionHas('success');
        $this->assertStringContainsString('Merged WS-01-DUP into WS-01', session('success'));

        $tombstone = Asset::withTrashed()->find($duplicate->id);
        $this->assertSame($survivor->id, $tombstone->merged_into_asset_id);
        $this->assertNotNull($tombstone->deleted_at);
        $this->assertSame('ninja-9', $survivor->fresh()->ninja_id);
    }

    public function test_merge_endpoint_rejects_cross_client_and_already_merged_duplicates(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $survivor = Asset::factory()->for($client)->create();
        $foreign = Asset::factory()->create(); // other client

        $this->actingAs($user)
            ->post(route('assets.merge', $survivor), ['duplicate_id' => $foreign->id])
            ->assertSessionHasErrors('duplicate_id');
        $this->assertNull($foreign->fresh()->deleted_at);

        // A tombstone can't be merged again either — the validator filters it out.
        $absorbed = Asset::factory()->for($client)->create();
        app(AssetService::class)->mergeAssets($survivor, $absorbed, $user->id);
        $this->actingAs($user)
            ->post(route('assets.merge', $survivor), ['duplicate_id' => $absorbed->id])
            ->assertSessionHasErrors('duplicate_id');
    }

    public function test_merge_endpoint_surfaces_identity_conflict_as_error_flash(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $survivor = Asset::factory()->for($client)->create(['ninja_id' => 'ninja-a']);
        $duplicate = Asset::factory()->for($client)->create(['ninja_id' => 'ninja-b']);

        $response = $this->actingAs($user)
            ->post(route('assets.merge', $survivor), ['duplicate_id' => $duplicate->id]);

        $response->assertRedirect(route('assets.show', $survivor));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('ninja_id', session('error'));
        $this->assertNull($duplicate->fresh()->deleted_at);
    }

    public function test_restore_refuses_a_merged_away_tombstone_but_restores_a_plain_retired_asset(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $survivor = Asset::factory()->for($client)->create();
        $merged = Asset::factory()->for($client)->create();
        app(AssetService::class)->mergeAssets($survivor, $merged, $user->id);

        $response = $this->actingAs($user)->post(route('assets.restore', $merged->id));
        $response->assertSessionHas('error');
        $this->assertNotNull(Asset::withTrashed()->find($merged->id)->deleted_at);

        $retired = Asset::factory()->for($client)->create();
        $retired->delete();
        $this->actingAs($user)->post(route('assets.restore', $retired->id))
            ->assertSessionHas('success');
        $this->assertNull($retired->fresh()->deleted_at);
    }

    public function test_merged_tombstone_page_shows_merged_banner_with_survivor_link_and_no_restore(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $survivor = Asset::factory()->for($client)->create(['hostname' => 'WS-SURV']);
        $merged = Asset::factory()->for($client)->create();
        app(AssetService::class)->mergeAssets($survivor, $merged, $user->id);

        $response = $this->actingAs($user)->get(route('assets.show', $merged->id));

        $response->assertOk();
        $response->assertSee('This asset was merged into');
        $response->assertSee('WS-SURV');
        $response->assertSee(route('assets.show', $survivor->id));
        $response->assertDontSee(route('assets.restore', $merged->id));
    }
}
