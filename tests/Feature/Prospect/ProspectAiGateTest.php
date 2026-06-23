<?php

namespace Tests\Feature\Prospect;

use App\Enums\TicketStatus;
use App\Jobs\MineTicketKnowledge;
use App\Jobs\RunTriagePipeline;
use App\Jobs\SendTicketNotification;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
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
}
