<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiFactSource;
use App\Enums\WikiFactStatus;
use App\Enums\WikiFactVolatility;
use App\Enums\WikiPageKind;
use App\Enums\WikiScope;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\WikiFact;
use App\Models\WikiPage;
use App\Services\Assistant\AssistantToolDefinitions;
use App\Services\Triage\TriageToolDefinitions;
use App\Services\Triage\TriageToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiToolsTriageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('wiki_enabled', '1');
    }

    public function test_triage_executor_returns_scoped_facts(): void
    {
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create([
            'slug' => 'known-issues', 'title' => 'Known Issues', 'kind' => WikiPageKind::Note, 'body_md' => "## Active\n",
        ]);
        WikiFact::factory()->create([
            'scope' => WikiScope::Client, 'client_id' => $client->id, 'page_id' => $page->id, 'section_anchor' => 'active',
            'subject_key' => 'issue:vpn-dtls', 'statement' => 'FortiClient DTLS causes afternoon VPN drops',
            'status' => WikiFactStatus::Unverified, 'source_type' => WikiFactSource::Ticket, 'volatility' => WikiFactVolatility::Volatile,
        ]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        $out = (new TriageToolExecutor($ticket))->execute('wiki_search', ['query' => 'VPN']);
        $this->assertStringContainsString('WIKI_FACT | subject: issue:vpn-dtls', $out);
        $this->assertStringContainsString('status: unverified', $out);
    }

    public function test_definitions_single_owner_and_referenced(): void
    {
        $triage = array_column(TriageToolDefinitions::getTools(), 'name');
        $withClient = array_column(AssistantToolDefinitions::getTools(hasClient: true), 'name');
        $general = array_column(AssistantToolDefinitions::getTools(hasClient: false), 'name');
        foreach (['wiki_list_pages', 'wiki_search', 'wiki_get_page'] as $t) {
            $this->assertContains($t, $triage);
            $this->assertContains($t, $withClient);
            $this->assertContains($t, $general);
        }
    }
}
