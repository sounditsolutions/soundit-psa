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
            'applications', 'backup', 'history', 'infrastructure', 'known-issues',
            'm365', 'network', 'notes', 'overview', 'security',
        ], $slugs);

        $infra = WikiPage::forClient($client->id)->where('slug', 'infrastructure')->first();
        $this->assertStringContainsString('<!-- wiki:facts:assets:start -->', $infra->body_md);
        $this->assertCount(1, $infra->revisions); // second ensure did not rewrite
    }
}
