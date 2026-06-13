<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiFactSource;
use App\Enums\WikiFactStatus;
use App\Enums\WikiFactVolatility;
use App\Enums\WikiPageKind;
use App\Enums\WikiScope;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Models\WikiFact;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the hardened MCP `client_id` cast (spec §6 isolation
 * control). Exercises the cast end-to-end through the real `tools/call` path —
 * route auth (VerifyMcpStaffToken) included — so a malformed/zero/garbage
 * client_id collapses to GLOBAL-only scope and can never reach a `client_id = 0`
 * query that would surface a real client's facts.
 */
class WikiToolsMcpTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'psa-mcp-test-token';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('wiki_enabled', '1');
        Setting::setEncrypted('mcp_staff_token', self::TOKEN);
        // callTool() resolves the service-account actor via TriageConfig::systemUserId(),
        // which falls back to the lowest user id — ensure one exists.
        User::factory()->create();
    }

    /** Seed one GLOBAL fact and one CLIENT fact, each matching the same query. */
    private function seedFacts(Client $client): void
    {
        $globalPage = WikiPage::factory()->create([
            'scope' => WikiScope::Global, 'client_id' => null, 'slug' => 'vendors/fortinet',
            'title' => 'Fortinet', 'kind' => WikiPageKind::Vendor, 'body_md' => "## Notes\n",
        ]);
        WikiFact::factory()->create([
            'scope' => WikiScope::Global, 'client_id' => null, 'page_id' => $globalPage->id,
            'section_anchor' => 'notes', 'subject_key' => 'vendor:fortinet', 'statement' => 'GLOBAL-FortiGate-note',
            'status' => WikiFactStatus::Confirmed, 'source_type' => WikiFactSource::Ticket, 'volatility' => WikiFactVolatility::Durable,
        ]);

        $clientPage = WikiPage::factory()->forClient($client)->create([
            'slug' => 'network', 'title' => 'Network', 'kind' => WikiPageKind::Environment, 'body_md' => "## Equipment\n",
        ]);
        WikiFact::factory()->create([
            'scope' => WikiScope::Client, 'client_id' => $client->id, 'page_id' => $clientPage->id,
            'section_anchor' => 'equipment', 'subject_key' => 'network:fw', 'statement' => 'CLIENT-FortiGate-note',
            'status' => WikiFactStatus::Confirmed, 'source_type' => WikiFactSource::Ticket, 'volatility' => WikiFactVolatility::Durable,
        ]);
    }

    private function callWikiSearch(mixed $clientId): string
    {
        $arguments = ['query' => 'FortiGate'];
        if ($clientId !== null) {
            $arguments['client_id'] = $clientId;
        }

        $response = $this->withHeaders(['Authorization' => 'Bearer '.self::TOKEN])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'wiki_search', 'arguments' => $arguments],
            ]);

        $response->assertOk();

        // tools/call wraps the executor output as a text content block.
        return (string) $response->json('result.content.0.text');
    }

    public function test_client_id_zero_collapses_to_global_only(): void
    {
        $client = Client::factory()->create();
        $this->seedFacts($client);

        $text = $this->callWikiSearch(0);

        $this->assertStringContainsString('GLOBAL-FortiGate-note', $text);
        $this->assertStringNotContainsString('CLIENT-FortiGate-note', $text);
    }

    public function test_client_id_garbage_collapses_to_global_only(): void
    {
        $client = Client::factory()->create();
        $this->seedFacts($client);

        $text = $this->callWikiSearch('garbage');

        $this->assertStringContainsString('GLOBAL-FortiGate-note', $text);
        $this->assertStringNotContainsString('CLIENT-FortiGate-note', $text);
    }

    public function test_real_positive_client_id_returns_that_clients_fact(): void
    {
        $client = Client::factory()->create();
        $this->seedFacts($client);

        $text = $this->callWikiSearch($client->id);

        $this->assertStringContainsString('CLIENT-FortiGate-note', $text);
    }
}
