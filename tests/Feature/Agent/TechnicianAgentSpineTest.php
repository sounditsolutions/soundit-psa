<?php

namespace Tests\Feature\Agent;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Jobs\RunTechnicianAgent;
use App\Jobs\SendPortalNotification;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\SignificanceGate;
use App\Services\Agent\TechnicianAgent;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiResponse;
use App\Services\Technician\Cockpit\CockpitQuery;
use App\Services\Technician\Notify\OperatorNotifier;
use App\Services\Technician\TechnicianApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * End-to-end spine proof — Task 10.
 *
 * Wires Tasks 1–9 together and proves the full integration chain:
 *   wake → SignificanceGate → TechnicianAgent → ProposeCloseTool → gate →
 *   held (default) or auto (opt-in) → cockpit surfacing → approveClose → close.
 *
 * Mocking strategy:
 *   - SignificanceGate: mocked at the class level (its production path uses
 *     new AiClient(...) inside, bypassing the container).
 *   - AiClient: mocked via the container (TechnicianAgent ctor-injects it);
 *     andReturnUsing drives the tool loop deterministically by calling the
 *     executor closure with the desired tool + args.
 *   - OperatorNotifier: mocked for auto-band tests (has external side effects).
 *   All other production code (gate, ProposeCloseTool, CockpitQuery,
 *   TechnicianApprovalService, TicketService) runs unmodified.
 *
 * Tests (5):
 *  1. Full spine: held → cockpit surface → approve → silent close (no client notify).
 *  2. Dormant: agent disabled → gate and agent never called, no run created.
 *  3. Auto band: threshold met + eligible ticket → auto-Closed + operator notified + no client notify.
 *  4. Held band: confidence below threshold → held, ticket not closed.
 *  5. No-retro-close (CO-25): existing held run survives threshold being turned on.
 */
class TechnicianAgentSpineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Tier-1 (psa-2f0bg): selectively fake ONLY the staged-action notification job.
        // These tests' subject is the agent's own synchronous behaviour; the observer's
        // async notify on AwaitingApproval is a separate concern with its own test
        // (StagedActionNotificationTest). Faking just this job leaves all other jobs live.
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\NotifyStagedActionAwaitingApproval::class]);
        // First user = AI actor (TechnicianConfig::aiActorUserId() falls back to first user).
        User::factory()->create();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function enableAgent(): void
    {
        Setting::setValue('agent_enabled', '1');
    }

    private function configureAi(): void
    {
        // AiConfig::isConfigured() checks the encrypted ai_api_key setting.
        Setting::setEncrypted('ai_api_key', 'test-key');
    }

    private function setAutoThreshold(float $threshold): void
    {
        Setting::setValue('propose_close_auto_threshold', (string) $threshold);
    }

    private function fakeAiResponse(): AiResponse
    {
        return new AiResponse(text: '', inputTokens: 0, outputTokens: 0, stopReason: 'end_turn');
    }

    /**
     * Open InProgress ticket on an operational client (stage=Active, is_active=true).
     * Suitable for held-band tests — InProgress is open but not auto-safe.
     */
    private function openInProgressTicket(): Ticket
    {
        $client = Client::factory()->create(); // defaults: stage=Active, is_active=true

        return Ticket::factory()->for($client)->create(['status' => TicketStatus::InProgress]);
    }

    /**
     * Open PendingClient ticket on an operational client — auto-eligible because:
     *   - isOpen()=true (so RunTechnicianAgent guard #4 passes)
     *   - In CloseAutoEligibility::AUTO_SAFE_STATUSES (so auto backstop passes)
     *   - No recent EndUser note
     * Suitable for auto-band tests.
     */
    private function openAutoEligibleTicket(): Ticket
    {
        $client = Client::factory()->create();

        return Ticket::factory()->for($client)->create(['status' => TicketStatus::PendingClient]);
    }

    /**
     * Build a mock that drives the TechnicianAgent's tool loop deterministically.
     * The executor closure is captured from runToolLoop's 4th parameter and
     * immediately invoked with the supplied tool + input, simulating the LLM
     * calling that tool in its first round.
     *
     * Also overrides the AppServiceProvider binding so handle() → app(TechnicianAgent::class)
     * resolves a mock-injected instance rather than the Opus-real AiClient from the
     * production binding. Direct binding override mirrors the comment in AppServiceProvider.
     */
    private function mockAiClientProposesClose(float $confidence, string $reason = 'No client response in 30 days.'): void
    {
        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('runToolLoop')
            ->once()
            ->andReturnUsing(function ($system, $user, $tools, $executor) use ($confidence, $reason): AiResponse {
                $executor('propose_close', ['reason' => $reason, 'confidence' => $confidence]);

                return $this->fakeAiResponse();
            });

        // Override the production binding so RunTechnicianAgent::handle() resolves
        // a mock-injected TechnicianAgent, not one with a real Opus AiClient.
        $this->app->bind(TechnicianAgent::class, fn () => new TechnicianAgent($ai));
    }

    // ── 1. Full spine: held → cockpit surface → approve → silent close ────────

    /**
     * The happy path, end to end:
     *  wake → gate (mock true) → agent (mock loops propose_close) → held run created
     *  → cockpit surfaces it → operator approveClose → ticket Closed, run Done,
     *  executed audit row, and NO SendPortalNotification even with a portal contact.
     */
    public function test_full_spine_held_then_approve_then_silent_close(): void
    {
        Queue::fake(); // captures all queued jobs

        $this->enableAgent();
        $this->configureAi();

        // Build a ticket with a portal-enabled contact so the no-client-notify
        // assertion has teeth (if code used Resolved instead of Closed, it would fire).
        $client = Client::factory()->create();
        $contact = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Portal',
            'last_name' => 'User',
            'email' => 'portal@example.com',
            'is_active' => true,
            'portal_enabled' => true,
        ]);
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'contact_id' => $contact->id,
            'status' => TicketStatus::InProgress,
        ]);

        // No auto threshold → gate holds; notifier must NOT fire.
        $notifier = $this->mock(OperatorNotifier::class);
        $notifier->shouldReceive('notify')->never();

        // SignificanceGate: mock the gate itself (its prod path uses new AiClient(...)).
        $gate = $this->mock(SignificanceGate::class);
        // RunTechnicianAgent re-fetches the ticket via Ticket::find(), so the object
        // passed to assess() is a different instance — match by type, not identity.
        $gate->shouldReceive('assess')->once()->andReturn(true);

        // AiClient: mock drives the agent loop — simulate model calling propose_close.
        $this->mockAiClientProposesClose(confidence: 0.97);

        // ── Wake ──
        (new RunTechnicianAgent($ticket->id))->handle();

        // Assert a held run was created.
        $run = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'propose_close')
            ->first();
        $this->assertNotNull($run, 'A TechnicianRun(propose_close) must be created after the wake.');
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        // Ticket must NOT be closed (no auto threshold set).
        $this->assertNotSame(TicketStatus::Closed, $ticket->fresh()->status);

        // Cockpit surfacing (CO-10): the held run must appear in pendingDrafts().
        $pendingIds = app(CockpitQuery::class)->pendingDrafts()->pluck('id');
        $this->assertTrue(
            $pendingIds->contains($run->id),
            "The held run (id={$run->id}) must appear in CockpitQuery::pendingDrafts().",
        );

        // ── Approve ──
        $approver = User::factory()->create();
        $result = app(TechnicianApprovalService::class)->approveClose($run->fresh(), $approver->id);

        $this->assertSame('closed', $result->status);
        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);

        // Executed audit row must exist.
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'propose_close',
            'result_status' => 'executed',
            'ticket_id' => $ticket->id,
        ]);

        // CRITICAL (CO-18): closing to Closed must NOT dispatch a client portal notification.
        Queue::assertNotPushed(SendPortalNotification::class);
    }

    // ── 2. Dormant ────────────────────────────────────────────────────────────

    /**
     * When agent_enabled is absent, handle() must no-op immediately.
     * Neither the SignificanceGate nor the TechnicianAgent may be called,
     * and no TechnicianRun must be created.
     */
    public function test_dormant_agent_does_not_call_gate_or_agent(): void
    {
        // agent_enabled absent → AgentConfig::enabled() = false (dormant by default).
        $ticket = $this->openInProgressTicket();

        $gate = $this->mock(SignificanceGate::class);
        $gate->shouldReceive('assess')->never();

        $agent = $this->mock(TechnicianAgent::class);
        $agent->shouldReceive('run')->never();

        (new RunTechnicianAgent($ticket->id))->handle();

        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->count());
    }

    // ── 3. Auto band ──────────────────────────────────────────────────────────

    /**
     * Full autonomous path: threshold set, eligible ticket, confidence clears it →
     * ticket auto-Closed in a single wake, operator notified, no client notification.
     */
    public function test_auto_band_closes_ticket_and_notifies_operator_without_client_notify(): void
    {
        Queue::fake();

        $this->enableAgent();
        $this->configureAi();
        $this->setAutoThreshold(0.95);

        // Fix-5: attach a portal-enabled contact so the Queue::assertNotPushed assertion
        // has teeth — without a contact it passes vacuously (no notification possible anyway).
        $client = Client::factory()->create();
        $contact = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Portal',
            'last_name' => 'User',
            'email' => 'portal@example.com',
            'is_active' => true,
            'portal_enabled' => true,
        ]);
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'contact_id' => $contact->id,
            'status' => TicketStatus::PendingClient, // no recent EndUser note
        ]);

        // OperatorNotifier must be called exactly once (CO-21: post-execute notify).
        $notifier = $this->mock(OperatorNotifier::class);
        $notifier->shouldReceive('notify')
            ->once()
            ->withArgs(fn (string $subject, string $body): bool => str_contains($subject, (string) $ticket->id)
                && str_contains($body, (string) $ticket->id));

        $gate = $this->mock(SignificanceGate::class);
        $gate->shouldReceive('assess')->once()->andReturn(true);

        // Confidence 0.97 ≥ threshold 0.95 AND eligible ticket → Auto tier.
        $this->mockAiClientProposesClose(confidence: 0.97);

        (new RunTechnicianAgent($ticket->id))->handle();

        // Ticket must be auto-Closed.
        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);

        // Run must be Done.
        $run = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'propose_close')
            ->first();
        $this->assertNotNull($run);
        $this->assertSame(TechnicianRunState::Done, $run->state);

        // Executed audit row must exist.
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'propose_close',
            'result_status' => 'executed',
            'ticket_id' => $ticket->id,
        ]);

        // CO-18: no client portal notification dispatched.
        Queue::assertNotPushed(SendPortalNotification::class);
    }

    // ── 4. Held band ──────────────────────────────────────────────────────────

    /**
     * Confidence below threshold → Approve tier → held, ticket untouched.
     * (Same setup as auto band but confidence 0.80 < threshold 0.95.)
     */
    public function test_held_band_creates_awaiting_approval_run_and_does_not_close_ticket(): void
    {
        $this->enableAgent();
        $this->configureAi();
        $this->setAutoThreshold(0.95);

        $ticket = $this->openAutoEligibleTicket();

        // Notifier must NOT fire — proposal is held, not executed.
        $notifier = $this->mock(OperatorNotifier::class);
        $notifier->shouldReceive('notify')->never();

        $gate = $this->mock(SignificanceGate::class);
        $gate->shouldReceive('assess')->once()->andReturn(true);

        // Confidence 0.80 < threshold 0.95 → Approve tier → held.
        $this->mockAiClientProposesClose(confidence: 0.80);

        (new RunTechnicianAgent($ticket->id))->handle();

        // Run must be held, not Done.
        $run = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'propose_close')
            ->first();
        $this->assertNotNull($run);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        // Ticket must NOT be closed.
        $this->assertSame(TicketStatus::PendingClient, $ticket->fresh()->status);

        // Gate wrote awaiting_approval, NOT executed.
        $this->assertDatabaseHas('technician_action_logs', [
            'ticket_id' => $ticket->id,
            'action_type' => 'propose_close',
            'result_status' => 'awaiting_approval',
        ]);
        $this->assertDatabaseMissing('technician_action_logs', [
            'ticket_id' => $ticket->id,
            'result_status' => 'executed',
        ]);
    }

    // ── 5. No-retro-close (CO-25) ─────────────────────────────────────────────

    /**
     * Enabling the auto threshold after a held run already exists must NOT
     * retroactively auto-close that run. The dedup guard fires first (before the
     * gate or agent are called), so the existing held run survives unchanged.
     *
     * Proves: flipping on the auto band does not close the backlog of held proposals.
     */
    public function test_no_retro_close_existing_held_run_survives_threshold_being_enabled(): void
    {
        $this->enableAgent();

        // PendingClient: open + auto-eligible (important — proves the dedup fires
        // before the eligibility/confidence logic can even be evaluated).
        $ticket = $this->openAutoEligibleTicket();

        // Plant an existing held propose_close run.
        $existingRun = TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'propose_close',
            'content_hash' => hash('sha256', 'propose_close:'.$ticket->id.':No response in 45 days.'),
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'No response in 45 days.',
            'confidence' => 0.97,
            'tokens_used' => 0,
        ]);

        // NOW enable the auto threshold — this is the "retroactive" scenario.
        $this->setAutoThreshold(0.95);

        // Gate and agent must never be reached — dedup fires first.
        $gate = $this->mock(SignificanceGate::class);
        $gate->shouldReceive('assess')->never();

        $agent = $this->mock(TechnicianAgent::class);
        $agent->shouldReceive('run')->never();

        (new RunTechnicianAgent($ticket->id))->handle();

        // The existing held run must still be AwaitingApproval — NOT auto-closed.
        $this->assertSame(TechnicianRunState::AwaitingApproval, $existingRun->fresh()->state);

        // No second proposal must have been created.
        $this->assertSame(
            1,
            TechnicianRun::where('ticket_id', $ticket->id)
                ->where('action_type', 'propose_close')
                ->count(),
        );

        // Ticket must still be open.
        $this->assertNotSame(TicketStatus::Closed, $ticket->fresh()->status);
    }
}
