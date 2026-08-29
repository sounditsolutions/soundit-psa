<?php

namespace Tests\Feature\Wiki;

use App\Models\Client;
use App\Models\WikiPage;
use App\Services\Wiki\WikiSkeletonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiSkeletonServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_standard_pages_once(): void
    {
        $client = Client::factory()->create();

        app(WikiSkeletonService::class)->ensureForClient($client);
        app(WikiSkeletonService::class)->ensureForClient($client); // idempotent

        $slugs = WikiPage::forClient($client->id)->pluck('slug')->sort()->values()->all();
        $this->assertSame([
            'applications', 'backup', 'billing', 'history', 'infrastructure',
            'known-issues', 'm365', 'network', 'notes', 'overview', 'security',
        ], $slugs);

        $infra = WikiPage::forClient($client->id)->where('slug', 'infrastructure')->first();
        $this->assertStringContainsString('<!-- wiki:facts:assets:start -->', $infra->body_md);
        $this->assertCount(1, $infra->revisions); // second ensure did not rewrite

        $billing = WikiPage::forClient($client->id)->where('slug', 'billing')->first();
        $this->assertSame('Billing & licensing', $billing->title);
        $this->assertStringContainsString('## Arrangements', $billing->body_md);
        $this->assertStringContainsString('<!-- wiki:facts:arrangements:start -->', $billing->body_md);
        $this->assertStringContainsString('## Licensing', $billing->body_md);
        $this->assertStringContainsString('<!-- wiki:facts:licensing:start -->', $billing->body_md);
    }

    public function test_backfills_new_blueprint_page_for_already_seeded_client(): void
    {
        // A client seeded before `billing` existed holds every page but that one. The
        // warm-path short-circuit counts blueprint slugs, so the missing page must make
        // the count fall short and the next ensure must create ONLY the missing page.
        $client = Client::factory()->create();
        app(WikiSkeletonService::class)->ensureForClient($client);
        WikiPage::forClient($client->id)->where('slug', 'billing')->first()->delete();

        app(WikiSkeletonService::class)->ensureForClient($client);

        $billing = WikiPage::forClient($client->id)->where('slug', 'billing')->first();
        $this->assertNotNull($billing);
        $this->assertCount(1, $billing->revisions); // freshly seeded, once

        $network = WikiPage::forClient($client->id)->where('slug', 'network')->first();
        $this->assertCount(1, $network->revisions); // pre-existing pages untouched
    }
}
