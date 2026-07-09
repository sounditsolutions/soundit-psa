<?php

namespace Tests\Feature\Agent;

use App\Enums\TicketStatus;
use App\Jobs\RunTechnicianAgent;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\Steering\CorrectionRecorder;
use App\Services\Agent\Steering\LeaveItOutcomeRecorder;
use App\Services\Agent\Steering\LessonCapture;
use App\Services\Agent\TechnicianAgent;
use App\Services\Agent\TechnicianAgentOutcome;
use App\Services\Triage\ContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-3q0c (psa-rmus FIX 2) — a correction-driven "leave-it" must be VISIBLE.
 *
 * When an operator declines + corrects a proposal and the re-assessment produces
 * NO new proposal, the agent's decision to leave the ticket as-is is recorded as an
 * assistant turn on the SAME ticket_correction conversation — so the operator sees an
 * outcome instead of a card that silently vanished ("did my correction do anything?").
 *
 * These tests drive RunTechnicianAgent::handle() with a MOCKED TechnicianAgent so the
 * outcome (acted vs left-it vs not-assessed) is deterministic. LessonCapture is mocked
 * to a no-op so step 11 does no real work.
 */
class LeaveItVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Keep step 11 (LessonCapture) inert — it is exercised by its own tests.
        $this->mock(LessonCapture::class, fn ($m) => $m->shouldReceive('capture')->andReturnNull());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function enableAgent(): void
    {
        Setting::setValue('agent_enabled', '1');
    }

    private function openTicketWithOperationalClient(): Ticket
    {
        $client = Client::factory()->create();

        return Ticket::factory()->for($client)->create(['status' => TicketStatus::InProgress]);
    }

    /** Seed a realistic ticket_correction conversation (operator's correction = a user turn). */
    private function recordCorrection(Ticket $ticket, User $operator, string $text): AssistantConversation
    {
        return app(CorrectionRecorder::class)->record($ticket, $operator, $text, null);
    }

    private function mockAgentOutcome(TechnicianAgentOutcome $outcome): void
    {
        $agent = $this->mock(TechnicianAgent::class);
        $agent->shouldReceive('run')->once()->andReturn($outcome);
    }

    private function leaveItTurns(AssistantConversation $conversation): int
    {
        return $conversation->messages()
            ->where('role', 'assistant')
            ->where('content', 'like', LeaveItOutcomeRecorder::NOTE_PREFIX.'%')
            ->count();
    }

    // ── the headline: a leave-it correction-driven run records a VISIBLE outcome ──

    public function test_correction_driven_leave_it_records_a_reasoned_assistant_turn(): void
    {
        $this->enableAgent();
        $ticket = $this->openTicketWithOperationalClient();
        $operator = User::factory()->create();
        $conversation = $this->recordCorrection($ticket, $operator, 'The client explicitly asked us to keep this open.');

        $this->mockAgentOutcome(new TechnicianAgentOutcome(
            assessed: true,
            acted: false,
            narration: 'Leaving as-is: the client asked to keep it open pending their vendor call next week.',
        ));

        (new RunTechnicianAgent($ticket->id, correctionDriven: true))->handle();

        $turn = $conversation->messages()->where('role', 'assistant')->latest('id')->first();
        $this->assertNotNull($turn, 'a leave-it must record an assistant turn on the correction conversation');
        $this->assertStringStartsWith(LeaveItOutcomeRecorder::NOTE_PREFIX, $turn->content);
        $this->assertStringContainsString('pending their vendor call', $turn->content, 'the agent reason is included');
    }

    /** With no narration, the note still records (a bare confirmation the re-assessment ran). */
    public function test_leave_it_with_empty_narration_still_records_a_confirmation(): void
    {
        $this->enableAgent();
        $ticket = $this->openTicketWithOperationalClient();
        $operator = User::factory()->create();
        $conversation = $this->recordCorrection($ticket, $operator, 'keep open');

        $this->mockAgentOutcome(new TechnicianAgentOutcome(assessed: true, acted: false, narration: ''));

        (new RunTechnicianAgent($ticket->id, correctionDriven: true))->handle();

        $turn = $conversation->messages()->where('role', 'assistant')->latest('id')->first();
        $this->assertNotNull($turn);
        $this->assertSame(LeaveItOutcomeRecorder::NOTE_PREFIX.'.', $turn->content);
    }

    // ── the negatives: when NOT to record ────────────────────────────────────

    /** The agent acted (new proposal) → nothing to surface here; no leave-it turn. */
    public function test_correction_driven_acted_records_no_leave_it_turn(): void
    {
        $this->enableAgent();
        $ticket = $this->openTicketWithOperationalClient();
        $operator = User::factory()->create();
        $conversation = $this->recordCorrection($ticket, $operator, 'reconsider this');

        $this->mockAgentOutcome(new TechnicianAgentOutcome(assessed: true, acted: true, narration: 'proposed a flag'));

        (new RunTechnicianAgent($ticket->id, correctionDriven: true))->handle();

        $this->assertSame(0, $this->leaveItTurns($conversation), 'an acted run must not record a leave-it note');
    }

    /** The loop never assessed (unconfigured/disabled/error) → NOT a leave-it; no turn. */
    public function test_not_assessed_outcome_records_no_leave_it_turn(): void
    {
        $this->enableAgent();
        $ticket = $this->openTicketWithOperationalClient();
        $operator = User::factory()->create();
        $conversation = $this->recordCorrection($ticket, $operator, 'reconsider this');

        $this->mockAgentOutcome(TechnicianAgentOutcome::notAssessed());

        (new RunTechnicianAgent($ticket->id, correctionDriven: true))->handle();

        $this->assertSame(0, $this->leaveItTurns($conversation), 'a not-assessed outcome is not a leave-it');
    }

    /** A NORMAL (autonomous) run that leaves a ticket is silent by design — no correction, no note. */
    public function test_non_correction_run_records_no_leave_it_turn(): void
    {
        $this->enableAgent();
        $ticket = $this->openTicketWithOperationalClient();
        $operator = User::factory()->create();
        // A stray same-ticket correction conversation exists, but this run is NOT correction-driven.
        $conversation = $this->recordCorrection($ticket, $operator, 'unrelated older correction');

        $this->mockAgentOutcome(new TechnicianAgentOutcome(assessed: true, acted: false, narration: 'left it'));

        (new RunTechnicianAgent($ticket->id, correctionDriven: false))->handle();

        $this->assertSame(0, $this->leaveItTurns($conversation), 'autonomous leave-it stays silent');
    }

    // ── provenance intact + no corruption of the operator directive ──────────

    /**
     * The leave-it turn lives on the operator's OWN correction conversation (provenance is
     * inherent), and — being assistant-role — it does NOT leak into the operator-directive
     * context, which reads user-role messages only.
     */
    public function test_provenance_intact_and_operator_directive_uncorrupted(): void
    {
        $this->enableAgent();
        $ticket = $this->openTicketWithOperationalClient();
        $operator = User::factory()->create(['name' => 'Dana Operator']);
        $conversation = $this->recordCorrection($ticket, $operator, 'Please keep this open — client is travelling.');

        $this->mockAgentOutcome(new TechnicianAgentOutcome(
            assessed: true,
            acted: false,
            narration: 'Leaving as-is per the operator; client is travelling.',
        ));

        (new RunTechnicianAgent($ticket->id, correctionDriven: true))->handle();

        // Provenance: the assistant turn belongs to the operator's correction conversation.
        $turn = $conversation->messages()->where('role', 'assistant')->latest('id')->first();
        $this->assertNotNull($turn);
        $this->assertSame($conversation->id, $turn->conversation_id);
        $this->assertSame($operator->id, $conversation->fresh()->user_id);

        // The operator directive (ContextBuilder) still reflects the CORRECTION, not the leave-it note.
        $context = ContextBuilder::buildForTicket($ticket->fresh(), includeClientSituation: false);
        $this->assertStringContainsString('client is travelling', $context);
        $this->assertStringNotContainsString(LeaveItOutcomeRecorder::NOTE_PREFIX, $context,
            'the assistant leave-it turn must NOT pollute the operator directive');
    }

    /** No correction conversation on record → the guard skips cleanly (no crash, no turn). */
    public function test_leave_it_with_no_correction_conversation_is_a_safe_no_op(): void
    {
        $this->enableAgent();
        $ticket = $this->openTicketWithOperationalClient();

        $this->mockAgentOutcome(new TechnicianAgentOutcome(assessed: true, acted: false, narration: 'left it'));

        (new RunTechnicianAgent($ticket->id, correctionDriven: true))->handle();

        $this->assertSame(0, AssistantMessage::where('role', 'assistant')->count());
    }
}
