<?php

namespace Tests\Feature\Prospect;

use App\Enums\TicketStatus;
use App\Jobs\GenerateTicketResolution;
use App\Jobs\MineTicketKnowledge;
use App\Jobs\RunTriagePipeline;
use App\Jobs\SendTicketNotification;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WikiRun;
use App\Services\TicketResolutionDrafter;
use App\Services\Triage\TriagePipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * AI-pipeline gate: prospect tickets must NEVER reach the LLM paths.
 *
 * Four paths gated:
 *   1. RunTriagePipeline — dispatched in TicketObserver::created
 *   2. notifyTicketCreated (SendTicketNotification) — also in TicketObserver::created
 *   3. MineTicketKnowledge — dispatched in TicketObserver::updated (terminal+resolution)
 *   4. buildTicketSuggestions — CallController pre-fill (gated defensively; integration
 *      point for Task 6/7 — see CallController::buildTicketSuggestions guard)
 */
class ProspectAiGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function enableAutoTriage(): void
    {
        Setting::setValue('triage_enabled', '1');
        Setting::setValue('triage_auto_new_tickets', '1');
    }

    private function enableAutoMine(): void
    {
        Setting::setValue('wiki_enabled', '1');
        Setting::setValue('wiki_auto_mine', '1');
    }

    // ── Path 1 + 2: TicketObserver::created ──────────────────────────────────

    public function test_a_prospect_ticket_does_not_dispatch_triage(): void
    {
        $this->enableAutoTriage();

        $prospect = Client::factory()->prospect()->create();
        Ticket::factory()->create(['client_id' => $prospect->id]);

        Bus::assertNotDispatched(RunTriagePipeline::class);
    }

    public function test_an_active_client_ticket_still_dispatches_triage(): void
    {
        $this->enableAutoTriage();

        $client = Client::factory()->create(); // stage defaults to Active
        Ticket::factory()->create(['client_id' => $client->id]);

        Bus::assertDispatched(RunTriagePipeline::class);
    }

    public function test_notify_ticket_created_is_not_dispatched_for_a_prospect_ticket(): void
    {
        // Ensure there is at least one user who would receive the notification
        // so the gate is exercised (not a vacuous pass from "no recipients").
        $tech = User::factory()->create();

        $prospect = Client::factory()->prospect()->create();
        Ticket::factory()->create([
            'client_id' => $prospect->id,
            'created_by' => null, // not the tech, so notification would fire
        ]);

        Bus::assertNotDispatched(SendTicketNotification::class);
    }

    public function test_notify_ticket_created_is_dispatched_for_an_active_client_ticket(): void
    {
        // Active-client control: notification MUST still fire for normal tickets.
        $tech = User::factory()->create();

        $client = Client::factory()->create();
        Ticket::factory()->create([
            'client_id' => $client->id,
            'created_by' => null,
        ]);

        Bus::assertDispatched(SendTicketNotification::class);
    }

    // ── Path 3: TicketObserver::updated (MineTicketKnowledge) ────────────────

    public function test_a_resolved_prospect_ticket_does_not_dispatch_mine_ticket_knowledge(): void
    {
        $this->enableAutoMine();

        $prospect = Client::factory()->prospect()->create();

        $ticket = Ticket::factory()->create([
            'client_id' => $prospect->id,
            'status' => TicketStatus::InProgress,
            'resolution' => null,
        ]);

        $ticket->update([
            'status' => TicketStatus::Resolved,
            'resolution' => 'Resolved the prospect issue.',
        ]);

        Bus::assertNotDispatched(MineTicketKnowledge::class);
    }

    public function test_a_resolved_active_client_ticket_still_dispatches_mine_ticket_knowledge(): void
    {
        $this->enableAutoMine();

        $client = Client::factory()->create();

        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => TicketStatus::InProgress,
            'resolution' => null,
        ]);

        $ticket->update([
            'status' => TicketStatus::Resolved,
            'resolution' => 'Fixed the issue by restarting the service.',
        ]);

        Bus::assertDispatched(MineTicketKnowledge::class);
    }

    // ── Choke-point regression: job-level guards (C1 / C2 / M1) ─────────────
    // These prove the guard lives INSIDE the job, not only at dispatch sites.
    // Even if a sibling dispatcher (TicketController, triage:review-open command,
    // WikiBackfillService) pushes the job for a prospect ticket, the job returns
    // early before touching any LLM path.

    /**
     * C1 — RunTriagePipeline::handle must early-out for prospect clients.
     *
     * Simulates: TicketController::triggerTriage / triggerReview / bulkAction or the
     * triage:review-open command dispatching the job for a prospect ticket.
     * TriagePipeline::run must never be called.
     */
    public function test_run_triage_pipeline_job_skips_prospect_client(): void
    {
        $this->enableAutoTriage();

        $prospect = Client::factory()->prospect()->create();
        $ticket = Ticket::factory()->create(['client_id' => $prospect->id]);

        $pipeline = $this->createMock(TriagePipeline::class);
        $pipeline->expects($this->never())->method('run');

        $job = new RunTriagePipeline($ticket->id, 'triage');
        $job->handle($pipeline);
    }

    /**
     * C1 control — RunTriagePipeline::handle must still call pipeline for active clients.
     *
     * Uses a mock that expects run() once so an active-client job is not silently swallowed.
     */
    public function test_run_triage_pipeline_job_runs_for_active_client(): void
    {
        $this->enableAutoTriage();

        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        $pipeline = $this->createMock(TriagePipeline::class);
        $pipeline->expects($this->once())->method('run');

        Bus::fake(); // suppress observer-fired jobs from handle()'s save()

        $job = new RunTriagePipeline($ticket->id, 'triage');
        $job->handle($pipeline);
    }

    /**
     * C2 — MineTicketKnowledge::handle must early-out for prospect clients.
     *
     * Simulates: WikiBackfillService dispatching the job for a prospect ticket
     * that already has a resolution. No WikiRun row must be created.
     * Calls handle() directly — Bus::fake() in setUp intercepts dispatchSync.
     */
    public function test_mine_ticket_knowledge_job_skips_prospect_client(): void
    {
        $this->enableAutoMine();

        $prospect = Client::factory()->prospect()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $prospect->id,
            'status' => TicketStatus::Resolved,
            'resolution' => 'Prospect issue resolved.',
        ]);

        $job = new MineTicketKnowledge($ticket->id, 'backfill');
        app()->call([$job, 'handle']);

        $this->assertSame(0, WikiRun::count());
    }

    /**
     * C2 control — MineTicketKnowledge::handle proceeds past the prospect gate for active clients.
     *
     * Calls handle() directly (Bus::fake() in setUp intercepts dispatchSync, so we bypass it).
     * Verifies the prospect gate does NOT stop an active-client ticket: WikiRun is opened.
     * Uses a mocked AiClient that returns zero facts so no real LLM calls are made.
     */
    public function test_mine_ticket_knowledge_job_runs_for_active_client(): void
    {
        $this->enableAutoMine();

        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => TicketStatus::Resolved,
            'resolution' => 'Fixed the network switch.',
        ]);

        $mock = $this->mock(\App\Services\Ai\AiClient::class);
        $mock->shouldReceive('completeJson')->andReturn(['facts' => []]);
        $mock->shouldReceive('cumulativeInputTokens')->andReturn(100);
        $mock->shouldReceive('cumulativeOutputTokens')->andReturn(50);
        $mock->shouldReceive('cumulativeTotalTokens')->andReturn(150);

        // Call handle() directly — Bus::fake() in setUp intercepts dispatchSync so we
        // exercise the job logic without queue infrastructure.
        $job = new MineTicketKnowledge($ticket->id, 'backfill');
        app()->call([$job, 'handle']);

        $this->assertSame(1, WikiRun::count());
    }

    /**
     * M1 — GenerateTicketResolution::handle must early-out for prospect clients.
     *
     * A terminal prospect ticket with no resolution must NOT call TicketResolutionDrafter::draft.
     */
    public function test_generate_ticket_resolution_job_skips_prospect_client(): void
    {
        $this->enableAutoMine();

        $prospect = Client::factory()->prospect()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $prospect->id,
            'status' => TicketStatus::Resolved,
            'resolution' => null,
        ]);

        $drafter = $this->createMock(TicketResolutionDrafter::class);
        $drafter->expects($this->never())->method('draft');

        $job = new GenerateTicketResolution($ticket->id);
        $job->handle($drafter);

        $ticket->refresh();
        $this->assertNull($ticket->resolution);
    }

    /**
     * M1 control — GenerateTicketResolution::handle runs for active clients.
     */
    public function test_generate_ticket_resolution_job_runs_for_active_client(): void
    {
        $this->enableAutoMine();

        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => TicketStatus::Resolved,
            'resolution' => null,
        ]);

        $drafter = $this->createMock(TicketResolutionDrafter::class);
        $drafter->expects($this->once())
            ->method('draft')
            ->willReturn('Replaced the failed drive.');

        Bus::fake(); // suppress observer re-fire

        $job = new GenerateTicketResolution($ticket->id);
        $job->handle($drafter);

        $ticket->refresh();
        $this->assertSame('Replaced the failed drive.', $ticket->resolution);
    }
}
