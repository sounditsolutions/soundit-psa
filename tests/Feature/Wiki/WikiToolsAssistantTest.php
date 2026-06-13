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

    private function seedFact(Client $client): void
    {
        $page = WikiPage::factory()->forClient($client)->create([
            'slug' => 'network', 'title' => 'Network', 'kind' => WikiPageKind::Environment, 'body_md' => "## Equipment\n",
        ]);
        WikiFact::factory()->create([
            'scope' => WikiScope::Client, 'client_id' => $client->id, 'page_id' => $page->id,
            'section_anchor' => 'equipment', 'subject_key' => 'network:fw', 'statement' => 'Edge firewall is a FortiGate 60F',
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
        $this->seedFact($rival);
        $out = (new AssistantToolExecutor(ticket: null, clientId: $acme->id, userId: null))->execute('wiki_search', ['query' => 'FortiGate']);
        $this->assertStringNotContainsString('FortiGate 60F', is_string($out) ? $out : json_encode($out));
    }
}
