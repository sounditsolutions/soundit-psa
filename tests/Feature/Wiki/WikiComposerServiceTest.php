<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiFactStatus;
use App\Models\Client;
use App\Models\WikiFact;
use App\Models\WikiPage;
use App\Services\Wiki\WikiComposerService;
use App\Services\Wiki\WikiSkeletonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiComposerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_composes_active_facts_into_marked_section(): void
    {
        $client = Client::factory()->create();
        app(WikiSkeletonService::class)->ensureForClient($client);
        $page = WikiPage::forClient($client->id)->where('slug', 'infrastructure')->first();

        WikiFact::factory()->create([
            'client_id' => $client->id, 'page_id' => $page->id, 'section_anchor' => 'assets',
            'subject_key' => 'asset:dc01:os', 'statement' => 'DC01 runs Windows Server 2022',
        ]);
        WikiFact::factory()->create([
            'client_id' => $client->id, 'page_id' => $page->id, 'section_anchor' => 'assets',
            'subject_key' => 'asset:dc01:ram', 'statement' => 'DC01 has 32 GB RAM',
            'status' => WikiFactStatus::Retired,
        ]);

        $changed = app(WikiComposerService::class)->composeSection($page, 'assets');

        $body = $page->fresh()->body_md;
        $this->assertTrue($changed);
        $this->assertStringContainsString('- DC01 runs Windows Server 2022', $body);
        $this->assertStringNotContainsString('32 GB RAM', $body);
        $this->assertStringNotContainsString('_No facts recorded yet._', $body);
    }

    public function test_no_write_when_content_unchanged(): void
    {
        $client = Client::factory()->create();
        app(WikiSkeletonService::class)->ensureForClient($client);
        $page = WikiPage::forClient($client->id)->where('slug', 'infrastructure')->first();
        WikiFact::factory()->create([
            'client_id' => $client->id, 'page_id' => $page->id, 'section_anchor' => 'assets',
            'subject_key' => 'asset:dc01:os', 'statement' => 'DC01 runs Windows Server 2022',
        ]);

        app(WikiComposerService::class)->composeSection($page, 'assets');
        $revisions = $page->fresh()->revisions()->count();

        $changedAgain = app(WikiComposerService::class)->composeSection($page->fresh(), 'assets');

        $this->assertFalse($changedAgain);
        $this->assertSame($revisions, $page->fresh()->revisions()->count());
    }
}
