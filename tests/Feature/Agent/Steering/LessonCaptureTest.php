<?php

namespace Tests\Feature\Agent\Steering;

use App\Enums\ToolingGapClassification;
use App\Enums\ToolingGapSource;
use App\Enums\WikiFactSource;
use App\Enums\WikiFactStatus;
use App\Jobs\ComposeClientOverview;
use App\Models\AssistantConversation;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\ToolingGap;
use App\Models\User;
use App\Models\WikiFact;
use App\Services\Agent\Steering\CorrectionRecorder;
use App\Services\Agent\Steering\LessonCandidate;
use App\Services\Agent\Steering\LessonCapture;
use App\Services\Agent\Steering\LessonDistiller;
use App\Services\Wiki\Mining\WikiFactExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * LessonCapture — focused unit tests (mock the distiller; real wiki services).
 *
 * Tests the six behavioral contracts:
 *  1. knowledge → durable Correction-sourced fact written (no approval queue).
 *  2. tooling   → durable ToolingGap row written (source=Correction, class=ToolUnused); no wiki fact.
 *  3. none      → nothing written, no overview dispatch.
 *  4. null      → nothing written, no exception.
 *  5. wiki off  → gate short-circuits only the knowledge branch; tooling still records.
 *  6. fail-soft → internal distiller error must never surface as an exception.
 */
class LessonCaptureTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ──────────────────────────────────────────────────────────────

    private function enableWiki(): void
    {
        Setting::setValue('wiki_enabled', '1');
    }

    private function makeClientAndTicket(): array
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->for($client)->create();

        return [$client, $ticket];
    }

    private function seedCorrection(Ticket $ticket, User $operator, string $text): AssistantConversation
    {
        return app(CorrectionRecorder::class)->record($ticket, $operator, $text);
    }

    // ── 1. knowledge → fact written (Confirmed, pinned, Correction source) ──

    public function test_knowledge_candidate_writes_fact_no_approval_queue(): void
    {
        $this->enableWiki();
        Bus::fake([ComposeClientOverview::class]);

        $operator = User::factory()->create();
        [$client, $ticket] = $this->makeClientAndTicket();
        $conv = $this->seedCorrection($ticket, $operator, 'client is on a no-auto-close contract');

        $candidate = new LessonCandidate('knowledge', 'known-issues', 'active', 'acme:no-auto-close', 'Acme is on a no-auto-close contract.', 0.9);
        $this->mock(LessonDistiller::class)
            ->shouldReceive('distill')
            ->andReturn($candidate);

        app(LessonCapture::class)->capture($ticket, $conv);

        // A Correction-sourced fact must exist, born Confirmed + pinned.
        $fact = WikiFact::where('source_type', WikiFactSource::Correction->value)->first();
        $this->assertNotNull($fact, 'A WikiFact with source_type=Correction must be written.');
        $this->assertSame(WikiFactStatus::Confirmed, $fact->status);
        $this->assertTrue((bool) $fact->pinned);
        $this->assertSame('Acme is on a no-auto-close contract.', $fact->statement);
        $this->assertSame($client->id, $fact->client_id);
        $this->assertSame('active', $fact->section_anchor);

        // source_refs must carry the correction conversation_id.
        $refs = $fact->source_refs ?? [];
        $this->assertNotEmpty($refs);
        $this->assertSame($conv->id, $refs[0]['conversation_id'] ?? null);
        $this->assertSame('correction', $refs[0]['type'] ?? null);

        // NO approval queue — capture writes the fact directly (no TechnicianRun).
        $this->assertSame(0, TechnicianRun::count());

        // Overview recompose dispatched for this client.
        Bus::assertDispatched(
            ComposeClientOverview::class,
            fn (ComposeClientOverview $j) => $j->clientId === $client->id,
        );
    }

    // ── 2. tooling → durable ToolingGap written, no wiki fact ───────────────

    public function test_tooling_candidate_writes_tooling_gap_no_fact(): void
    {
        $this->enableWiki();

        $operator = User::factory()->create();
        [$client, $ticket] = $this->makeClientAndTicket();
        $conv = $this->seedCorrection($ticket, $operator, 'agent did not search sibling tickets');

        $candidate = new LessonCandidate('tooling', statement: 'agent did not search sibling tickets');
        $this->mock(LessonDistiller::class)
            ->shouldReceive('distill')
            ->andReturn($candidate);

        app(LessonCapture::class)->capture($ticket, $conv);

        // No Correction wiki fact must be written.
        $this->assertSame(0, WikiFact::where('source_type', WikiFactSource::Correction->value)->count());

        // A ToolingGap row must be recorded with the correct source and classification.
        $gap = ToolingGap::first();
        $this->assertNotNull($gap, 'A ToolingGap row must be written for a tooling candidate.');
        $this->assertSame(ToolingGapSource::Correction, $gap->source);
        $this->assertSame(ToolingGapClassification::ToolUnused, $gap->classification);
        $this->assertSame($candidate->statement, $gap->capability_gap);
        $this->assertStringContainsString((string) $ticket->id, $gap->evidence);
        $this->assertNotSame($gap->capability_gap, $gap->evidence, 'capability_gap and evidence must be stored separately.');
    }

    // ── 3. none → nothing written, no dispatch ───────────────────────────────

    public function test_none_candidate_writes_nothing_and_does_not_dispatch(): void
    {
        $this->enableWiki();
        Bus::fake([ComposeClientOverview::class]);

        $operator = User::factory()->create();
        [, $ticket] = $this->makeClientAndTicket();
        $conv = $this->seedCorrection($ticket, $operator, 'just routine feedback');

        $this->mock(LessonDistiller::class)
            ->shouldReceive('distill')
            ->andReturn(LessonCandidate::none());

        app(LessonCapture::class)->capture($ticket, $conv);

        $this->assertSame(0, WikiFact::where('source_type', WikiFactSource::Correction->value)->count());
        Bus::assertNotDispatched(ComposeClientOverview::class);
    }

    // ── 4. null from distiller → nothing, no exception ───────────────────────

    public function test_null_from_distiller_writes_nothing_does_not_throw(): void
    {
        $this->enableWiki();

        $operator = User::factory()->create();
        [, $ticket] = $this->makeClientAndTicket();
        $conv = $this->seedCorrection($ticket, $operator, 'some feedback');

        $this->mock(LessonDistiller::class)
            ->shouldReceive('distill')
            ->andReturn(null);

        // Must not throw — null is a graceful "AI call failed, skip".
        app(LessonCapture::class)->capture($ticket, $conv);

        $this->assertSame(0, WikiFact::where('source_type', WikiFactSource::Correction->value)->count());
    }

    // ── 5. wiki disabled → knowledge branch blocked, no fact written ────────

    public function test_wiki_disabled_noop_even_for_knowledge_candidate(): void
    {
        Setting::setValue('wiki_enabled', '0');

        $operator = User::factory()->create();
        [, $ticket] = $this->makeClientAndTicket();
        $conv = $this->seedCorrection($ticket, $operator, 'client is on a no-auto-close contract');

        $candidate = new LessonCandidate('knowledge', 'known-issues', 'active', 'acme:no-auto-close', 'Acme is on a no-auto-close contract.', 0.9);
        $this->mock(LessonDistiller::class)
            ->shouldReceive('distill')
            ->andReturn($candidate);

        app(LessonCapture::class)->capture($ticket, $conv);

        // Wiki gate now guards only the knowledge branch; no wiki fact must be written.
        $this->assertSame(0, WikiFact::where('source_type', WikiFactSource::Correction->value)->count());
    }

    // ── 7. backstop: 'overview' (a real skeleton page) must NEVER be written ──

    /**
     * Simulates a hypothetical distiller that slipped an 'overview' page through.
     * 'overview' IS a real skeleton page, so the "page missing" guard would NOT save us.
     * The write-point TARGETS check is the only thing that stops the fact being written.
     * Without that guard this test FAILS — upsertCorrectionFact would be called and a
     * Correction WikiFact would be persisted for the overview page.
     */
    public function test_refuses_to_write_a_fact_targeting_the_overview_page(): void
    {
        $this->enableWiki();
        Bus::fake([ComposeClientOverview::class]);

        $operator = User::factory()->create();
        [$client, $ticket] = $this->makeClientAndTicket();
        $conv = $this->seedCorrection($ticket, $operator, 'some client background info');

        // 'overview' is not in WikiFactExtractor::TARGETS — this is the invariant we guard.
        $this->assertArrayNotHasKey('overview', WikiFactExtractor::TARGETS, 'Precondition: overview must remain absent from TARGETS.');

        // A distiller that — hypothetically — slipped 'overview' through as a knowledge target.
        $candidate = new LessonCandidate('knowledge', 'overview', 'summary', 'acme:background', 'Acme background info.', 0.9);
        $this->mock(LessonDistiller::class)
            ->shouldReceive('distill')
            ->andReturn($candidate);

        app(LessonCapture::class)->capture($ticket, $conv);

        // The write-point backstop must have blocked this — zero Correction facts written.
        $this->assertSame(
            0,
            WikiFact::where('source_type', WikiFactSource::Correction->value)->count(),
            'LessonCapture must NEVER write a fact targeting the Overview page.'
        );

        // No overview recompose should be triggered either.
        Bus::assertNotDispatched(ComposeClientOverview::class);
    }

    // ── 6. fail-soft: internal error must never surface ──────────────────────

    public function test_capture_is_fail_soft_on_internal_error(): void
    {
        $this->enableWiki();

        $operator = User::factory()->create();
        [, $ticket] = $this->makeClientAndTicket();
        $conv = $this->seedCorrection($ticket, $operator, 'some feedback');

        $this->mock(LessonDistiller::class)
            ->shouldReceive('distill')
            ->andThrow(new \RuntimeException('boom — distiller exploded'));

        // Must NOT propagate the exception — the agent has already acted.
        app(LessonCapture::class)->capture($ticket, $conv);

        $this->assertSame(0, WikiFact::where('source_type', WikiFactSource::Correction->value)->count());
    }
}
