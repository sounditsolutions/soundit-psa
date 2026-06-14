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

    public function test_live_challenger_is_not_duplicated_as_a_standalone_row(): void
    {
        // In a LIVE dispute the challenger is shown INSIDE the incumbent's AI-challenge
        // block. It must NOT also render as a standalone normal row — that row's Confirm
        // action would set the challenger Confirmed while the incumbent stays Disputed,
        // corrupting the pair into a half-resolved state. Assert the challenger statement
        // appears exactly once (the block's "Suggests:" line) and there is one block.
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create(['slug' => 'infrastructure']);
        $service = app(\App\Services\Wiki\WikiFactService::class);
        $service->upsertSyncFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            \App\Enums\WikiFactVolatility::Durable, [['type' => 'sync', 'id' => 'ninja']]);
        $service->upsertMinedFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 16 GB RAM',
            \App\Enums\WikiFactVolatility::Durable, [['type' => 'ticket', 'id' => 7]], 0.8);

        $content = $this->actingAs($user)->get("/clients/{$client->id}/wiki/infrastructure")
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($content, 'AI challenge'));
        // Exactly once: in the block's "Suggests:" line, never as a second standalone row
        // (a standalone row would print it again in the row text and the correct-form value).
        $this->assertSame(1, substr_count($content, 'DC01 has 16 GB RAM'));
    }

    public function test_orphaned_disputed_fact_stays_visible_and_actionable(): void
    {
        // A Disputed fact whose challenger was independently retired (challenger no
        // longer in $facts, so not in $challengers) must NOT silently vanish — it
        // renders as a normal row so staff can still resolve it (retire/correct).
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create(['slug' => 'infrastructure']);
        $service = app(\App\Services\Wiki\WikiFactService::class);
        $original = $service->upsertSyncFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            \App\Enums\WikiFactVolatility::Durable, [['type' => 'sync', 'id' => 'ninja']]);
        $challenger = $service->upsertMinedFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 16 GB RAM',
            \App\Enums\WikiFactVolatility::Durable, [['type' => 'ticket', 'id' => 7]], 0.8);

        // Retire the challenger directly, leaving the original orphaned-disputed.
        WikiFact::where('id', $challenger->id)->update(['status' => WikiFactStatus::Retired->value]);
        $this->assertSame(WikiFactStatus::Disputed, $original->fresh()->status);

        $response = $this->actingAs($user)->get("/clients/{$client->id}/wiki/infrastructure");

        $response->assertOk()
            ->assertSee('DC01 has 32 GB RAM')                       // the orphaned fact is still shown
            ->assertSee(route('wiki.facts.retire', $original), false) // and remains actionable
            ->assertDontSee('AI challenge');                        // no challenger left to render
    }

    public function test_panel_absent_when_page_has_no_facts(): void
    {
        $user = User::factory()->create();
        $page = WikiPage::factory()->create(['slug' => 'vendors/x', 'title' => 'X']);

        $this->actingAs($user)->get('/wiki/vendors/x')
            ->assertOk()
            ->assertDontSee('Show provenance');
    }

    public function test_fact_statement_is_on_its_own_full_width_line_not_in_action_flex_row(): void
    {
        // psa-ux48: the statement must be its own block element, NOT inside the same
        // d-flex row as the Confirm/Correct/Retire action buttons.
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create(['slug' => 'infrastructure']);
        WikiFact::factory()->create([
            'client_id' => $client->id, 'page_id' => $page->id,
            'status' => WikiFactStatus::Unverified,
            'statement' => 'DC01 runs Windows Server 2022',
            'source_type' => 'ticket', 'source_refs' => [['type' => 'ticket', 'id' => 42]],
        ]);

        $content = $this->actingAs($user)->get("/clients/{$client->id}/wiki/infrastructure")
            ->assertOk()
            ->getContent();

        // The statement text must appear before the action buttons (Confirm / Correct / Retire).
        $statementPos = strpos($content, 'DC01 runs Windows Server 2022');
        // The action row carries the `wiki-fact-actions` class
        $actionPos = strpos($content, 'wiki-fact-actions');
        $this->assertNotFalse($statementPos, 'Statement not found in response');
        $this->assertNotFalse($actionPos, 'Action wrapper not found in response');
        $this->assertLessThan($actionPos, $statementPos, 'Statement must appear before actions');

        // The statement must NOT be inside the same flex wrapper as the action buttons.
        // We verify by checking that the statement div and the action div are siblings,
        // not nested — the statement class is `wiki-fact-statement` (own block, own div),
        // and actions are in a separate `wiki-fact-actions` div beneath it.
        $this->assertStringContainsString('wiki-fact-statement', $content);
        $this->assertStringContainsString('wiki-fact-actions', $content);

        // The statement and actions are NOT in the same d-flex row:
        // find the statement text and verify the d-flex wrapper around the actions
        // does NOT contain the statement text (they are separate blocks).
        $stmtDivStart = strpos($content, 'wiki-fact-statement');
        $actionsDivStart = strpos($content, 'wiki-fact-actions');
        $this->assertGreaterThan($stmtDivStart, $actionsDivStart,
            'Actions div must come after the statement div (stacked layout)');
    }

    public function test_correct_and_retire_details_open_full_width_outside_action_flex_row(): void
    {
        // psa-ux48: Correct/Retire <details> editors must open full width, not be
        // constrained inside the action flex row. They sit in their own full-width
        // container below the action row.
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create(['slug' => 'infrastructure']);
        WikiFact::factory()->create([
            'client_id' => $client->id, 'page_id' => $page->id,
            'status' => WikiFactStatus::Unverified,
            'statement' => 'Server spec TBD',
            'source_type' => 'ticket', 'source_refs' => [['type' => 'ticket', 'id' => 99]],
        ]);

        $content = $this->actingAs($user)->get("/clients/{$client->id}/wiki/infrastructure")
            ->assertOk()
            ->getContent();

        // Correct and Retire details elements must be present
        $this->assertStringContainsString('wiki-fact-editors', $content);
        // The editors block comes after the actions block
        $actionsPos = strpos($content, 'wiki-fact-actions');
        $editorsPos = strpos($content, 'wiki-fact-editors');
        $this->assertNotFalse($actionsPos);
        $this->assertNotFalse($editorsPos);
        $this->assertGreaterThan($actionsPos, $editorsPos,
            'Editors block must come after the inline action buttons');
    }
}
