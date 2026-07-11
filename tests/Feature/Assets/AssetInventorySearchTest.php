<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetInventorySearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression for psa-rcfq: device search must be a primary, always-visible
     * affordance on the inventory page — not hidden behind the collapsed
     * "Filters" panel. We prove it by asserting the search input renders
     * before the `#filterCard` collapse container in the document.
     */
    public function test_search_input_renders_outside_the_collapsed_filters_panel(): void
    {
        $user = User::factory()->create();

        $resp = $this->actingAs($user)->get(route('assets.index'));

        $resp->assertOk();
        // The search box is present...
        $resp->assertSee('Search hostname, name, serial, IP...', false);
        // ...and it appears ahead of the collapsible advanced-filter panel,
        // so it is visible without opening Filters.
        $resp->assertSeeInOrder([
            'Search hostname, name, serial, IP...',
            'id="filterCard"',
        ], false);
    }

    public function test_search_filters_the_inventory_by_hostname(): void
    {
        $user = User::factory()->create();

        Asset::factory()->create(['hostname' => 'VAN-APP01', 'name' => 'App Server']);
        Asset::factory()->create(['hostname' => 'VAN-DC01', 'name' => 'Domain Controller']);

        $resp = $this->actingAs($user)->get(route('assets.index', ['search' => 'VAN-APP01']));

        $resp->assertOk();
        $resp->assertSee('VAN-APP01');
        $resp->assertDontSee('VAN-DC01');
    }

    /**
     * Searching must not silently drop an active quick-filter. The always-visible
     * search form carries the current filters as hidden inputs, so submitting a
     * search from the top bar preserves (e.g.) the Online status filter.
     */
    public function test_search_bar_preserves_an_active_status_filter(): void
    {
        $user = User::factory()->create();

        $resp = $this->actingAs($user)->get(route('assets.index', ['status' => 'online']));

        $resp->assertOk();
        // The status filter is carried as a hidden input inside the top search
        // form (i.e. before the collapsed advanced-filter panel).
        $resp->assertSeeInOrder([
            'name="status" value="online"',
            'id="filterCard"',
        ], false);
    }
}
