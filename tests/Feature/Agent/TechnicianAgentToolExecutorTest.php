<?php

namespace Tests\Feature\Agent;

use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Enums\ToolingGapSource;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\ToolingGap;
use App\Models\User;
use App\Services\Agent\FlagAttentionTool;
use App\Services\Agent\ProposeCloseTool;
use App\Services\Agent\RequestToolTool;
use App\Services\Agent\SendReplyTool;
use App\Services\Agent\TechnicianAgentToolExecutor;
use App\Services\Technician\Notify\OperatorNotifier;
use App\Services\Triage\TriageToolDefinitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TechnicianAgentToolExecutor — the read-only tool fence (Task 4, CO-1 BLOCKER).
 *
 * The executor is the enforcement boundary: a mutator tool name must NEVER
 * reach a mutating code path. All adversarial cases must fail early and leave
 * the DB in the exact same state they found it.
 *
 * Tests:
 *  1. Mutator refusal (CO-1): set_ticket_status, set_ticket_priority,
 *     set_ticket_category, set_ticket_keywords, tactical_run_diagnostic —
 *     each returns the error array; ticket status unchanged; no audit row.
 *  2. Unknown tool refusal: definitely_not_a_tool → error, no throw.
 *  3. Read delegation: search_tickets (and get_ticket_notes) routes through
 *     TriageToolExecutor and returns a read result (no error).
 *  4. propose_close routes to ProposeCloseTool: held TechnicianRun created.
 *  5. readTools() shape: exactly the 5 allowed reads, no mutators.
 */
class TechnicianAgentToolExecutorTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ──────────────────────────────────────────────────────────────

    /** A ticket in an open status (InProgress). Factory defaults to Closed. */
    private function openTicket(): Ticket
    {
        return Ticket::factory()->create(['status' => TicketStatus::InProgress]);
    }

    /** A ticket in an open status that belongs to a client (required by TriageToolExecutor). */
    private function openTicketWithClient(): Ticket
    {
        $client = Client::factory()->create();

        return Ticket::factory()->for($client)->create(['status' => TicketStatus::InProgress]);
    }

    private function executor(Ticket $ticket): TechnicianAgentToolExecutor
    {
        return new TechnicianAgentToolExecutor($ticket, app(ProposeCloseTool::class), app(FlagAttentionTool::class), app(SendReplyTool::class), app(RequestToolTool::class));
    }

    private function assertNoAuditRowForTicket(Ticket $ticket): void
    {
        $this->assertDatabaseMissing('technician_action_logs', ['ticket_id' => $ticket->id]);
    }

    // ── 1. Mutator refusal (CO-1 BLOCKER) ────────────────────────────────────

    public function test_set_ticket_status_is_refused_and_ticket_unchanged(): void
    {
        $ticket = $this->openTicket();

        $result = $this->executor($ticket)->execute('set_ticket_status', ['status' => 'closed']);

        $this->assertSame(['error' => 'tool not available to the agent'], $result);
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $this->assertNoAuditRowForTicket($ticket);
    }

    public function test_set_ticket_priority_is_refused_and_ticket_unchanged(): void
    {
        $ticket = $this->openTicket();
        $originalPriority = $ticket->priority;

        $result = $this->executor($ticket)->execute('set_ticket_priority', ['priority' => 1]);

        $this->assertSame(['error' => 'tool not available to the agent'], $result);
        $this->assertSame($originalPriority, $ticket->fresh()->priority);
        $this->assertNoAuditRowForTicket($ticket);
    }

    public function test_set_ticket_category_is_refused_and_ticket_unchanged(): void
    {
        $ticket = $this->openTicket();
        $originalCategory = $ticket->category;

        $result = $this->executor($ticket)->execute('set_ticket_category', ['category' => 'hardware']);

        $this->assertSame(['error' => 'tool not available to the agent'], $result);
        $this->assertSame($originalCategory, $ticket->fresh()->category);
        $this->assertNoAuditRowForTicket($ticket);
    }

    public function test_set_ticket_keywords_is_refused_and_ticket_unchanged(): void
    {
        $ticket = $this->openTicket();
        $originalKeywords = $ticket->search_keywords;

        $result = $this->executor($ticket)->execute('set_ticket_keywords', ['keywords' => ['printer', 'offline']]);

        $this->assertSame(['error' => 'tool not available to the agent'], $result);
        $this->assertSame($originalKeywords, $ticket->fresh()->search_keywords);
        $this->assertNoAuditRowForTicket($ticket);
    }

    public function test_tactical_run_diagnostic_is_refused_and_ticket_unchanged(): void
    {
        $ticket = $this->openTicket();

        $result = $this->executor($ticket)->execute('tactical_run_diagnostic', [
            'hostname' => 'workstation-01',
            'diagnostic' => 'event_log_errors',
        ]);

        $this->assertSame(['error' => 'tool not available to the agent'], $result);
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $this->assertNoAuditRowForTicket($ticket);
    }

    // ── 2. Unknown tool refusal ───────────────────────────────────────────────

    public function test_unknown_tool_returns_error_without_throwing(): void
    {
        $ticket = $this->openTicket();

        $result = $this->executor($ticket)->execute('definitely_not_a_tool', []);

        $this->assertSame(['error' => 'tool not available to the agent'], $result);
        $this->assertNoAuditRowForTicket($ticket);
    }

    // A second unknown-tool variant to cover names that look plausible but aren't on the list.
    public function test_ninja_search_devices_is_refused(): void
    {
        $ticket = $this->openTicket();

        $result = $this->executor($ticket)->execute('ninja_search_devices', ['query' => 'workstation']);

        $this->assertSame(['error' => 'tool not available to the agent'], $result);
    }

    // ── 3. Read delegation ────────────────────────────────────────────────────

    /**
     * search_tickets must route through TriageToolExecutor and return a read
     * result (an array that is not the error sentinel) — even when empty.
     */
    public function test_search_tickets_delegates_to_triage_executor_and_returns_results(): void
    {
        $ticket = $this->openTicketWithClient();

        $result = $this->executor($ticket)->execute('search_tickets', ['query' => 'nonexistent unique term xyz']);

        // A successful (even empty) read returns an array — not the error sentinel.
        $this->assertIsArray($result);
        $this->assertNotSame(['error' => 'tool not available to the agent'], $result);
        $this->assertArrayNotHasKey('error', $result);
    }

    /**
     * get_ticket_notes must route through TriageToolExecutor. Uses the ticket's
     * own ID so the client-scope check passes (same client).
     */
    public function test_get_ticket_notes_delegates_to_triage_executor(): void
    {
        $ticket = $this->openTicketWithClient();

        $result = $this->executor($ticket)->execute('get_ticket_notes', ['ticket_id' => $ticket->id]);

        // Notes may be empty, but the result is a read array, not the refusal error.
        $this->assertIsArray($result);
        $this->assertNotSame(['error' => 'tool not available to the agent'], $result);
        $this->assertArrayNotHasKey('error', $result);
    }

    // ── 4. propose_close routes to ProposeCloseTool ───────────────────────────

    /**
     * execute('propose_close', …) must delegate to ProposeCloseTool and produce
     * a held TechnicianRun in AwaitingApproval state (no auto threshold configured).
     */
    public function test_propose_close_routes_to_propose_close_tool_and_creates_held_run(): void
    {
        // AI actor required by TechnicianConfig::aiActorUserId() fallback.
        User::factory()->create();

        $ticket = $this->openTicketWithClient();

        // No auto-close threshold → held, not auto-executed → notifier never called.
        $notifier = $this->mock(OperatorNotifier::class);
        $notifier->shouldReceive('notify')->never();

        $this->executor($ticket)->execute('propose_close', [
            'reason' => 'No client response in 45 days; issue appears resolved.',
            'confidence' => 0.5,
        ]);

        $run = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'propose_close')
            ->first();

        $this->assertNotNull($run, 'A TechnicianRun(propose_close) must be created.');
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        // Ticket must NOT be closed by a held run.
        $this->assertNotSame(TicketStatus::Closed, $ticket->fresh()->status);
    }

    // ── 4b. flag_attention routes to FlagAttentionTool ───────────────────────

    /**
     * execute('flag_attention', …) must delegate to FlagAttentionTool and produce
     * a held TechnicianRun in the Flagged state — and never touch the ticket.
     */
    public function test_flag_attention_routes_to_flag_attention_tool_and_creates_held_flag(): void
    {
        User::factory()->create(); // AI actor fallback

        $ticket = $this->openTicketWithClient();

        $this->executor($ticket)->execute('flag_attention', [
            'reason' => 'Needs an owner decision I cannot make.',
            'category' => 'needs_decision',
        ]);

        $run = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'flag_attention')
            ->first();

        $this->assertNotNull($run, 'A TechnicianRun(flag_attention) must be created.');
        $this->assertSame(TechnicianRunState::Flagged, $run->state);
        $this->assertNotSame(TicketStatus::Closed, $ticket->fresh()->status);
    }

    // ── 4c. send_reply routes to SendReplyTool (A2 — held, never sent) ────────

    /**
     * execute('send_reply', …) must delegate to SendReplyTool and produce a held
     * TechnicianRun(send_reply) in AwaitingApproval — never an executed send. The
     * drafter is mocked so no AI call is made; the fence/scan is exercised in the
     * drafter's own tests.
     */
    public function test_send_reply_routes_to_send_reply_tool_and_creates_held_run(): void
    {
        User::factory()->create(); // AI actor fallback for the audit row
        \App\Models\Setting::setValue('ai_provider', 'anthropic');
        \App\Models\Setting::setEncrypted('ai_api_key', 'test-key');
        $this->mock(
            \App\Services\Technician\TechnicianReplyDrafter::class,
            fn ($m) => $m->shouldReceive('draft')->andReturn(new \App\Services\Technician\TechnicianDraft('Held reply body.', 'c@example.com', 100))
        );

        $ticket = $this->openTicketWithClient();
        \App\Models\TicketNote::create([
            'ticket_id' => $ticket->id, 'author_name' => 'Client',
            'who_type' => \App\Enums\WhoType::EndUser, 'ai_authored' => false,
            'body' => 'Any update?', 'note_type' => \App\Enums\NoteType::Reply,
            'is_private' => false, 'noted_at' => now(),
        ]);

        $this->executor($ticket)->execute('send_reply', ['reason' => 'client awaiting a reply']);

        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->first();
        $this->assertNotNull($run, 'A TechnicianRun(send_reply) must be created.');
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertDatabaseMissing('technician_action_logs', ['action_type' => 'send_reply', 'result_status' => 'executed']);
    }

    // ── 5. readTools() shape ──────────────────────────────────────────────────

    public function test_read_tools_contains_exactly_the_five_allowed_reads(): void
    {
        $tools = TriageToolDefinitions::readTools();
        $names = array_column($tools, 'name');

        $this->assertContains('search_tickets', $names);
        $this->assertContains('get_ticket_notes', $names);
        $this->assertContains('wiki_list_pages', $names);
        $this->assertContains('wiki_search', $names);
        $this->assertContains('wiki_get_page', $names);

        $this->assertCount(5, $names, 'readTools() must return exactly the 5 allowed reads.');
    }

    public function test_read_tools_contains_no_set_ticket_mutators(): void
    {
        $tools = TriageToolDefinitions::readTools();
        $names = array_column($tools, 'name');

        foreach ($names as $name) {
            $this->assertFalse(
                str_starts_with($name, 'set_ticket_'),
                "readTools() must not include mutator '{$name}'."
            );
        }
    }

    public function test_read_tools_does_not_contain_tactical_run_diagnostic(): void
    {
        $tools = TriageToolDefinitions::readTools();
        $names = array_column($tools, 'name');

        $this->assertNotContains('tactical_run_diagnostic', $names);
    }

    // ── Regression: mutators don't sneak in through a broader allowlist ───────

    /**
     * Drive every known un-gated mutator through the executor and assert each
     * one is refused. This is the adversarial regression guard: if someone adds
     * a new mutator to the allowlist or changes the routing, this test fails.
     */
    public function test_all_known_mutators_are_refused(): void
    {
        $ticket = $this->openTicket();
        $executor = $this->executor($ticket);

        $mutators = [
            ['set_ticket_status', ['status' => 'closed']],
            ['set_ticket_priority', ['priority' => 1]],
            ['set_ticket_category', ['category' => 'hardware']],
            ['set_ticket_keywords', ['keywords' => ['printer']]],
            ['tactical_run_diagnostic', ['hostname' => 'pc', 'diagnostic' => 'event_log_errors']],
        ];

        foreach ($mutators as [$name, $input]) {
            $result = $executor->execute($name, $input);
            $this->assertSame(
                ['error' => 'tool not available to the agent'],
                $result,
                "Mutator '{$name}' must be refused by TechnicianAgentToolExecutor."
            );
        }

        // Ticket status must be unchanged after all refusals.
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);

        // No audit rows written for any of the refused calls.
        $this->assertNoAuditRowForTicket($ticket);
    }

    // ── 6. request_tool routes to the recording path ─────────────────────────

    /**
     * execute('request_tool', …) must route to RequestToolTool, NOT return the
     * default-deny error, and must write a ToolingGap row.
     */
    public function test_request_tool_routes_to_recording_path_and_writes_gap(): void
    {
        $ticket = $this->openTicketWithClient();

        $result = $this->executor($ticket)->execute('request_tool', [
            'capability_gap' => 'needs NinjaRMM device health lookup',
            'classification' => 'tool_missing',
        ]);

        // Must NOT return the default-deny error.
        $this->assertNotSame(['error' => 'tool not available to the agent'], $result);
        $this->assertIsString($result);

        // A ToolingGap must exist.
        $gap = ToolingGap::where('source', ToolingGapSource::Agent)->first();
        $this->assertNotNull($gap, 'A ToolingGap(source=Agent) must be created.');
        $this->assertSame('needs NinjaRMM device health lookup', $gap->capability_gap);
    }

    // ── 7. request_tool NEVER mutates (adversarial) ───────────────────────────

    /**
     * Even via the executor, request_tool must not touch the ticket, the client,
     * or create any TechnicianRun row.
     */
    public function test_request_tool_never_mutates_ticket_or_creates_technician_run(): void
    {
        $ticket = $this->openTicketWithClient();
        $originalStatus = $ticket->status;

        $this->executor($ticket)->execute('request_tool', [
            'capability_gap' => 'needs vendor contract lookup',
            'classification' => 'tool_missing',
        ]);

        $this->assertSame($originalStatus, $ticket->fresh()->status, 'Ticket status must be unchanged.');
        $this->assertSame(0, TechnicianRun::count(), 'No TechnicianRun must be created by request_tool.');
        $this->assertNoAuditRowForTicket($ticket);
    }

    // ── 8. default-deny still holds for unknown verbs ─────────────────────────

    /**
     * 'set_ticket_status' and other unknowns must STILL return the error sentinel
     * after the request_tool arm is added. Also asserts no ToolingGap is written.
     */
    public function test_default_deny_still_holds_for_unknown_verb(): void
    {
        $ticket = $this->openTicket();

        $result = $this->executor($ticket)->execute('set_ticket_status', ['status' => 'closed']);

        $this->assertSame(['error' => 'tool not available to the agent'], $result);
        $this->assertSame(0, ToolingGap::count(), 'No ToolingGap must be written for a refused tool.');
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
    }
}
