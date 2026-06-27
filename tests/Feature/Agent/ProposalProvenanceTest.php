<?php

namespace Tests\Feature\Agent;

use App\Enums\TicketStatus;
use App\Jobs\RunTechnicianAgent;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\ProposeCloseTool;
use App\Services\Agent\SignificanceGate;
use App\Services\Agent\Steering\CorrectionRecorder;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiResponse;
use App\Services\Technician\Notify\OperatorNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proposal Provenance — Task 5 (psa-gofv).
 *
 * When the agent acts during a correction-driven run, the resulting
 * TechnicianRun.proposed_meta['informed_by_correction'] carries the
 * correction conversation's identity. On a normal (non-correction) run,
 * that key is ABSENT.
 *
 * Tests:
 *  1. End-to-end: correction-driven RunTechnicianAgent → propose_close
 *     → proposed_meta['informed_by_correction']['conversation_id'] matches.
 *  2. Normal (no correction): proposed_meta has NO 'informed_by_correction' key.
 */
class ProposalProvenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // First user = AI actor (TechnicianConfig::aiActorUserId() fallback).
        User::factory()->create();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function enableAgent(): void
    {
        Setting::setValue('agent_enabled', '1');
    }

    private function configureAi(): void
    {
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key');
    }

    private function openTicketWithOperationalClient(): array
    {
        $client = Client::factory()->create(); // Active + is_active by default
        $ticket = Ticket::factory()->for($client)->create(['status' => TicketStatus::InProgress]);

        return [$ticket, $client];
    }

    private function fakeAiResponse(): AiResponse
    {
        return new AiResponse(text: '', inputTokens: 0, outputTokens: 0, stopReason: 'end_turn');
    }

    // ── 1. End-to-end: correction-driven → propose_close → provenance present ─

    /**
     * A correction-driven RunTechnicianAgent run threads the correction context
     * through to ProposeCloseTool, which stores it in proposed_meta.
     *
     * This test exercises the full plumbing:
     *   RunTechnicianAgent → TechnicianAgent → TechnicianAgentToolExecutor → ProposeCloseTool
     * so the integration of every threading change is proven, not just the tool in isolation.
     */
    public function test_correction_driven_run_stores_provenance_in_proposed_meta(): void
    {
        $this->enableAgent();
        $this->configureAi();

        $operator = User::factory()->create();
        [$ticket] = $this->openTicketWithOperationalClient();

        // Seed a ticket_correction conversation via CorrectionRecorder.
        $conv = app(CorrectionRecorder::class)->record(
            $ticket,
            $operator,
            'The client says the issue is NOT resolved — ignore the last proposal.',
        );

        // SignificanceGate passes.
        $gate = $this->mock(SignificanceGate::class);
        $gate->shouldReceive('assess')->once()->andReturn(true);

        // No auto-close threshold → held; operator notifier must NOT fire.
        $notifier = $this->mock(OperatorNotifier::class);
        $notifier->shouldReceive('notify')->never();

        // TechnicianAgent is bound via a factory in AppServiceProvider (uses withConfiguredModel).
        // Rebind it here so the job's `app(TechnicianAgent::class)` resolves our mock-injected
        // instance rather than constructing a fresh real AiClient.
        $ai = \Mockery::mock(AiClient::class);
        $ai->shouldReceive('runToolLoop')
            ->once()
            ->andReturnUsing(function ($system, $user, $tools, $executor): AiResponse {
                $executor('propose_close', ['reason' => 'ticket looks dormant for weeks', 'confidence' => 0.8]);

                return $this->fakeAiResponse();
            });
        $this->app->bind(\App\Services\Agent\TechnicianAgent::class, fn () => new \App\Services\Agent\TechnicianAgent($ai));

        (new RunTechnicianAgent($ticket->id, correctionDriven: true))->handle();

        $run = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'propose_close')
            ->first();

        $this->assertNotNull($run, 'A propose_close TechnicianRun must be created.');

        $meta = $run->proposed_meta;
        $this->assertArrayHasKey('informed_by_correction', $meta,
            "proposed_meta must carry 'informed_by_correction' on a correction-driven run.");

        $provenance = $meta['informed_by_correction'];
        $this->assertSame($conv->id, $provenance['conversation_id'],
            'conversation_id must equal the seeded ticket_correction conversation id.');
        $this->assertSame($operator->id, $provenance['operator_id'],
            'operator_id must equal the operator who recorded the correction.');
        $this->assertArrayHasKey('summary', $provenance,
            "provenance must carry a 'summary' key.");
    }

    // ── 2. Normal run → no 'informed_by_correction' key ─────────────────────

    /**
     * On a normal (non-correction-driven) propose_close, proposed_meta must NOT
     * carry the 'informed_by_correction' key — it is strictly additive.
     *
     * Drives ProposeCloseTool directly (no correction context) to keep the test
     * focused on the tool's output contract rather than the job plumbing.
     */
    public function test_normal_propose_close_has_no_provenance_key(): void
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::PendingClient]);

        // No auto-close → notifier must NOT fire.
        $notifier = $this->mock(OperatorNotifier::class);
        $notifier->shouldReceive('notify')->never();

        // Call without a correction context (the normal path).
        app(ProposeCloseTool::class)->execute(
            $ticket,
            ['reason' => 'client confirmed resolved 60 days ago', 'confidence' => 0.8],
            // correctionContext = null (default — omitted here to prove the parameter is optional)
        );

        $run = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'propose_close')
            ->first();

        $this->assertNotNull($run, 'A TechnicianRun must be created.');

        $this->assertArrayNotHasKey('informed_by_correction', $run->proposed_meta ?? [],
            "proposed_meta must NOT carry 'informed_by_correction' on a normal run.");
    }
}
