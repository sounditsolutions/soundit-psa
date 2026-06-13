<?php

namespace Tests\Feature\Wiki;

use App\Models\Client;
use App\Models\User;
use App\Models\WikiFact;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiRoutesTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create();
    }

    public function test_routes_require_auth(): void
    {
        $this->get('/wiki')->assertRedirect();
    }

    public function test_global_index_lists_pages_grouped_by_kind(): void
    {
        WikiPage::factory()->create(['slug' => 'vendors/fortinet', 'title' => 'Fortinet', 'kind' => 'vendor']);

        $this->actingAs($this->user())->get('/wiki')
            ->assertOk()
            ->assertSee('Fortinet')
            ->assertSee('Vendor');
    }

    public function test_client_index_seeds_skeleton_and_shows_pages(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->user())->get("/clients/{$client->id}/wiki");

        $response->assertOk()->assertSee('Infrastructure');
        $this->assertSame(10, WikiPage::forClient($client->id)->count());
    }

    public function test_show_renders_markdown_backlinks_and_fact_summary(): void
    {
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create([
            'slug' => 'infrastructure', 'title' => 'Infrastructure',
            'body_md' => "## Assets\n\n- DC01 runs Windows Server 2022\n",
        ]);
        $linker = WikiPage::factory()->forClient($client)->create([
            'slug' => 'overview', 'title' => 'Overview', 'body_md' => 'See [[infrastructure]]',
        ]);
        app(\App\Services\Wiki\WikiPageService::class)->rebuildLinks($linker);
        WikiFact::factory()->create([
            'client_id' => $client->id, 'page_id' => $page->id,
            'section_anchor' => 'assets', 'status' => 'unverified',
            'statement' => 'DC01 runs Windows Server 2022', 'subject_key' => 'asset:dc01:os',
        ]);

        $this->actingAs($this->user())->get("/clients/{$client->id}/wiki/infrastructure")
            ->assertOk()
            ->assertSee('DC01 runs Windows Server 2022')
            ->assertSee('Overview')
            ->assertSee('1 unverified');
    }

    public function test_global_show_renders_deviation_merge_in_client_context(): void
    {
        $client = Client::factory()->create();
        $global = WikiPage::factory()->create([
            'slug' => 'runbooks/onboarding', 'title' => 'Onboarding', 'kind' => 'runbook',
            'body_md' => "## Hardware\n\nStandard laptop.\n",
        ]);
        WikiPage::factory()->forClient($client)->create([
            'slug' => 'runbooks/onboarding', 'kind' => 'deviation', 'parent_page_id' => $global->id,
            'body_md' => "## Hardware\n\nMac only.\n",
        ]);

        $this->actingAs($this->user())
            ->get("/clients/{$client->id}/wiki/runbooks/onboarding?merged=1")
            ->assertOk()
            ->assertSee('Mac only.')
            ->assertDontSee('Standard laptop.');
    }
}
