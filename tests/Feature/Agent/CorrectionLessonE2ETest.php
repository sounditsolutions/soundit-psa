<?php

namespace Tests\Feature\Agent;

use App\Enums\TicketStatus;
use App\Enums\WikiFactSource;
use App\Jobs\RunTechnicianAgent;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WikiFact;
use App\Services\Agent\SignificanceGate;
use App\Services\Agent\Steering\CorrectionRecorder;
use App\Services\Agent\Steering\LessonCandidate;
use App\Services\Agent\Steering\LessonDistiller;
use App\Services\Agent\TechnicianAgent;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiResponse;
use App\Services\Triage\ContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correction → LEARN loop E2E.
 *
 * Proves that:
 *   (a) A correction-driven RunTechnicianAgent run autonomously distils the
 *       operator's correction into a durable wiki fact and composes the overview,
 *       so a LATER ticket on the same client sees it in its ContextBuilder context.
 *       (The loop closes.)
 *   (b) A normal (non-correction) run never writes a Correction-sourced fact,
 *       regardless of wiki configuration. (The wire-in guard holds.)
 *
 * Both AI boundaries are mocked deterministically:
 *   — LessonDistiller → fixed knowledge candidate (no AI call needed).
 *   — AiClient::runToolLoop → no-op AiResponse (agent leaves the ticket unchanged).
 *   — AiClient::completeJson → fixed overview_md (WikiOverviewComposer uses this).
 */
class CorrectionLessonE2ETest extends TestCase
{
    use RefreshDatabase;

    // ── shared setup helpers ─────────────────────────────────────────────────

    private function enableBase(): void
    {
        Setting::setValue('agent_enabled', '1');
        Setting::setValue('wiki_enabled', '1');
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key');
    }

    private function fakeAiResponse(): AiResponse
    {
        return new AiResponse(text: '', inputTokens: 0, outputTokens: 0, stopReason: 'end_turn');
    }

    /** Overview body that is ≥ 200 chars, contains the statement, and is redactor-clean. */
    private function overviewMd(): string
    {
        return "## Environment\n\nAcme is on a no-auto-close contract. "
            .str_repeat('Documented client policy and environment detail. ', 6);
    }

    // ── 1. The loop closes ───────────────────────────────────────────────────

    /**
     * Full E2E — correction-driven RunTechnicianAgent writes a durable wiki fact,
     * which is composed into the client overview, which then surfaces in a LATER
     * ticket's ContextBuilder context.
     */
    public function test_loop_closes_via_wiki_overview(): void
    {
        $this->enableBase();

        // First user = AI actor (TechnicianConfig fallback).
        $operator = User::factory()->create();

        $client = Client::factory()->create();
        $ticket = Ticket::factory()->for($client)->create(['status' => TicketStatus::InProgress]);

        // ── AI boundary 1: distiller → fixed knowledge candidate (no AI call) ─
        $candidate = new LessonCandidate(
            'knowledge', 'known-issues', 'active',
            'acme:no-auto-close', 'Acme is on a no-auto-close contract.', 0.9,
        );
        $this->mock(LessonDistiller::class)
            ->shouldReceive('distill')
            ->andReturn($candidate);

        // ── AI boundary 2: AiClient — runToolLoop (TechnicianAgent) + completeJson
        //    (WikiOverviewComposer). Instance in the container so both consumers get
        //    the same mock (mirrors ProposalProvenanceTest rebind pattern).
        $mockAi = \Mockery::mock(AiClient::class);
        $mockAi->shouldReceive('runToolLoop')
            ->once()
            ->andReturn($this->fakeAiResponse()); // agent does nothing (no propose_close)
        $mockAi->shouldReceive('completeJson')
            ->once()
            ->andReturn(['overview_md' => $this->overviewMd()]);
        $mockAi->shouldReceive('cumulativeInputTokens')->andReturn(100);
        $mockAi->shouldReceive('cumulativeOutputTokens')->andReturn(50);

        $this->app->instance(AiClient::class, $mockAi);
        $this->app->bind(TechnicianAgent::class, fn () => new TechnicianAgent($mockAi));

        // ── Record the correction, then fire the job ─────────────────────────
        $conv = app(CorrectionRecorder::class)->record(
            $ticket, $operator,
            'Acme is on a no-auto-close contract — never auto-close their tickets.',
        );

        // correctionDriven=true: skips all autonomous guards (#5–#8) so the agent
        // always re-assesses, then step 11 captures the lesson.
        (new RunTechnicianAgent($ticket->id, correctionDriven: true))->handle();

        // ── Assert 1: durable Correction-sourced fact written ────────────────
        $fact = WikiFact::where('source_type', WikiFactSource::Correction->value)->first();
        $this->assertNotNull($fact, 'A WikiFact with source_type=Correction must exist.');
        $this->assertSame('Acme is on a no-auto-close contract.', $fact->statement);

        // source_refs must carry the conversation_id so provenance is traceable.
        $refs = $fact->source_refs ?? [];
        $this->assertNotEmpty($refs);
        $this->assertSame($conv->id, $refs[0]['conversation_id'] ?? null);

        // ── Assert 2: the loop closes — a LATER ticket sees the lesson ───────
        $later = Ticket::factory()->for($client)->create(['status' => TicketStatus::InProgress]);
        $ctx = ContextBuilder::buildForTicket($later);

        $this->assertStringContainsString(
            'no-auto-close contract', $ctx,
            'The captured fact must reach a later ticket\'s context.',
        );
        $this->assertStringContainsString(
            'Client Environment Overview', $ctx,
            'The composed wiki overview must be injected into the context.',
        );
    }

    // ── 2. Normal run captures nothing ───────────────────────────────────────

    /**
     * A non-correction-driven run must NEVER write a Correction-sourced fact —
     * the wire-in at step 11 is correctionDriven-guarded.
     */
    public function test_normal_run_captures_nothing(): void
    {
        $this->enableBase();

        User::factory()->create(); // AI actor

        $client = Client::factory()->create();
        $ticket = Ticket::factory()->for($client)->create(['status' => TicketStatus::InProgress]);

        // If step 11 were reached, the distiller would return a knowledge candidate.
        // Asserting it is never called proves the guard is what stops us.
        $this->mock(LessonDistiller::class)
            ->shouldReceive('distill')
            ->never();

        // Significance gate returns false → agent returns at step 8 (before steps 10–11).
        // correctionDriven=false, so step 11 would never be reached even if the gate passed.
        $this->mock(SignificanceGate::class)
            ->shouldReceive('assess')
            ->andReturn(false);

        (new RunTechnicianAgent($ticket->id, correctionDriven: false))->handle();

        $this->assertSame(
            0,
            WikiFact::where('source_type', WikiFactSource::Correction->value)->count(),
            'A normal run must not write any Correction-sourced wiki facts.',
        );
    }
}
