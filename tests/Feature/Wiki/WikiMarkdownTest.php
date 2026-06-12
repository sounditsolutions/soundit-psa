<?php

namespace Tests\Feature\Wiki;

use App\Models\Client;
use App\Models\WikiPage;
use App\Services\Wiki\WikiLinkResolver;
use App\Services\Wiki\WikiMarkdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiMarkdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_prefers_client_scope_then_global(): void
    {
        $client = Client::factory()->create();
        $global = WikiPage::factory()->create(['slug' => 'network']);
        $clientPage = WikiPage::factory()->forClient($client)->create(['slug' => 'network']);

        $resolver = app(WikiLinkResolver::class);

        $this->assertTrue($resolver->resolve('network', $client->id)->is($clientPage));
        $this->assertTrue($resolver->resolve('network', null)->is($global));
        $this->assertNull($resolver->resolve('missing', $client->id));
    }

    public function test_render_converts_wikilinks_to_anchors_and_sanitizes(): void
    {
        $client = Client::factory()->create();
        WikiPage::factory()->forClient($client)->create(['slug' => 'network', 'title' => 'Network']);
        $page = WikiPage::factory()->forClient($client)->create([
            'slug' => 'overview',
            'body_md' => "See [[network|the network]].\n\n<script>alert(1)</script>",
        ]);

        $html = app(WikiMarkdown::class)->render($page);

        $this->assertStringContainsString('the network</a>', $html);
        $this->assertStringContainsString(route('clients.wiki.show', [$client, 'network']), $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_unresolved_wikilink_renders_as_plain_text(): void
    {
        $page = WikiPage::factory()->create(['body_md' => 'See [[nowhere|missing page]].']);

        $html = app(WikiMarkdown::class)->render($page);

        $this->assertStringContainsString('missing page', $html);
        $this->assertStringNotContainsString('<a', $html);
    }
}
