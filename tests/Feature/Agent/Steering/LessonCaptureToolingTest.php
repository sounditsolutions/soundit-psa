<?php

namespace Tests\Feature\Agent\Steering;

use App\Enums\ToolingGapClassification;
use App\Enums\ToolingGapSource;
use App\Enums\ToolingGapStatus;
use App\Enums\WikiFactSource;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\ToolingGap;
use App\Models\User;
use App\Models\WikiFact;
use App\Services\Agent\Steering\CorrectionRecorder;
use App\Services\Agent\Steering\LessonCandidate;
use App\Services\Agent\Steering\LessonCapture;
use App\Services\Agent\Steering\LessonDistiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LessonCapture — tooling-gap branch tests (Inc 3: RETRIEVE loop).
 *
 * The tooling branch now writes a durable ToolingGap row instead of the
 * Inc 2 seam log. Critically, this happens independently of the wiki gate —
 * a retrieval gap must be recorded even when the wiki is disabled.
 *
 * Tests:
 *  1. tooling correction → ToolingGap row (source=Correction, class=ToolUnused,
 *     abstract capability_gap, private evidence containing ticket id, status=Open).
 *     Wiki ENABLED. No WikiFact written.
 *  2. knowledge correction → no ToolingGap (goes to wiki fact path, Inc 2).
 *  3. none → neither ToolingGap nor WikiFact.
 *  4. DECOUPLING (load-bearing): tooling + wiki DISABLED → ToolingGap STILL recorded.
 *  5. fail-soft preserved: distiller throws → capture() does not throw, no ToolingGap.
 */
class LessonCaptureToolingTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ──────────────────────────────────────────────────────────────

    private function enableWiki(): void
    {
        Setting::setValue('wiki_enabled', '1');
    }

    private function disableWiki(): void
    {
        Setting::setValue('wiki_enabled', '0');
    }

    private function makeClientAndTicket(): array
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->for($client)->create();

        return [$client, $ticket];
    }

    private function toolingCandidate(): LessonCandidate
    {
        return new LessonCandidate('tooling', statement: 'the agent should query recent ticket history before responding');
    }

    private function knowledgeCandidate(): LessonCandidate
    {
        return new LessonCandidate('knowledge', 'known-issues', 'active', 'acme:no-auto-close', 'Acme is on a no-auto-close contract.', 0.9);
    }

    // ── 1. tooling correction → ToolingGap row written ───────────────────────

    public function test_tooling_correction_writes_tooling_gap_row(): void
    {
        $this->enableWiki();

        $operator = User::factory()->create();
        [$client, $ticket] = $this->makeClientAndTicket();

        $correctionText = 'you should have searched the ticket history first';
        $conv = app(CorrectionRecorder::class)->record($ticket, $operator, $correctionText);

        $candidate = $this->toolingCandidate();
        $this->mock(LessonDistiller::class)
            ->shouldReceive('distill')
            ->andReturn($candidate);

        app(LessonCapture::class)->capture($ticket, $conv);

        // Exactly one ToolingGap row must be written.
        $this->assertSame(1, ToolingGap::count());

        $gap = ToolingGap::first();
        $this->assertSame(ToolingGapSource::Correction, $gap->source);
        $this->assertSame(ToolingGapClassification::ToolUnused, $gap->classification);
        $this->assertSame(ToolingGapStatus::Open, $gap->status);

        // capability_gap is the abstract, forwardable distiller statement.
        $this->assertSame($candidate->statement, $gap->capability_gap);

        // evidence is the instance-private record: contains the ticket id.
        $this->assertStringContainsString((string) $ticket->id, $gap->evidence);

        // The two fields must be stored separately — evidence ≠ capability_gap.
        $this->assertNotSame($gap->capability_gap, $gap->evidence);

        // No WikiFact must be written.
        $this->assertSame(0, WikiFact::where('source_type', WikiFactSource::Correction->value)->count());
    }

    // ── 2. knowledge correction → no ToolingGap ──────────────────────────────

    public function test_knowledge_correction_writes_no_tooling_gap(): void
    {
        $this->enableWiki();

        $operator = User::factory()->create();
        [, $ticket] = $this->makeClientAndTicket();
        $conv = app(CorrectionRecorder::class)->record($ticket, $operator, 'client is on a no-auto-close contract');

        $this->mock(LessonDistiller::class)
            ->shouldReceive('distill')
            ->andReturn($this->knowledgeCandidate());

        app(LessonCapture::class)->capture($ticket, $conv);

        $this->assertSame(0, ToolingGap::count());
    }

    // ── 3. none candidate → neither ToolingGap nor WikiFact ──────────────────

    public function test_none_candidate_writes_nothing(): void
    {
        $this->enableWiki();

        $operator = User::factory()->create();
        [, $ticket] = $this->makeClientAndTicket();
        $conv = app(CorrectionRecorder::class)->record($ticket, $operator, 'just routine feedback');

        $this->mock(LessonDistiller::class)
            ->shouldReceive('distill')
            ->andReturn(LessonCandidate::none());

        app(LessonCapture::class)->capture($ticket, $conv);

        $this->assertSame(0, ToolingGap::count());
        $this->assertSame(0, WikiFact::where('source_type', WikiFactSource::Correction->value)->count());
    }

    // ── 4. DECOUPLING (load-bearing): tooling + wiki DISABLED → gap still recorded

    /**
     * This is the key decoupling test. In Inc 2 the whole method returned early
     * when the wiki was off — meaning tooling corrections were silently dropped.
     * After the restructure the wiki gate guards ONLY the knowledge branch.
     * A tooling gap (agent-retrieval feedback) must be recorded regardless of
     * whether the wiki is enabled. This test would FAIL against the old code.
     */
    public function test_tooling_gap_recorded_even_when_wiki_is_disabled(): void
    {
        $this->disableWiki();

        $operator = User::factory()->create();
        [$client, $ticket] = $this->makeClientAndTicket();
        $conv = app(CorrectionRecorder::class)->record($ticket, $operator, 'you missed the backup status tool');

        $candidate = $this->toolingCandidate();
        $this->mock(LessonDistiller::class)
            ->shouldReceive('distill')
            ->andReturn($candidate);

        app(LessonCapture::class)->capture($ticket, $conv);

        // The ToolingGap must exist even though the wiki is off.
        $gap = ToolingGap::first();
        $this->assertNotNull($gap, 'ToolingGap must be recorded even when the wiki is disabled.');
        $this->assertSame(ToolingGapSource::Correction, $gap->source);
        $this->assertSame(ToolingGapClassification::ToolUnused, $gap->classification);
        $this->assertSame($candidate->statement, $gap->capability_gap);
    }

    // ── 5. fail-soft preserved: distiller throws → no exception, no gap ──────

    public function test_capture_is_fail_soft_on_distiller_exception(): void
    {
        $this->enableWiki();

        $operator = User::factory()->create();
        [, $ticket] = $this->makeClientAndTicket();
        $conv = app(CorrectionRecorder::class)->record($ticket, $operator, 'some correction');

        $this->mock(LessonDistiller::class)
            ->shouldReceive('distill')
            ->andThrow(new \RuntimeException('distiller exploded'));

        // Must not propagate the exception.
        app(LessonCapture::class)->capture($ticket, $conv);

        $this->assertSame(0, ToolingGap::count());
    }
}
