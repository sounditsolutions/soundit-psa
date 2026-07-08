<?php

namespace Tests\Feature\Wiki;

use App\Enums\TicketStatus;
use App\Enums\WikiRunStatus;
use App\Enums\WikiRunType;
use App\Jobs\MineTicketKnowledge;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\WikiRun;
use App\Services\Wiki\WikiBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class WikiBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('wiki_enabled', '1');
        Setting::setValue('wiki_auto_mine', '1');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Create N closed tickets with resolutions, seeded oldest→newest via closed_at.
     *
     * @return \Illuminate\Support\Collection<int, Ticket>
     */
    private function closedTicketsWithResolutions(int $count, ?Client $client = null): \Illuminate\Support\Collection
    {
        return collect(range(1, $count))->map(function (int $i) use ($client, $count) {
            return Ticket::factory()->create([
                'client_id' => $client?->id ?? Client::factory()->create()->id,
                'status' => TicketStatus::Closed->value,
                'resolution' => "Resolution for ticket {$i}",
                'closed_at' => now()->subDays($count - $i + 1), // oldest first
            ]);
        });
    }

    /**
     * Mark a ticket as already mined (completed wiki_run exists).
     */
    private function markMined(Ticket $ticket): WikiRun
    {
        $contentHash = hash('sha256', $ticket->id.'|'.$ticket->resolution);

        return WikiRun::create([
            'run_type' => WikiRunType::MineTicket->value,
            'subject_type' => 'ticket',
            'subject_id' => $ticket->id,
            'source_content_hash' => $contentHash,
            'status' => WikiRunStatus::Completed->value,
            'triggered_by' => 'auto',
        ]);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_dry_run_writes_nothing_and_estimates(): void
    {
        Bus::fake();
        $this->closedTicketsWithResolutions(3);

        $plan = app(WikiBackfillService::class)->plan(null, 25);

        $this->assertSame(3, $plan['ticket_count']);
        $this->assertGreaterThan(0, $plan['estimated_tokens']);
        $this->assertArrayHasKey('daily_ceiling', $plan);
        $this->assertArrayHasKey('auto_mine_on', $plan);
        Bus::assertNotDispatched(MineTicketKnowledge::class);
        $this->assertSame(0, WikiRun::count());
    }

    public function test_plan_includes_oldest_and_newest_ticket_ids(): void
    {
        $tickets = $this->closedTicketsWithResolutions(3);
        $plan = app(WikiBackfillService::class)->plan(null, 25);

        $this->assertNotNull($plan['oldest']);
        $this->assertNotNull($plan['newest']);
        // oldest should be the one with the earliest closed_at
        $this->assertSame($tickets->first()->id, $plan['oldest']);
        $this->assertSame($tickets->last()->id, $plan['newest']);
    }

    public function test_plan_estimate_capped_at_daily_ceiling(): void
    {
        Setting::setValue('wiki_daily_token_limit', '1000');
        $this->closedTicketsWithResolutions(5);

        $plan = app(WikiBackfillService::class)->plan(null, 25);

        $this->assertLessThanOrEqual(1000, $plan['estimated_tokens']);
        $this->assertSame(1000, $plan['daily_ceiling']);
    }

    public function test_oldest_first_and_batch_capped(): void
    {
        Bus::fake();
        $tickets = $this->closedTicketsWithResolutions(5);

        $dispatched = app(WikiBackfillService::class)->execute(null, 2);

        $this->assertSame(2, $dispatched);
        Bus::assertDispatchedTimes(MineTicketKnowledge::class, 2);

        // The two dispatched jobs should be for the oldest two tickets
        $dispatchedIds = [];
        Bus::assertDispatched(MineTicketKnowledge::class, function (MineTicketKnowledge $job) use (&$dispatchedIds) {
            $ref = new \ReflectionClass($job);
            $prop = $ref->getProperty('ticketId');
            $prop->setAccessible(true);
            $dispatchedIds[] = $prop->getValue($job);

            return true;
        });

        sort($dispatchedIds);
        $oldestTwoIds = $tickets->take(2)->pluck('id')->sort()->values()->all();
        $this->assertSame($oldestTwoIds, $dispatchedIds);
    }

    public function test_already_mined_ticket_is_skipped(): void
    {
        Bus::fake();
        $tickets = $this->closedTicketsWithResolutions(3);

        // Mark the first two as already mined
        $this->markMined($tickets[0]);
        $this->markMined($tickets[1]);

        $dispatched = app(WikiBackfillService::class)->execute(null, 10);

        // Only the third (unmined) ticket should be dispatched
        $this->assertSame(1, $dispatched);
        Bus::assertDispatchedTimes(MineTicketKnowledge::class, 1);

        $dispatchedIds = [];
        Bus::assertDispatched(MineTicketKnowledge::class, function (MineTicketKnowledge $job) use (&$dispatchedIds) {
            $ref = new \ReflectionClass($job);
            $prop = $ref->getProperty('ticketId');
            $prop->setAccessible(true);
            $dispatchedIds[] = $prop->getValue($job);

            return true;
        });

        $this->assertContains($tickets[2]->id, $dispatchedIds);
    }

    public function test_budget_exhausted_stops_early(): void
    {
        Bus::fake();
        $tickets = $this->closedTicketsWithResolutions(3);

        // Exhaust the budget by creating a run that consumed all tokens
        Setting::setValue('wiki_daily_token_limit', '100');
        WikiRun::create([
            'run_type' => WikiRunType::MineTicket->value,
            'subject_type' => 'ticket',
            'subject_id' => $tickets[0]->id,
            'source_content_hash' => 'some-hash',
            'status' => WikiRunStatus::Completed->value,
            'triggered_by' => 'auto',
            'ai_tokens_used' => ['input' => 60, 'output' => 60], // 120 > 100 limit
        ]);

        $dispatched = app(WikiBackfillService::class)->execute(null, 10);

        $this->assertSame(0, $dispatched);
        Bus::assertNotDispatched(MineTicketKnowledge::class);
    }

    public function test_auto_mine_off_dispatches_zero_and_warns(): void
    {
        Bus::fake();
        Setting::setValue('wiki_auto_mine', '0');
        $this->closedTicketsWithResolutions(3);

        $dispatched = app(WikiBackfillService::class)->execute(null, 10);

        $this->assertSame(0, $dispatched);
        Bus::assertNotDispatched(MineTicketKnowledge::class);
    }

    public function test_auto_mine_off_shown_in_plan(): void
    {
        Setting::setValue('wiki_auto_mine', '0');
        $this->closedTicketsWithResolutions(2);

        $plan = app(WikiBackfillService::class)->plan(null, 25);

        $this->assertFalse($plan['auto_mine_on']);
    }

    public function test_client_filter_limits_candidates(): void
    {
        Bus::fake();
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();

        $this->closedTicketsWithResolutions(3, $clientA);
        $this->closedTicketsWithResolutions(2, $clientB);

        $dispatched = app(WikiBackfillService::class)->execute($clientA->id, 10);

        $this->assertSame(3, $dispatched);
        Bus::assertDispatchedTimes(MineTicketKnowledge::class, 3);
    }

    public function test_estimate_uses_recent_mine_run_average(): void
    {
        // Create 2 unmined tickets first so we know their IDs
        $tickets = $this->closedTicketsWithResolutions(2);

        // Seed 3 completed mine_ticket runs for DIFFERENT (non-existent) ticket IDs
        // so they don't interfere with the candidates() query while still providing
        // perTicketEstimate() history via WikiRun::where('run_type', MineTicket).
        $fakeTicketIdBase = 999900;
        foreach (range(1, 3) as $i) {
            WikiRun::create([
                'run_type' => WikiRunType::MineTicket->value,
                'subject_type' => 'ticket',
                'subject_id' => $fakeTicketIdBase + $i,
                'source_content_hash' => "avg-history-hash-{$i}",
                'status' => WikiRunStatus::Completed->value,
                'triggered_by' => 'auto',
                'ai_tokens_used' => ['input' => 8000, 'output' => 4000], // 12000 each
            ]);
        }

        $plan = app(WikiBackfillService::class)->plan(null, 25);

        // 2 unmined tickets × 12000 avg tokens each = 24000, capped at default 500000
        $this->assertSame(2, $plan['ticket_count']);
        $this->assertSame(24_000, $plan['estimated_tokens']);
    }

    public function test_estimate_falls_back_to_nominal_when_no_history(): void
    {
        // No prior mine runs
        $this->closedTicketsWithResolutions(1);

        $plan = app(WikiBackfillService::class)->plan(null, 25);

        // Nominal fallback is 12000 per ticket
        $this->assertSame(12_000, $plan['estimated_tokens']);
    }

    public function test_open_tickets_not_included_in_candidates(): void
    {
        Bus::fake();
        $client = Client::factory()->create();

        // Create one open (non-closed) ticket with a resolution — should not be picked up
        Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => TicketStatus::InProgress->value,
            'resolution' => 'Some resolution text',
            'closed_at' => null,
        ]);

        // One properly closed ticket
        Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => TicketStatus::Closed->value,
            'resolution' => 'Closed resolution',
            'closed_at' => now()->subDay(),
        ]);

        $dispatched = app(WikiBackfillService::class)->execute(null, 10);

        // Only the closed ticket
        $this->assertSame(1, $dispatched);
    }

    public function test_tickets_without_resolution_excluded(): void
    {
        Bus::fake();

        // Ticket with no resolution — should be skipped
        Ticket::factory()->create([
            'status' => TicketStatus::Closed->value,
            'resolution' => null,
            'closed_at' => now()->subDay(),
        ]);

        $dispatched = app(WikiBackfillService::class)->execute(null, 10);

        $this->assertSame(0, $dispatched);
        Bus::assertNotDispatched(MineTicketKnowledge::class);
    }
}
