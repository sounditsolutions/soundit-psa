<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiPageKind;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\WikiPage;
use App\Services\Triage\ContextBuilder;
use App\Services\Wiki\WikiSkeletonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiOverviewInjectionTest extends TestCase
{
    use RefreshDatabase;

    private function ticketFor(Client $client): Ticket
    {
        return Ticket::factory()->create(['client_id' => $client->id]);
    }

    private function overview(Client $client, string $body, bool $composed = true): void
    {
        $page = WikiPage::factory()->forClient($client)->create([
            'slug' => 'overview', 'kind' => WikiPageKind::Overview, 'body_md' => $body,
        ]);
        if ($composed) {
            $page->update(['meta' => ['composed_at' => now()->toIso8601String()]]);
        }
    }

    public function test_wiki_off_injects_site_notes(): void
    {
        Setting::setValue('wiki_enabled', '0');
        $client = Client::factory()->create(['site_notes' => 'Legacy notes: gateway 10.0.0.1.']);
        $ctx = ContextBuilder::buildForTicket($this->ticketFor($client));
        $this->assertStringContainsString('Legacy notes', $ctx);
        $this->assertStringContainsString('Client Site Notes', $ctx);
    }

    public function test_composed_overview_replaces_site_notes(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $client = Client::factory()->create(['site_notes' => 'Legacy notes: gateway 10.0.0.1.']);
        $this->overview($client, str_repeat('Windows-shop; DC01 on Server 2022; standard onboarding. ', 6));
        $ctx = ContextBuilder::buildForTicket($this->ticketFor($client));
        $this->assertStringContainsString('Client Environment Overview', $ctx);
        $this->assertStringNotContainsString('Legacy notes', $ctx);
    }

    public function test_placeholder_overview_falls_back_to_site_notes(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $client = Client::factory()->create(['site_notes' => 'Legacy notes: gateway 10.0.0.1.']);
        $this->overview($client, WikiSkeletonService::OVERVIEW_PLACEHOLDER_BODY, composed: false);
        $ctx = ContextBuilder::buildForTicket($this->ticketFor($client));
        $this->assertStringContainsString('Legacy notes', $ctx);
        $this->assertStringNotContainsString('Client Environment Overview', $ctx);
    }

    public function test_thin_overview_does_not_displace_rich_site_notes(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $rich = str_repeat('Detailed human-curated environment notes. ', 20);
        $client = Client::factory()->create(['site_notes' => $rich]);
        $this->overview($client, "## Env\n\nDC01.\n"); // composed but tiny (< floor)
        $ctx = ContextBuilder::buildForTicket($this->ticketFor($client));
        $this->assertStringContainsString('Detailed human-curated', $ctx); // site_notes wins
    }

    public function test_both_empty_injects_nothing(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $client = Client::factory()->create(['site_notes' => null]);
        $ctx = ContextBuilder::buildForTicket($this->ticketFor($client));
        $this->assertStringNotContainsString('Client Environment Overview', $ctx);
        $this->assertStringNotContainsString('Client Site Notes', $ctx);
    }
}
