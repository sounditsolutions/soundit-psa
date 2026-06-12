<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiAuthorType;
use App\Enums\WikiPageKind;
use App\Enums\WikiScope;
use App\Models\Client;
use App\Models\WikiLink;
use App\Models\WikiPage;
use App\Services\Wiki\WikiPageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiPageServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): WikiPageService
    {
        return app(WikiPageService::class);
    }

    public function test_create_writes_initial_revision_and_links(): void
    {
        $target = WikiPage::factory()->create(['slug' => 'network']);

        $page = $this->service()->create([
            'scope' => WikiScope::Global,
            'slug' => 'overview-page',
            'title' => 'Overview',
            'kind' => WikiPageKind::Note,
            'body_md' => 'Linked: [[network]] and [[missing-page]].',
        ], WikiAuthorType::Human, authorId: null);

        $this->assertCount(1, $page->revisions);
        $this->assertSame('Created', $page->revisions->first()->change_summary);

        $links = WikiLink::where('from_page_id', $page->id)->get()->keyBy('target_slug');
        $this->assertTrue($links['network']->to_page_id === $target->id);
        $this->assertNull($links['missing-page']->to_page_id); // dead link recorded
    }

    public function test_update_body_writes_revision_and_rebuilds_links(): void
    {
        $page = WikiPage::factory()->create(['body_md' => '[[a]]']);
        WikiPage::factory()->create(['slug' => 'b', 'title' => 'B']);

        $this->service()->updateBody($page, 'now [[b]]', WikiAuthorType::Human, null, 'Edited');

        $this->assertSame('now [[b]]', $page->fresh()->body_md);
        $this->assertSame(['b'], WikiLink::where('from_page_id', $page->id)->pluck('target_slug')->all());
        $this->assertSame('Edited', $page->fresh()->revisions->first()->change_summary);
    }

    public function test_create_rejects_duplicate_global_slug(): void
    {
        WikiPage::factory()->create(['slug' => 'dup']);

        $this->expectException(\RuntimeException::class);

        $this->service()->create([
            'scope' => WikiScope::Global,
            'slug' => 'dup',
            'title' => 'Dup',
            'kind' => WikiPageKind::Note,
            'body_md' => '',
        ], WikiAuthorType::Human, null);
    }

    public function test_deviation_requires_global_root_parent(): void
    {
        $client = Client::factory()->create();
        $globalRunbook = WikiPage::factory()->create([
            'slug' => 'runbooks/onboarding', 'kind' => WikiPageKind::Runbook,
        ]);

        // Valid: deviation under a global, parentless page.
        $deviation = $this->service()->create([
            'scope' => WikiScope::Client,
            'client_id' => $client->id,
            'slug' => 'runbooks/onboarding',
            'title' => 'Onboarding (deviation)',
            'kind' => WikiPageKind::Deviation,
            'parent_page_id' => $globalRunbook->id,
            'body_md' => '## Steps\n\nExcept step 3.',
        ], WikiAuthorType::Human, null);
        $this->assertTrue($deviation->parent->is($globalRunbook));

        // Invalid: deviation chained under a deviation (depth > 1).
        $this->expectException(\RuntimeException::class);
        $this->service()->create([
            'scope' => WikiScope::Client,
            'client_id' => $client->id,
            'slug' => 'runbooks/onboarding-2',
            'title' => 'Bad chain',
            'kind' => WikiPageKind::Deviation,
            'parent_page_id' => $deviation->id,
            'body_md' => '',
        ], WikiAuthorType::Human, null);
    }

    public function test_archive_sets_flag_and_writes_revision(): void
    {
        $page = WikiPage::factory()->create();

        $this->service()->archive($page, WikiAuthorType::Human, null);

        $this->assertTrue($page->fresh()->is_archived);
        $this->assertSame('Archived', $page->fresh()->revisions->first()->change_summary);
    }

    public function test_update_body_with_unchanged_links_is_safe(): void
    {
        WikiPage::factory()->create(['slug' => 'x', 'title' => 'X']);
        $page = WikiPage::factory()->create(['body_md' => 'see [[x]]']);
        $this->service()->rebuildLinks($page);

        $this->service()->updateBody($page, 'still see [[x]]', WikiAuthorType::Human, null, 'reword');

        $this->assertSame(['x'], WikiLink::where('from_page_id', $page->id)->pluck('target_slug')->all());
        $this->assertCount(1, $page->fresh()->revisions); // factory page had no create-revision; updateBody wrote one
    }
}
