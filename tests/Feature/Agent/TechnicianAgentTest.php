<?php

namespace Tests\Feature\Agent;

use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\TechnicianAgent;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiResponse;
use App\Services\Technician\Notify\OperatorNotifier;
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
