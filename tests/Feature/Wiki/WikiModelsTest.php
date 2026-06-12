<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiFactStatus;
use App\Enums\WikiPageKind;
use App\Enums\WikiScope;
use App\Models\Client;
use App\Models\WikiFact;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_casts_and_relations(): void
    {
        $client = Client::factory()->create();
        $global = WikiPage::factory()->create([
            'slug' => 'runbooks/user-onboarding',
            'kind' => WikiPageKind::Runbook,
        ]);
        $deviation = WikiPage::factory()->forClient($client)->create([
            'slug' => 'runbooks/user-onboarding',
            'kind' => WikiPageKind::Deviation,
            'parent_page_id' => $global->id,
        ]);

        $this->assertSame(WikiScope::Global, $global->scope);
        $this->assertSame(WikiPageKind::Deviation, $deviation->kind);
        $this->assertTrue($deviation->parent->is($global));
        $this->assertTrue($global->deviations->first()->is($deviation));
        $this->assertTrue($deviation->client->is($client));
    }

    public function test_fact_casts_and_page_relation(): void
    {
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create(['slug' => 'infrastructure']);
        $fact = WikiFact::factory()->create([
            'client_id' => $client->id,
            'page_id' => $page->id,
            'subject_key' => 'asset:dc01:ram',
            'statement' => 'DC01 has 32 GB RAM',
        ]);

        $this->assertSame(WikiFactStatus::Confirmed, $fact->status);
        $this->assertTrue($fact->page->is($page));
        $this->assertSame(['type' => 'sync', 'id' => 'test'], $fact->source_refs[0]);
        $this->assertTrue($page->facts->first()->is($fact));
    }

    public function test_active_scope_excludes_archived_pages(): void
    {
        WikiPage::factory()->create(['slug' => 'a']);
        WikiPage::factory()->create(['slug' => 'b', 'is_archived' => true]);

        $this->assertSame(['a'], WikiPage::active()->pluck('slug')->all());
    }
}
