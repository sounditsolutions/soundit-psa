<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiFactStatus;
use App\Enums\WikiRunStatus;
use App\Jobs\MineTicketKnowledge;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\WikiFact;
use App\Models\WikiRun;
use App\Services\Ai\AiClient;
use App\Services\Wiki\WikiSkeletonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MineTicketKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    private function enableWiki(): void
    {
        Setting::setValue('wiki_enabled', '1');
        Setting::setValue('wiki_auto_mine', '1');
    }

    private function makeClosedTicketWithResolution(Client $client, string $resolution = 'Fixed the firewall.'): Ticket
    {
        return Ticket::factory()->create([
            'client_id' => $client->id,
            'resolution' => $resolution,
        ]);
    }

    private function mockAiNoFacts(): void
    {
        $mock = $this->mock(AiClient::class);
        $mock->shouldReceive('completeJson')->andReturn(['facts' => []]);
        $mock->shouldReceive('cumulativeInputTokens')->andReturn(500);
        $mock->shouldReceive('cumulativeOutputTokens')->andReturn(100);
        $mock->shouldReceive('cumulativeTotalTokens')->andReturn(600);
    }

    // ── gate tests ────────────────────────────────────────────────────────────

    public function test_auto_mine_off_is_noop(): void
    {
        // wiki_enabled=0, wiki_auto_mine=0 — job should return early without touching anything
        $client = Client::factory()->create();
        $ticket = $this->makeClosedTicketWithResolution($client);

        MineTicketKnowledge::dispatchSync($ticket->id);

        $this->assertSame(0, WikiRun::count());
    }

    public function test_noop_when_ticket_has_no_resolution(): void
    {
        $this->enableWiki();
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'resolution' => null]);

        MineTicketKnowledge::dispatchSync($ticket->id);

        $this->assertSame(0, WikiRun::count());
    }

    public function test_noop_when_ticket_is_a_merge_closure(): void
    {
        $this->enableWiki();
        $client = Client::factory()->create();
        $parent = Ticket::factory()->create(['client_id' => $client->id, 'resolution' => 'main ticket']);
        $child = Ticket::factory()->create([
            'client_id' => $client->id,
            'resolution' => 'merged',
            'parent_ticket_id' => $parent->id,
        ]);

        MineTicketKnowledge::dispatchSync($child->id);

        $this->assertSame(0, WikiRun::count());
    }

    // ── idempotency test ──────────────────────────────────────────────────────

    public function test_idempotency_skips_already_processed_content_hash(): void
    {
        $this->enableWiki();
        $client = Client::factory()->create();
        $ticket = $this->makeClosedTicketWithResolution($client, 'Fixed the firewall.');
        $this->mockAiNoFacts();

        app(WikiSkeletonService::class)->ensureForClient($client);

        // First run — should create a WikiRun
        MineTicketKnowledge::dispatchSync($ticket->id);
        $this->assertSame(1, WikiRun::count());

        // Second run with same resolution — should skip (idempotency key matches)
        MineTicketKnowledge::dispatchSync($ticket->id);
        $this->assertSame(1, WikiRun::count()); // still just 1
    }

    // ── budget-defer test ─────────────────────────────────────────────────────

    public function test_defers_when_daily_budget_exhausted(): void
    {
        $this->enableWiki();
        Setting::setValue('wiki_daily_token_limit', '100'); // tiny limit

        $client = Client::factory()->create();
        $ticket = $this->makeClosedTicketWithResolution($client);
        app(WikiSkeletonService::class)->ensureForClient($client);

        // Fill the daily budget with a completed run
        WikiRun::create([
            'run_type' => 'mine_ticket',
            'subject_type' => 'ticket',
            'subject_id' => 0,
            'source_content_hash' => 'fakehash',
            'status' => WikiRunStatus::Completed,
            'ai_tokens_used' => ['input' => 60, 'output' => 60],
            'triggered_by' => 'auto',
        ]);

        // Run — budget exhausted, should not create a second completed run (deferred)
        MineTicketKnowledge::dispatchSync($ticket->id);

        // Should NOT have created a new completed WikiRun (budget exceeded, deferred)
        $completed = WikiRun::where('status', WikiRunStatus::Completed)->count();
        $this->assertSame(1, $completed); // only the pre-existing one
    }

    // ── quarantine tests (H1, H2 security checks) ────────────────────────────

    public function test_quarantines_on_secret_in_statement(): void
    {
        $this->enableWiki();
        $client = Client::factory()->create();
        $ticket = $this->makeClosedTicketWithResolution($client, 'Fixed firewall config.');
        app(WikiSkeletonService::class)->ensureForClient($client);

        $mock = $this->mock(AiClient::class);
        $mock->shouldReceive('completeJson')->andReturn(['facts' => [
            [
                'page' => 'network',
                'anchor' => 'equipment',
                'subject_key' => 'network:edge-firewall',
                'statement' => 'password=SuperSecret123 on FortiGate',
                'volatility' => 'durable',
                'confidence' => 0.9,
            ],
        ]]);
        $mock->shouldReceive('cumulativeInputTokens')->andReturn(500);
        $mock->shouldReceive('cumulativeOutputTokens')->andReturn(100);
        $mock->shouldReceive('cumulativeTotalTokens')->andReturn(600);

        MineTicketKnowledge::dispatchSync($ticket->id);

        $run = WikiRun::first();
        $this->assertSame(WikiRunStatus::Quarantined, $run->status);
        $this->assertSame(0, WikiFact::count()); // nothing stored

        // H1: errors payload must NOT contain the raw secret value
        $errors = $run->errors ?? [];
        $errorsJson = json_encode($errors);
        $this->assertStringNotContainsString('SuperSecret123', $errorsJson);
    }

    public function test_quarantines_on_secret_in_subject_key(): void
    {
        $this->enableWiki();
        $client = Client::factory()->create();
        $ticket = $this->makeClosedTicketWithResolution($client, 'Updated config.');
        app(WikiSkeletonService::class)->ensureForClient($client);

        $mock = $this->mock(AiClient::class);
        // subject_key contains a secret-shaped value; statement is clean
        $mock->shouldReceive('completeJson')->andReturn(['facts' => [
            [
                'page' => 'network',
                'anchor' => 'equipment',
                'subject_key' => 'network:token=abc123secretkey',
                'statement' => 'Edge firewall is a FortiGate 60F',
                'volatility' => 'durable',
                'confidence' => 0.9,
            ],
        ]]);
        $mock->shouldReceive('cumulativeInputTokens')->andReturn(500);
        $mock->shouldReceive('cumulativeOutputTokens')->andReturn(100);
        $mock->shouldReceive('cumulativeTotalTokens')->andReturn(600);

        MineTicketKnowledge::dispatchSync($ticket->id);

        $run = WikiRun::first();
        $this->assertSame(WikiRunStatus::Quarantined, $run->status);
        $this->assertSame(0, WikiFact::count()); // nothing stored
        // H1: errors payload must NOT contain the raw statement text
        $errors = $run->errors ?? [];
        $errorsJson = json_encode($errors);
        // The subject_key may be in errors (it's metadata), but the STATEMENT must not
        $this->assertStringNotContainsString('Edge firewall is a FortiGate 60F', $errorsJson);
    }

    // ── happy-path: zero facts is fine ───────────────────────────────────────

    public function test_zero_facts_completes_successfully(): void
    {
        $this->enableWiki();
        $client = Client::factory()->create();
        $ticket = $this->makeClosedTicketWithResolution($client, 'Routine maintenance, no new info.');
        app(WikiSkeletonService::class)->ensureForClient($client);
        $this->mockAiNoFacts();

        MineTicketKnowledge::dispatchSync($ticket->id);

        $run = WikiRun::first();
        $this->assertNotNull($run);
        $this->assertSame(WikiRunStatus::Completed, $run->status);
        $this->assertSame(0, WikiFact::count());
    }

    // ── happy-path: facts are written ────────────────────────────────────────

    public function test_valid_facts_are_written_as_unverified(): void
    {
        $this->enableWiki();
        $client = Client::factory()->create();
        $ticket = $this->makeClosedTicketWithResolution($client, 'Replaced the FortiGate 60F firewall.');
        app(WikiSkeletonService::class)->ensureForClient($client);

        $mock = $this->mock(AiClient::class);
        $mock->shouldReceive('completeJson')->andReturn(['facts' => [
            [
                'page' => 'network',
                'anchor' => 'equipment',
                'subject_key' => 'network:edge-firewall',
                'statement' => 'Edge firewall is a FortiGate 60F',
                'volatility' => 'durable',
                'confidence' => 0.9,
            ],
        ]]);
        $mock->shouldReceive('cumulativeInputTokens')->andReturn(500);
        $mock->shouldReceive('cumulativeOutputTokens')->andReturn(100);
        $mock->shouldReceive('cumulativeTotalTokens')->andReturn(600);

        MineTicketKnowledge::dispatchSync($ticket->id);

        $run = WikiRun::first();
        $this->assertSame(WikiRunStatus::Completed, $run->status);

        $fact = WikiFact::first();
        $this->assertNotNull($fact);
        $this->assertSame(WikiFactStatus::Unverified, $fact->status);
        $this->assertSame('network:edge-firewall', $fact->subject_key);
        $this->assertSame('Edge firewall is a FortiGate 60F', $fact->statement);
    }
}
