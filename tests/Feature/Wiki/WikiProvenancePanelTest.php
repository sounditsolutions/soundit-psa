<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiFactStatus;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Models\WikiFact;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiProvenancePanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('wiki_enabled', '1');
    }

    public function test_panel_lists_facts_with_badges_and_actions(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create(['slug' => 'infrastructure']);
        $fact = WikiFact::factory()->create([
            'client_id' => $client->id, 'page_id' => $page->id,
            'status' => WikiFactStatus::Unverified,
            'statement' => 'DC01 runs Windows Server 2022',
            'source_type' => 'ticket', 'source_refs' => [['type' => 'ticket', 'id' => 42]],
        ]);

        $response = $this->actingAs($user)->get("/clients/{$client->id}/wiki/infrastructure");

        $response->assertOk()
            ->assertSee('Show provenance')
            ->assertSee('Unverified')                            // badge text (color never alone)
            ->assertSee(route('wiki.facts.confirm', $fact), false) // confirm form target
            ->assertSee('ticket #42');                            // source attribution
    }

    public function test_disputed_pair_renders_addendum_block(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create(['slug' => 'infrastructure']);
        $service = app(\App\Services\Wiki\WikiFactService::class);
        $original = $service->upsertSyncFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            \App\Enums\WikiFactVolatility::Durable, [['type' => 'sync', 'id' => 'ninja']]);
        $service->upsertMinedFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 16 GB RAM',
            \App\Enums\WikiFactVolatility::Durable, [['type' => 'ticket', 'id' => 7]], 0.8);

        $response = $this->actingAs($user)->get("/clients/{$client->id}/wiki/infrastructure");

        $response->assertOk()
            ->assertSee('AI challenge')
            ->assertSee('DC01 has 16 GB RAM')
            ->assertSee('Accept')
            ->assertSee('Dismiss')
            ->assertDontSee('alert-danger'); // §8.1 item 5: never an error-state block

        // Architecture review: the pair must render the challenge block EXACTLY ONCE
        // (not once per side). This is the assertion the original plan lacked.
        $this->assertSame(1, substr_count($response->getContent(), 'AI challenge'));
    }

    public function test_panel_absent_when_page_has_no_facts(): void
    {
        $user = User::factory()->create();
        $page = WikiPage::factory()->create(['slug' => 'vendors/x', 'title' => 'X']);

        $this->actingAs($user)->get('/wiki/vendors/x')
            ->assertOk()
            ->assertDontSee('Show provenance');
    }
}
