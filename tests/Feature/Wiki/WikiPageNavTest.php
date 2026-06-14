<?php

namespace Tests\Feature\Wiki;

use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\Wiki\WikiSkeletonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiPageNavTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_has_index_sibling_and_search_nav(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $client = Client::factory()->create();
        app(WikiSkeletonService::class)->ensureForClient($client); // seeds network, infrastructure, m365, ...
        $user = User::factory()->create();
        $resp = $this->actingAs($user)->get(route('clients.wiki.show', [$client, 'network']));

        $resp->assertOk();
        $resp->assertSee(route('clients.wiki.index', $client), false);                    // back-to-index link
        $resp->assertSee(route('clients.wiki.show', [$client, 'infrastructure']), false); // a sibling link
        $resp->assertSee('name="q"', false);                                              // on-page search input
    }

    public function test_breadcrumb_contains_clickable_index_link(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $client = Client::factory()->create();
        app(WikiSkeletonService::class)->ensureForClient($client);
        $user = User::factory()->create();
        $resp = $this->actingAs($user)->get(route('clients.wiki.show', [$client, 'network']));

        $resp->assertOk();
        // Breadcrumb must be a link (href to the client wiki index), not plain text.
        $resp->assertSee('href="'.route('clients.wiki.index', $client).'"', false);
    }

    public function test_active_sibling_is_marked_in_nav(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $client = Client::factory()->create();
        app(WikiSkeletonService::class)->ensureForClient($client);
        $user = User::factory()->create();
        $resp = $this->actingAs($user)->get(route('clients.wiki.show', [$client, 'network']));

        $resp->assertOk();
        // The active item has the Bootstrap 'active' class applied.
        $resp->assertSee('active', false);
    }

    public function test_global_show_page_has_index_and_search_nav(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $user = User::factory()->create();
        \App\Models\WikiPage::factory()->create(['slug' => 'vendors/fortinet', 'title' => 'Fortinet', 'kind' => 'vendor']);
        \App\Models\WikiPage::factory()->create(['slug' => 'vendors/cisco', 'title' => 'Cisco', 'kind' => 'vendor']);

        $resp = $this->actingAs($user)->get(route('wiki.show', 'vendors/fortinet'));

        $resp->assertOk();
        $resp->assertSee(route('wiki.index'), false);              // back-to-index link
        $resp->assertSee('name="q"', false);                       // on-page search input
    }
}
