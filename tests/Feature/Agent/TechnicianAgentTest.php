<?php

namespace Tests\Feature\Agent;

use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Agent\TechnicianAgent;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiResponse;
use App\Services\Technician\Notify\OperatorNotifier;
use App\Services\Technician\TechnicianDraft;
use App\Services\Technician\TechnicianReplyDrafter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TechnicianAgent — the event-woken tool-loop brain (Task 5).
 *
 * All five tests mock AiClient::runToolLoop to drive the loop deterministically —
 * no real HTTP calls are made.
 *
 * Tests:
 *  1. Decides to close → held proposal (one TechnicianRun in AwaitingApproval).
 *  2. Fail-soft: runToolLoop throws → run() does NOT throw; no TechnicianRun created.
 *  3. Tool-list fence (CO-1): $tools contains propose_close, no set_ticket_* mutators, no tactical_run_diagnostic.
 *  4. Propose-once (CO-4): executor called twice with different reasons → only ONE TechnicianRun (second call refused).
 *  5. AI not configured: run() returns without calling runToolLoop (mock ->never()).
 */
class TechnicianAgentTest extends TestCase
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
        // First user = AI actor (TechnicianConfig::aiActorUserId() fallback).
        User::factory()->create();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function configureAi(): void
    {
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key'); // AiConfig::isConfigured() → true
    }

    /** Open ticket with a client (required by ContextBuilder and TriageToolExecutor). */
    private function openTicketWithClient(): Ticket
    {
        $client = Client::factory()->create();

        return Ticket::factory()->for($client)->create(['status' => TicketStatus::InProgress]);
    }

    /**
     * Construct the agent with the supplied mock AiClient injected directly.
     * After the AppServiceProvider binding, app(TechnicianAgent::class) builds a
     * real Opus AiClient — tests must bypass the container and inject the mock.
     */
    private function agent(AiClient $ai): TechnicianAgent
    {
        return new TechnicianAgent($ai);
    }

    private function fakeAiResponse(): AiResponse
    {
        return new AiResponse(text: '', inputTokens: 0, outputTokens: 0, stopReason: 'end_turn');
    }

    // ── 1. Decides to close → held proposal ──────────────────────────────────

    public function test_decides_to_close_creates_exactly_one_held_technician_run(): void
    {
        $this->configureAi();
        $ticket = $this->openTicketWithClient();

        // No auto-close threshold → gate holds; notifier must NOT fire.
        $notifier = $this->mock(OperatorNotifier::class);
        $notifier->shouldReceive('notify')->never();

        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('runToolLoop')
            ->once()
            ->andReturnUsing(function ($system, $user, $tools, $executor): AiResponse {
                // Simulate the model calling propose_close once.
                $executor('propose_close', ['reason' => 'client confirmed sorted 100d ago', 'confidence' => 0.5]);

                return $this->fakeAiResponse();
            });

        $this->agent($ai)->run($ticket);

        $run = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'propose_close')
            ->first();

        $this->assertNotNull($run, 'A TechnicianRun(propose_close) must be created.');
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        $this->assertSame(
            1,
            TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'propose_close')->count(),
        );
    }

    // ── 2. Fail-soft ─────────────────────────────────────────────────────────

    public function test_run_does_not_throw_when_run_tool_loop_throws(): void
    {
        $this->configureAi();
        $ticket = $this->openTicketWithClient();

        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('runToolLoop')
            ->once()
            ->andThrow(new \RuntimeException('model hiccup'));

        // Must not throw — run() is fail-soft.
        $this->agent($ai)->run($ticket);

        // No TechnicianRun created (loop threw before any executor call).
        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->count());
    }

    // ── 3. Tool-list fence (CO-1) ─────────────────────────────────────────────

    /**
     * Capture the $tools array passed to runToolLoop and assert its contents
     * OUTSIDE run() — assertions inside the andReturnUsing callback would be
     * swallowed by the fail-soft try/catch (AssertionFailedError extends Error
     * extends Throwable).
     */
    public function test_tool_list_contains_propose_close_and_no_mutators(): void
    {
        $this->configureAi();
        $ticket = $this->openTicketWithClient();

        $capturedTools = null;

        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('runToolLoop')
            ->once()
            ->andReturnUsing(function ($system, $user, $tools, $executor) use (&$capturedTools): AiResponse {
                $capturedTools = $tools; // capture for assertion after run() returns

                return $this->fakeAiResponse();
            });

        $this->agent($ai)->run($ticket);

        $this->assertNotNull($capturedTools, 'runToolLoop must have been called.');
        $names = array_column($capturedTools, 'name');

        // propose_close must be present (the one gated ACT tool).
        $this->assertContains('propose_close', $names, 'Tool list must include propose_close.');

        // No set_ticket_* mutators — CO-1 fence.
        foreach ($names as $name) {
            $this->assertFalse(
                str_starts_with($name, 'set_ticket_'),
                "Tool list must not include mutator '{$name}'.",
            );
        }

        // tactical_run_diagnostic must be absent — CO-1 fence.
        $this->assertNotContains('tactical_run_diagnostic', $names);
    }

    // ── 4. Propose-once (CO-4) ────────────────────────────────────────────────

    /**
     * The propose-once guard in TechnicianAgent must prevent a second propose_close
     * from reaching ProposeCloseTool even when the two calls use different reasons
     * (different content hashes — ProposeCloseTool's own idempotency guard would
     * NOT prevent a second row in that case, so the agent-level guard is critical).
     */
    public function test_propose_once_guard_prevents_second_proposal_being_dispatched(): void
    {
        $this->configureAi();
        $ticket = $this->openTicketWithClient();

        // No auto-close threshold → held; notifier must not fire.
        $notifier = $this->mock(OperatorNotifier::class);
        $notifier->shouldReceive('notify')->never();

        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('runToolLoop')
            ->once()
            ->andReturnUsing(function ($system, $user, $tools, $executor): AiResponse {
                // First call — propose-once guard allows this through.
                $executor('propose_close', ['reason' => 'client confirmed sorted 100d ago', 'confidence' => 0.5]);
                // Second call with a different reason — propose-once guard MUST block.
                $executor('propose_close', ['reason' => 'different reason entirely', 'confidence' => 0.6]);

                return $this->fakeAiResponse();
            });

        $this->agent($ai)->run($ticket);

        // Exactly ONE TechnicianRun must exist — the second call was refused.
        $this->assertSame(
            1,
            TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'propose_close')->count(),
        );
    }

    // ── H. flag_attention wiring + one-action-per-run guard ──────────────────

    public function test_calling_flag_attention_creates_exactly_one_held_flagged_run(): void
    {
        $this->configureAi();
        $ticket = $this->openTicketWithClient();

        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('runToolLoop')
            ->once()
            ->andReturnUsing(function ($system, $user, $tools, $executor): AiResponse {
                $executor('flag_attention', ['reason' => 'Needs an owner decision I cannot make.', 'category' => 'needs_decision']);

                return $this->fakeAiResponse();
            });

        $this->agent($ai)->run($ticket);

        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'flag_attention')->first();
        $this->assertNotNull($run, 'A held flag_attention run must be created.');
        $this->assertSame(TechnicianRunState::Flagged, $run->state);
        $this->assertSame(1, TechnicianRun::where('ticket_id', $ticket->id)->count());
    }

    /**
     * The one-action-per-run guard is action-AGNOSTIC: a propose_close THEN a
     * flag_attention (or vice versa) must yield exactly ONE run — the agent takes
     * at most one action per ticket (propose_close OR flag_attention OR nothing).
     */
    public function test_one_action_per_run_guard_blocks_a_flag_after_a_close(): void
    {
        $this->configureAi();
        $ticket = $this->openTicketWithClient();

        $notifier = $this->mock(OperatorNotifier::class);
        $notifier->shouldReceive('notify')->never();

        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('runToolLoop')
            ->once()
            ->andReturnUsing(function ($system, $user, $tools, $executor): AiResponse {
                $executor('propose_close', ['reason' => 'client confirmed sorted 100d ago', 'confidence' => 0.5]);
                $executor('flag_attention', ['reason' => 'second action — must be blocked', 'category' => 'other']);

                return $this->fakeAiResponse();
            });

        $this->agent($ai)->run($ticket);

        $this->assertSame(1, TechnicianRun::where('ticket_id', $ticket->id)->count(), 'only the first action may land');
        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'flag_attention')->count());
    }

    public function test_one_action_per_run_guard_blocks_a_close_after_a_flag(): void
    {
        $this->configureAi();
        $ticket = $this->openTicketWithClient();

        $notifier = $this->mock(OperatorNotifier::class);
        $notifier->shouldReceive('notify')->never();

        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('runToolLoop')
            ->once()
            ->andReturnUsing(function ($system, $user, $tools, $executor): AiResponse {
                $executor('flag_attention', ['reason' => 'needs a person', 'category' => 'uncertain']);
                $executor('propose_close', ['reason' => 'second action — must be blocked', 'confidence' => 0.9]);

                return $this->fakeAiResponse();
            });

        $this->agent($ai)->run($ticket);

        $this->assertSame(1, TechnicianRun::where('ticket_id', $ticket->id)->count(), 'only the first action may land');
        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'propose_close')->count());
    }

    public function test_tool_list_includes_flag_attention(): void
    {
        $this->configureAi();
        $ticket = $this->openTicketWithClient();

        $capturedTools = null;
        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('runToolLoop')
            ->once()
            ->andReturnUsing(function ($system, $user, $tools, $executor) use (&$capturedTools): AiResponse {
                $capturedTools = $tools;

                return $this->fakeAiResponse();
            });

        $this->agent($ai)->run($ticket);

        $names = array_column($capturedTools ?? [], 'name');
        $this->assertContains('flag_attention', $names, 'Tool list must include flag_attention.');
        $this->assertContains('propose_close', $names);
    }

    public function test_taking_no_action_leaves_the_ticket_with_no_run(): void
    {
        // The conservative leave-it path: when the model takes no tool action, the
        // agent records nothing — no spurious flag, no close. (Whether the model
        // SHOULD flag a given ticket is the prompt's job, calibrated in the soak.)
        $this->configureAi();
        $ticket = $this->openTicketWithClient();

        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('runToolLoop')
            ->once()
            ->andReturnUsing(fn ($system, $user, $tools, $executor): AiResponse => $this->fakeAiResponse());

        $this->agent($ai)->run($ticket);

        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->count());
    }

    // ── psa-3q0c: run() surfaces a leave-it outcome (for correction visibility) ──

    /**
     * When the model takes NO action, run() returns an outcome whose leftAsIs() is true and
     * whose narration carries the model's closing reasoning — the reason the caller surfaces
     * to the operator on a correction-driven re-assessment.
     */
    public function test_run_returns_a_left_as_is_outcome_with_the_agent_narration(): void
    {
        $this->configureAi();
        $ticket = $this->openTicketWithClient();

        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('runToolLoop')->once()
            ->andReturnUsing(fn ($system, $user, $tools, $executor): AiResponse => new AiResponse(
                text: 'Leaving as-is: the client confirmed the printer was fixed on 2026-06-01 and no reply is pending.',
            ));

        $outcome = $this->agent($ai)->run($ticket);

        $this->assertTrue($outcome->assessed, 'the loop ran');
        $this->assertFalse($outcome->acted, 'no action was taken');
        $this->assertTrue($outcome->leftAsIs(), 'a no-action run is a leave-it');
        $this->assertStringContainsString('printer was fixed', $outcome->narration);
    }

    /**
     * When the model DOES act (propose_close), the outcome is acted (leftAsIs() false) — there is
     * a new proposal to surface, so the caller records no leave-it note.
     */
    public function test_run_returns_an_acted_outcome_when_the_agent_proposes(): void
    {
        $this->configureAi();
        $ticket = $this->openTicketWithClient();

        $notifier = $this->mock(OperatorNotifier::class);
        $notifier->shouldReceive('notify')->never();

        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('runToolLoop')->once()
            ->andReturnUsing(function ($system, $user, $tools, $executor): AiResponse {
                $executor('propose_close', ['reason' => 'client confirmed sorted 100d ago', 'confidence' => 0.5]);

                return $this->fakeAiResponse();
            });

        $outcome = $this->agent($ai)->run($ticket);

        $this->assertTrue($outcome->acted, 'the agent acted');
        $this->assertFalse($outcome->leftAsIs(), 'an acted run is not a leave-it');
    }

    /** AI unconfigured → notAssessed(): NOT a leave-it (no conclusion was reached). */
    public function test_run_returns_not_assessed_when_ai_is_unconfigured(): void
    {
        $ticket = $this->openTicketWithClient();

        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('runToolLoop')->never();

        $outcome = $this->agent($ai)->run($ticket);

        $this->assertFalse($outcome->assessed);
        $this->assertFalse($outcome->leftAsIs(), 'an unrun agent must NOT report a leave-it');
    }

    /** A caught fail-soft error → notAssessed(): a crash is NOT a "decided to leave it". */
    public function test_run_returns_not_assessed_when_the_loop_throws(): void
    {
        $this->configureAi();
        $ticket = $this->openTicketWithClient();

        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('runToolLoop')->once()->andThrow(new \RuntimeException('model hiccup'));

        $outcome = $this->agent($ai)->run($ticket);

        $this->assertFalse($outcome->assessed);
        $this->assertFalse($outcome->leftAsIs(), 'a caught error must NOT report a leave-it');
    }

    // ── A2a: send_reply is built + guarded, but NOT yet offered (inert) ───────

    private function unaddressedClientReply(Ticket $ticket): void
    {
        TicketNote::create([
            'ticket_id' => $ticket->id, 'author_name' => 'Client',
            'who_type' => \App\Enums\WhoType::EndUser, 'ai_authored' => false,
            'body' => 'Any update?', 'note_type' => \App\Enums\NoteType::Reply,
            'is_private' => false, 'noted_at' => now(),
        ]);
    }

    /**
     * A2b: send_reply is now OFFERED to the model — the agent is the sole producer of held
     * client replies (DraftPipeline's reply branch retired). Still no mutators in the list.
     */
    public function test_send_reply_is_offered_to_the_model(): void
    {
        $this->configureAi();
        $ticket = $this->openTicketWithClient();

        $capturedTools = null;
        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('runToolLoop')->once()
            ->andReturnUsing(function ($system, $user, $tools, $executor) use (&$capturedTools): AiResponse {
                $capturedTools = $tools;

                return $this->fakeAiResponse();
            });

        $this->agent($ai)->run($ticket);

        $names = array_column($capturedTools ?? [], 'name');
        $this->assertContains('send_reply', $names, 'send_reply must be offered to the model in A2b.');
        $this->assertContains('propose_close', $names);
        $this->assertContains('flag_attention', $names);
        // The CO-1 fence still holds: no mutators leak in.
        foreach ($names as $name) {
            $this->assertFalse(str_starts_with($name, 'set_ticket_'), "mutator '{$name}' must not be offered.");
        }
        $this->assertNotContains('tactical_run_diagnostic', $names);
    }

    /**
     * The one-action-per-run guard must cover send_reply too: a propose_close THEN a
     * send_reply yields exactly ONE run — the send_reply is refused BEFORE it reaches
     * SendReplyTool (so the drafter is never even consulted).
     */
    public function test_one_action_guard_blocks_send_reply_after_a_close(): void
    {
        $this->configureAi();
        $notifier = $this->mock(OperatorNotifier::class);
        $notifier->shouldReceive('notify')->never();
        // The drafter must NEVER be consulted — the one-action guard refuses send_reply
        // BEFORE it reaches SendReplyTool (the unaddressed-reply fixture below means the
        // tool WOULD draft if the guard ever let it through, so ->never() proves the guard).
        $this->mock(TechnicianReplyDrafter::class, fn ($m) => $m->shouldReceive('draft')->never());

        $ticket = $this->openTicketWithClient();
        $this->unaddressedClientReply($ticket);

        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('runToolLoop')->once()
            ->andReturnUsing(function ($system, $user, $tools, $executor): AiResponse {
                $executor('propose_close', ['reason' => 'client confirmed sorted', 'confidence' => 0.5]);
                $executor('send_reply', ['reason' => 'second action — must be blocked']);

                return $this->fakeAiResponse();
            });

        $this->agent($ai)->run($ticket);

        $this->assertSame(1, TechnicianRun::where('ticket_id', $ticket->id)->count(), 'only the first action may land');
        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->count());
    }

    /**
     * Reverse direction: a send_reply THEN a propose_close yields exactly ONE run — the
     * send_reply lands first and the close is refused.
     */
    public function test_one_action_guard_blocks_a_close_after_send_reply(): void
    {
        $this->configureAi();
        $notifier = $this->mock(OperatorNotifier::class);
        $notifier->shouldReceive('notify')->never();
        $this->mock(TechnicianReplyDrafter::class, fn ($m) => $m->shouldReceive('draft')
            ->andReturn(new TechnicianDraft('Held reply body.', 'c@example.com', 50)));

        $ticket = $this->openTicketWithClient();
        $this->unaddressedClientReply($ticket);

        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('runToolLoop')->once()
            ->andReturnUsing(function ($system, $user, $tools, $executor): AiResponse {
                $executor('send_reply', ['reason' => 'client awaiting a reply']);
                $executor('propose_close', ['reason' => 'second action — must be blocked', 'confidence' => 0.9]);

                return $this->fakeAiResponse();
            });

        $this->agent($ai)->run($ticket);

        $this->assertSame(1, TechnicianRun::where('ticket_id', $ticket->id)->count(), 'only the first action may land');
        $this->assertSame(1, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->count());
        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'propose_close')->count());
    }

    // ── 5. AI not configured ──────────────────────────────────────────────────

    public function test_run_no_ops_when_ai_is_not_configured(): void
    {
        // No API key set → AiConfig::isConfigured() = false.
        $ticket = $this->openTicketWithClient();

        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('runToolLoop')->never();

        $this->agent($ai)->run($ticket);

        // No TechnicianRun created.
        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->count());
    }

    // ── 6. AI explicitly disabled (T5 branch) ────────────────────────────────

    /**
     * Fix-5 T5: AiConfig::isEnabled()=false while configured → run() no-ops.
     * Distinct from the isConfigured()=false case above: the API key IS present,
     * but the operator has explicitly set ai_enabled = 0.
     */
    public function test_run_no_ops_when_ai_is_explicitly_disabled(): void
    {
        $this->configureAi(); // API key present → isConfigured() = true

        // Explicitly disable (default is '1' — set to '0' to disable).
        Setting::setValue('ai_enabled', '0');

        $ticket = $this->openTicketWithClient();

        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('runToolLoop')->never();

        $this->agent($ai)->run($ticket);

        // No TechnicianRun created — run() returned early on isEnabled()=false.
        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->count());
    }
}
