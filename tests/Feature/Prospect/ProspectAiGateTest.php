<?php

namespace Tests\Feature\Prospect;

use App\Enums\TicketStatus;
use App\Jobs\GenerateTicketResolution;
use App\Jobs\MineTicketKnowledge;
use App\Jobs\RunTriagePipeline;
use App\Jobs\SendTicketNotification;
use App\Models\AssistantConversation;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Models\WikiRun;
use App\Services\Assistant\AssistantService;
use App\Services\Assistant\AssistantToolDefinitions;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\ReplyDraftService;
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

    public function test_manual_triage_endpoint_rejects_prospect_ticket_without_dispatch(): void
    {
        $this->enableAutoTriage();

        $user = User::factory()->create();
        $prospect = Client::factory()->prospect()->create();
        $ticket = Ticket::factory()->create(['client_id' => $prospect->id]);
        Bus::fake();

        $response = $this->actingAs($user)->post(route('tickets.triage', $ticket));

        $response->assertRedirect(route('tickets.show', $ticket));
        $response->assertSessionHas('error', 'AI Triage is unavailable for prospect tickets.');
        Bus::assertNotDispatched(RunTriagePipeline::class);
    }

    public function test_manual_review_endpoint_rejects_prospect_ticket_without_dispatch(): void
    {
        $this->enableAutoTriage();

        $user = User::factory()->create();
        $prospect = Client::factory()->prospect()->create();
        $ticket = Ticket::factory()->create(['client_id' => $prospect->id]);
        Bus::fake();

        $response = $this->actingAs($user)->post(route('tickets.review', $ticket));

        $response->assertRedirect(route('tickets.show', $ticket));
        $response->assertSessionHas('error', 'AI Review is unavailable for prospect tickets.');
        Bus::assertNotDispatched(RunTriagePipeline::class);
    }

    public function test_bulk_triage_skips_prospect_tickets_and_dispatches_active_tickets_only(): void
    {
        $this->enableAutoTriage();

        $user = User::factory()->create();
        $active = Client::factory()->create();
        $prospect = Client::factory()->prospect()->create();
        $activeTicket = Ticket::factory()->create(['client_id' => $active->id]);
        $prospectTicket = Ticket::factory()->create(['client_id' => $prospect->id]);
        Bus::fake();

        $response = $this->actingAs($user)->post(route('tickets.bulk-action'), [
            'action' => 'triage',
            'ticket_ids' => [$activeTicket->id, $prospectTicket->id],
        ]);

        $response->assertRedirect(route('tickets.index'));
        $response->assertSessionHas('success', 'AI Triage queued for 1 ticket(s); 1 prospect ticket(s) skipped.');
        Bus::assertDispatchedTimes(RunTriagePipeline::class, 1);
        Bus::assertDispatched(
            RunTriagePipeline::class,
            fn ($job) => (fn () => $this->ticketId)->call($job) === $activeTicket->id,
        );
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

    // ── Manual AI surfaces: endpoints + ticket page controls ────────────────

    public function test_manual_draft_reply_endpoint_rejects_prospect_ticket(): void
    {
        config(['services.ai.api_key' => 'sk-test-key']);

        $user = User::factory()->create();
        $prospect = Client::factory()->prospect()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $prospect->id,
            'status' => TicketStatus::InProgress,
        ]);

        $this->mock(ReplyDraftService::class)
            ->shouldNotReceive('generateDraft');

        $response = $this->actingAs($user)
            ->postJson(route('tickets.draft-reply', $ticket), [
                'instructions' => 'Be concise.',
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'error' => 'AI assistance is unavailable for prospect tickets.',
        ]);
    }

    public function test_manual_draft_resolution_endpoint_rejects_prospect_ticket(): void
    {
        config(['services.ai.api_key' => 'sk-test-key']);

        $user = User::factory()->create();
        $prospect = Client::factory()->prospect()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $prospect->id,
            'status' => TicketStatus::InProgress,
        ]);

        $this->mock(TicketResolutionDrafter::class)
            ->shouldNotReceive('draft');

        $response = $this->actingAs($user)
            ->postJson(route('tickets.draft-resolution', $ticket));

        $response->assertStatus(422);
        $response->assertJson([
            'error' => 'AI assistance is unavailable for prospect tickets.',
        ]);
    }

    public function test_assistant_conversation_creation_rejects_prospect_ticket_context(): void
    {
        $user = User::factory()->create();
        $prospect = Client::factory()->prospect()->create();
        $ticket = Ticket::factory()->create(['client_id' => $prospect->id]);

        $response = $this->actingAs($user)
            ->postJson(route('assistant.create'), [
                'context_type' => 'ticket',
                'context_id' => $ticket->id,
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'error' => 'AI assistance is unavailable for prospect tickets.',
        ]);
        $this->assertDatabaseMissing('assistant_conversations', [
            'context_type' => 'ticket',
            'context_id' => $ticket->id,
        ]);
    }

    public function test_assistant_message_send_rejects_existing_prospect_ticket_conversation_before_persisting_message(): void
    {
        $user = User::factory()->create();
        $prospect = Client::factory()->prospect()->create();
        $ticket = Ticket::factory()->create(['client_id' => $prospect->id]);
        $conversation = AssistantConversation::create([
            'user_id' => $user->id,
            'context_type' => 'ticket',
            'context_id' => $ticket->id,
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('assistant.message', $conversation), [
                'message' => 'Please investigate this ticket.',
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'error' => 'AI assistance is unavailable for prospect tickets.',
        ]);
        $this->assertDatabaseMissing('assistant_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Please investigate this ticket.',
        ]);
    }

    public function test_assistant_conversation_creation_rejects_prospect_client_context(): void
    {
        $user = User::factory()->create();
        $prospect = Client::factory()->prospect()->create();

        $response = $this->actingAs($user)
            ->postJson(route('assistant.create'), [
                'context_type' => 'client',
                'context_id' => $prospect->id,
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'error' => 'AI assistance is unavailable for prospect clients.',
        ]);
        $this->assertDatabaseMissing('assistant_conversations', [
            'context_type' => 'client',
            'context_id' => $prospect->id,
        ]);
    }

    public function test_assistant_message_send_rejects_existing_prospect_client_conversation_before_persisting_message(): void
    {
        $user = User::factory()->create();
        $prospect = Client::factory()->prospect()->create();
        $conversation = AssistantConversation::create([
            'user_id' => $user->id,
            'context_type' => 'client',
            'context_id' => $prospect->id,
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('assistant.message', $conversation), [
                'message' => 'Summarize this prospect.',
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'error' => 'AI assistance is unavailable for prospect clients.',
        ]);
        $this->assertDatabaseMissing('assistant_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Summarize this prospect.',
        ]);
    }

    public function test_assistant_save_note_rejects_prospect_ticket_and_creates_no_note(): void
    {
        $user = User::factory()->create();
        $prospect = Client::factory()->prospect()->create();
        $ticket = Ticket::factory()->create(['client_id' => $prospect->id]);
        $conversation = AssistantConversation::create([
            'user_id' => $user->id,
            'context_type' => 'ticket',
            'context_id' => $ticket->id,
        ]);
        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'AI output that must not become a note.',
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('assistant.save-note', $conversation), [
                'message_id' => $message->id,
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'error' => 'AI assistance is unavailable for prospect tickets.',
        ]);
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count());
    }

    public function test_assistant_service_save_as_note_rejects_prospect_ticket_directly(): void
    {
        $user = User::factory()->create();
        $prospect = Client::factory()->prospect()->create();
        $ticket = Ticket::factory()->create(['client_id' => $prospect->id]);
        $conversation = AssistantConversation::create([
            'user_id' => $user->id,
            'context_type' => 'ticket',
            'context_id' => $ticket->id,
        ]);
        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'AI output that must not become a note.',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI assistance is unavailable for prospect tickets.');

        try {
            app(AssistantService::class)->saveAsNote($message, $ticket, $user->id);
        } finally {
            $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count());
        }
    }

    public function test_assistant_tool_executor_rejects_prospect_ticket_reads_and_writes(): void
    {
        $user = User::factory()->create();
        $prospect = Client::factory()->prospect()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $prospect->id,
            'status' => TicketStatus::InProgress,
            'subject' => 'Prospect-only tool surface ticket',
        ]);
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_name' => 'Tester',
            'body' => 'Prospect note body',
            'noted_at' => now(),
        ]);

        $general = new AssistantToolExecutor(userId: $user->id);
        $clientScoped = new AssistantToolExecutor(clientId: $prospect->id, userId: $user->id);

        $this->assertSame(
            ['error' => 'AI assistance is unavailable for prospect tickets.'],
            $general->execute('get_ticket_detail', ['ticket_id' => $ticket->id]),
        );
        $this->assertSame(
            ['error' => 'AI assistance is unavailable for prospect tickets.'],
            $general->execute('get_ticket_calls', ['ticket_id' => $ticket->id]),
        );
        $this->assertSame(
            ['error' => 'AI assistance is unavailable for prospect clients.'],
            $clientScoped->execute('search_tickets', ['query' => 'tool surface']),
        );
        $this->assertSame(
            ['error' => 'AI assistance is unavailable for prospect clients.'],
            $clientScoped->execute('get_ticket_notes', ['ticket_id' => $ticket->id]),
        );
        $this->assertSame(
            ['error' => 'AI assistance is unavailable for prospect clients.'],
            $clientScoped->execute('create_ticket', [
                'subject' => 'AI-created prospect ticket',
                'description' => 'Must be rejected.',
            ]),
        );
        $this->assertSame(
            ['error' => 'AI assistance is unavailable for prospect clients.'],
            $clientScoped->execute('add_ticket_note', [
                'ticket_id' => $ticket->id,
                'body' => 'Must be rejected.',
            ]),
        );
        $this->assertSame(1, TicketNote::where('ticket_id', $ticket->id)->count());
    }

    public function test_assistant_cross_ticket_tools_exclude_prospect_tickets_and_clients(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['name' => 'Operational Tool Client']);
        $prospect = Client::factory()->prospect()->create(['name' => 'Prospect Tool Client']);
        $activeTicket = Ticket::factory()->create([
            'client_id' => $client->id,
            'assignee_id' => $user->id,
            'status' => TicketStatus::InProgress,
            'subject' => 'Shared tool keyword active',
            'opened_at' => now()->subHour(),
        ]);
        $prospectTicket = Ticket::factory()->create([
            'client_id' => $prospect->id,
            'assignee_id' => $user->id,
            'status' => TicketStatus::InProgress,
            'subject' => 'Shared tool keyword prospect',
            'opened_at' => now()->subHours(2),
        ]);

        $executor = new AssistantToolExecutor(userId: $user->id);

        $searchIds = collect($executor->execute('search_all_tickets', ['query' => 'Shared tool keyword']))
            ->pluck('id');
        $myIds = collect($executor->execute('list_my_tickets', []))->pluck('id');
        $openIds = collect($executor->execute('list_open_tickets', []))->pluck('id');
        $clientIds = collect($executor->execute('find_clients', ['query' => 'Tool Client'])['clients'])
            ->pluck('id');
        $queueStats = $executor->execute('get_queue_stats', []);

        $this->assertTrue($searchIds->contains($activeTicket->id));
        $this->assertFalse($searchIds->contains($prospectTicket->id));
        $this->assertTrue($myIds->contains($activeTicket->id));
        $this->assertFalse($myIds->contains($prospectTicket->id));
        $this->assertTrue($openIds->contains($activeTicket->id));
        $this->assertFalse($openIds->contains($prospectTicket->id));
        $this->assertTrue($clientIds->contains($client->id));
        $this->assertFalse($clientIds->contains($prospect->id));
        $this->assertSame(1, $queueStats['total_open']);
        $this->assertSame($activeTicket->id, $queueStats['oldest_ticket']['id']);
    }

    public function test_ticket_show_hides_manual_ai_controls_for_prospect_ticket(): void
    {
        config(['services.ai.api_key' => 'sk-test-key']);

        $user = User::factory()->create();
        $prospect = Client::factory()->prospect()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $prospect->id,
            'status' => TicketStatus::InProgress,
        ]);
        $conversation = AssistantConversation::create([
            'user_id' => $user->id,
            'context_type' => 'ticket',
            'context_id' => $ticket->id,
        ]);
        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Previous AI question.',
        ]);

        $response = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $response->assertOk();
        $response->assertDontSee('id="askAiBtn"', false);
        $response->assertDontSee('id="draftReplyBtn"', false);
        $response->assertDontSee('id="draftResolutionBtn"', false);
        $response->assertDontSee('id="ai-chat-input-'.$conversation->id.'"', false);
    }

    public function test_ticket_show_hides_triage_controls_for_prospect_ticket(): void
    {
        $this->enableAutoTriage();

        $prospect = Client::factory()->prospect()->create();
        $ticket = Ticket::factory()->create(['client_id' => $prospect->id]);

        $response = $this->actingAs(User::factory()->create())->get(route('tickets.show', $ticket));

        $response->assertOk();
        $response->assertDontSee(route('tickets.triage', $ticket), false);
        $response->assertDontSee(route('tickets.review', $ticket), false);
    }

    public function test_assistant_ticket_conversation_listing_marks_prospect_ticket_conversations_inactive(): void
    {
        $user = User::factory()->create();
        $prospect = Client::factory()->prospect()->create();
        $ticket = Ticket::factory()->create(['client_id' => $prospect->id]);
        $conversation = AssistantConversation::create([
            'user_id' => $user->id,
            'context_type' => 'ticket',
            'context_id' => $ticket->id,
        ]);
        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Previous AI question.',
        ]);

        $response = $this->actingAs($user)->getJson(route('assistant.for-ticket', $ticket));

        $response->assertOk();
        $this->assertFalse($response->json('0.is_active'));
    }

    public function test_ticket_show_still_shows_manual_ai_controls_for_active_client_ticket(): void
    {
        config(['services.ai.api_key' => 'sk-test-key']);

        $user = User::factory()->create();
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => TicketStatus::InProgress,
        ]);

        $response = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $response->assertOk();
        $response->assertSee('id="askAiBtn"', false);
        $response->assertSee('id="draftReplyBtn"', false);
        $response->assertSee('id="draftResolutionBtn"', false);
    }

    public function test_assistant_tool_status_schema_uses_pending_third_party_status(): void
    {
        $tools = collect(AssistantToolDefinitions::getTools(false));

        foreach (['search_all_tickets', 'list_my_tickets'] as $toolName) {
            $status = $tools->firstWhere('name', $toolName)['input_schema']['properties']['status'];

            $this->assertContains('pending_third_party', $status['enum']);
            $this->assertStringContainsString('pending_third_party', $status['description']);
            $this->assertNotContains('pending_vendor', $status['enum']);
            $this->assertStringNotContainsString('pending_vendor', $status['description']);
        }
    }
}
