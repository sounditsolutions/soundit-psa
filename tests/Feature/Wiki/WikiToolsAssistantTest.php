<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiFactSource;
use App\Enums\WikiFactStatus;
use App\Enums\WikiFactVolatility;
use App\Enums\WikiPageKind;
use App\Enums\WikiScope;
use App\Models\Client;
use App\Models\Setting;
use App\Models\WikiFact;
use App\Models\WikiPage;
use App\Services\Assistant\AssistantToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiToolsAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('wiki_enabled', '1');
    }

    private function seedFact(Client $client, string $statement = 'Edge firewall is a FortiGate 60F'): WikiPage
    {
        $page = WikiPage::factory()->forClient($client)->create([
            'slug' => 'network', 'title' => 'Network', 'kind' => WikiPageKind::Environment, 'body_md' => "## Equipment\n",
        ]);
        WikiFact::factory()->create([
            'scope' => WikiScope::Client, 'client_id' => $client->id, 'page_id' => $page->id,
            'section_anchor' => 'equipment', 'subject_key' => 'network:fw', 'statement' => $statement,
            'status' => WikiFactStatus::Confirmed, 'source_type' => WikiFactSource::Ticket, 'volatility' => WikiFactVolatility::Durable,
        ]);

        return $page;
    }

    private function seedGlobalFact(string $statement = 'Global FortiGate vendor note'): void
    {
        $page = WikiPage::factory()->create([
            'scope' => WikiScope::Global, 'client_id' => null, 'slug' => 'vendors/fortinet',
            'title' => 'Fortinet', 'kind' => WikiPageKind::Vendor, 'body_md' => "## Notes\n",
        ]);
        WikiFact::factory()->create([
            'scope' => WikiScope::Global, 'client_id' => null, 'page_id' => $page->id,
            'section_anchor' => 'notes', 'subject_key' => 'vendor:fortinet', 'statement' => $statement,
            'status' => WikiFactStatus::Confirmed, 'source_type' => WikiFactSource::Ticket, 'volatility' => WikiFactVolatility::Durable,
        ]);
    }

    public function test_wiki_search_returns_structured_records(): void
    {
        $client = Client::factory()->create();
        $this->seedFact($client);
        $out = (new AssistantToolExecutor(ticket: null, clientId: $client->id, userId: null))->execute('wiki_search', ['query' => 'FortiGate']);
        $this->assertStringContainsString('WIKI_FACT | subject: network:fw', $out);
    }

    public function test_wiki_tools_no_op_when_disabled(): void
    {
        Setting::setValue('wiki_enabled', '0');
        $client = Client::factory()->create();
        $this->seedFact($client);
        $out = (new AssistantToolExecutor(ticket: null, clientId: $client->id, userId: null))->execute('wiki_search', ['query' => 'FortiGate']);
        $this->assertSame(['error' => 'The wiki is not enabled.'], $out);
    }

    public function test_scope_isolation(): void
    {
        $acme = Client::factory()->create();
        $rival = Client::factory()->create();
        // Distinct statements so the test fails (not trivially passes via the
        // 'No matching wiki content.' sentinel) if isolation ever breaks.
        $this->seedFact($acme, 'ACME-only FortiGate 60F');
        $this->seedFact($rival, 'RIVAL-only FortiGate 60F');

        $out = (new AssistantToolExecutor(ticket: null, clientId: $acme->id, userId: null))->execute('wiki_search', ['query' => 'FortiGate']);

        // Positive anchor: acme's fact present; rival's absent in the SAME output.
        $this->assertStringContainsString('ACME-only FortiGate 60F', $out);
        $this->assertStringNotContainsString('RIVAL-only FortiGate 60F', $out);
    }

    public function test_null_client_searches_global_only(): void
    {
        $client = Client::factory()->create();
        $this->seedFact($client, 'CLIENT-only FortiGate 60F');
        $this->seedGlobalFact('GLOBAL-only FortiGate note');

        // A null clientId is valid for wiki tools — it means global-only (§6).
        $out = (new AssistantToolExecutor(ticket: null, clientId: null, userId: null))->execute('wiki_search', ['query' => 'FortiGate']);

        $this->assertStringContainsString('GLOBAL-only FortiGate note', $out);
        $this->assertStringNotContainsString('CLIENT-only FortiGate 60F', $out);
    }

    public function test_wiki_get_page_returns_envelope_for_real_slug(): void
    {
        $client = Client::factory()->create();
        $page = $this->seedFact($client);
        $page->update(['body_md' => "## Equipment\n\n- FortiGate 60F edge firewall\n"]);

        $out = (new AssistantToolExecutor(ticket: null, clientId: $client->id, userId: null))->execute('wiki_get_page', ['slug' => 'network']);

        $this->assertSame('network', $out['slug']);
        $this->assertSame('Network', $out['title']);
        $this->assertStringContainsString('FortiGate 60F edge firewall', $out['body_md']);
    }

    public function test_wiki_get_page_returns_not_found_for_missing_slug(): void
    {
        $client = Client::factory()->create();

        $out = (new AssistantToolExecutor(ticket: null, clientId: $client->id, userId: null))->execute('wiki_get_page', ['slug' => 'does-not-exist']);

        $this->assertSame(['error' => "Wiki page 'does-not-exist' not found in scope."], $out);
    }
}
